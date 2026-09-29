<?php
// SC-50 返品伝票入力（F-50）担当C
// 返品は独立テーブルを持たず、納品伝票のマイナス伝票（t_delivery.is_return=1）として作る（質問No.4）
// 明細は返品する商品の元の発注明細を指し、数量はマイナス、memo に返品理由
// 確定は SC-21 納品確定（confirmDelivery）で行い、そのとき在庫が減る
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/query.php';
requireLogin();
$pdo = getDb();
$errors = [];

$supplierCode = is_string($_GET['supplier_code'] ?? null) ? $_GET['supplier_code'] : '';
$returnDate = date('Y-m-d');
$qtyInput = [];
$reasonInput = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. 入力値の取得・バリデーション
    $supplierCode = postStr('supplier_code');
    $returnDate   = postStr('return_date');
    $qtyInput     = postArray('return_qty');
    $reasonInput  = postArray('reason');
    if (!isValidDate($returnDate)) {
        $errors[] = '返品日を正しく入力してください';
    }
    $returnable = [];
    foreach (getReturnableList($supplierCode) as $r) {
        $returnable[$r['order_no'] . '-' . $r['order_line_no']] = $r;
    }
    $lines = [];
    foreach ($qtyInput as $key => $qtyRaw) {
        if (!is_string($qtyRaw) || trim($qtyRaw) === '') {
            continue;
        }
        $r = $returnable[$key] ?? null;
        if ($r === null) {
            $errors[] = "発注No.{$key}：返品できる納品明細ではありません";
            continue;
        }
        $qty    = toIntOrNull($qtyRaw);
        $reason = is_string($reasonInput[$key] ?? null) ? trim($reasonInput[$key]) : '';
        if ($qty === null || $qty <= 0) {
            $errors[] = "発注No.{$key}：返品数は1以上の整数で入力してください";
            continue;
        }
        if ($qty > $r['returnable_qty']) {
            $errors[] = "発注No.{$key}：返品数が返品できる数（{$r['returnable_qty']}）を超えています";
            continue;
        }
        if ($reason === '' || mb_strlen($reason) > 200) {
            $errors[] = "発注No.{$key}：返品理由を200文字以内で入力してください";
            continue;
        }
        $lines[] = ['r' => $r, 'qty' => -$qty, 'reason' => $reason];
    }
    if (!$errors && !$lines) {
        $errors[] = '返品数を1行以上入力してください';
    }

    // 2. DB更新
    if (!$errors) {
        $by = currentOperator()['operator_code'];
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('INSERT INTO t_delivery (delivery_date, supplier_code, is_return, created_by, updated_by)
                                 VALUES (:date, :supplier, 1, :by, :by2)');
            $st->execute([':date' => $returnDate, ':supplier' => $supplierCode, ':by' => $by, ':by2' => $by]);
            $deliveryNo = (int)$pdo->lastInsertId();
            $st = $pdo->prepare(
                'INSERT INTO t_delivery_detail
                     (delivery_no, line_no, order_no, order_line_no, product_code, delivery_qty, contract_price, amount, memo, created_by, updated_by)
                 VALUES (:no, :line, :order_no, :order_line, :code, :qty, :price, :amount, :memo, :by, :by2)'
            );
            foreach ($lines as $i => $l) {
                $st->execute([
                    ':no'         => $deliveryNo,
                    ':line'       => $i + 1,
                    ':order_no'   => $l['r']['order_no'],
                    ':order_line' => $l['r']['order_line_no'],
                    ':code'       => $l['r']['product_code'],
                    ':qty'        => $l['qty'],
                    ':price'      => $l['r']['contract_price'],
                    ':amount'     => (int)$l['r']['contract_price'] * $l['qty'],
                    ':memo'       => $l['reason'],
                    ':by'         => $by,
                    ':by2'        => $by,
                ]);
            }
            $pdo->commit();
            setFlash('success', "返品伝票（納品伝票No.{$deliveryNo}）を登録しました。納品確定で確定すると在庫が減ります");
            redirect('/return_goods/return_goods_input.php?supplier_code=' . rawurlencode($supplierCode));
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = '登録に失敗しました：' . $e->getMessage();
        }
    }
    setFlash('error', implode("\n", $errors));
}

// GET時：表示用データ
$suppliers = getSupplierOptions();
$list = $supplierCode !== '' ? getReturnableList($supplierCode) : [];

$pageTitle = '返品伝票入力';
require_once __DIR__ . '/../common/header.php';
?>
<form method="get" class="filter-form">
  <label>卸業者
    <select name="supplier_code" onchange="this.form.submit()">
      <option value="">選択してください</option>
      <?php foreach ($suppliers as $s): ?>
        <option value="<?= h($s['supplier_code']) ?>" <?= $supplierCode === $s['supplier_code'] ? 'selected' : '' ?>><?= h($s['supplier_code'] . ' ' . $s['supplier_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <noscript><button type="submit" class="btn">表示</button></noscript>
</form>

<?php if ($supplierCode !== ''): ?>
<?php if (!$list): ?>
  <p>この卸業者の確定済み納品で、返品できるものはありません。</p>
<?php else: ?>
<form method="post" data-confirm="入力した内容で返品伝票（未確定）を登録します。よろしいですか？">
  <input type="hidden" name="supplier_code" value="<?= h($supplierCode) ?>">
  <div class="form-grid">
    <label for="returnDate">返品日</label>
    <input type="date" id="returnDate" name="return_date" value="<?= h($returnDate) ?>" required>
  </div>
  <p class="note">返品する行だけ返品数（プラスで入力）と理由を入れてください。伝票にはマイナス数量で記録されます。</p>
  <table class="data-table">
    <thead>
      <tr><th>発注No</th><th>発注日</th><th>最終納品日</th><th>商品</th><th>入数</th><th>納品済</th><th>返品済</th><th>返品可能数</th><th>返品数</th><th>返品理由</th></tr>
    </thead>
    <tbody>
      <?php foreach ($list as $r): $key = $r['order_no'] . '-' . $r['order_line_no']; ?>
      <tr>
        <td><?= h($key) ?></td>
        <td><?= h(formatDate($r['order_date'])) ?></td>
        <td><?= h(formatDate($r['last_delivery_date'])) ?></td>
        <td><?= h($r['product_code'] . ' ' . $r['product_name'] . ' ' . $r['spec']) ?><?= storageBadge($r['storage_type']) ?></td>
        <td class="num"><?= h($r['pack_qty']) ?></td>
        <td class="num"><?= h($r['delivered_qty']) ?></td>
        <td class="num <?= minusClass($r['returned_qty']) ?>"><?= (int)$r['returned_qty'] !== 0 ? h($r['returned_qty']) : '' ?></td>
        <td class="num"><strong><?= h($r['returnable_qty']) ?></strong></td>
        <td><input type="number" name="return_qty[<?= h($key) ?>]" value="<?= h($qtyInput[$key] ?? '') ?>" min="1" max="<?= h($r['returnable_qty']) ?>" step="1"></td>
        <td><input type="text" name="reason[<?= h($key) ?>]" value="<?= h($reasonInput[$key] ?? '') ?>" maxlength="200" placeholder="例：破損・賞味期限切れ"></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="btn-area">
    <button type="submit" class="btn btn-primary">登録</button>
    <a href="/menu.php" class="btn">メニューへ戻る</a>
  </div>
</form>
<?php endif; ?>
<?php endif; ?>
<p><a href="/delivery/delivery_confirm.php">納品確定へ（返品伝票もここで確定）</a></p>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
