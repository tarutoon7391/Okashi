<?php
// SC-20 納品入力（F-20）担当C
// ①卸業者を選ぶ → ②その業者宛の未納品一覧 → ③納品された行をチェックして数量入力 → 納品伝票（未確定）
// 未納品数は取消（マイナス発注）分を差し引き、返品伝票は含めずに計算（getUndeliveredList）
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/query.php';
requireLogin();
$pdo = getDb();
$errors = [];

$supplierCode = is_string($_GET['supplier_code'] ?? null) ? $_GET['supplier_code'] : '';
$deliveryDate = date('Y-m-d');
$checked = [];
$qtyInput = [];
$memoInput = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. 入力値の取得・バリデーション
    $supplierCode = postStr('supplier_code');
    $deliveryDate = postStr('delivery_date');
    $checked   = array_filter(postArray('check'), 'is_string');
    $qtyInput  = postArray('delivery_qty');
    $memoInput = postArray('memo');
    if (!isValidDate($deliveryDate)) {
        $errors[] = '納品日を正しく入力してください';
    }
    if (!$checked) {
        $errors[] = '納品された明細をチェックしてください';
    }
    $lines = [];
    foreach ($checked as $key) {
        [$orderNo, $lineNo] = array_map('intval', explode('-', $key) + [0, 0]);
        $u = findUndeliveredLine($orderNo, $lineNo, ['supplier_code' => $supplierCode]);
        if ($u === null) {
            $errors[] = "発注No.{$key}：未納品の明細ではありません（他の人が先に登録した可能性があります）";
            continue;
        }
        $qty  = toIntOrNull($qtyInput[$key] ?? '');
        $memo = is_string($memoInput[$key] ?? null) ? trim($memoInput[$key]) : '';
        if ($qty === null || $qty === 0) {
            $errors[] = "発注No.{$key}：納品数量は0以外の整数で入力してください（マイナス可）";
            continue;
        }
        if ($qty > (int)$u['remain_qty']) {
            $errors[] = "発注No.{$key}：納品数量が残数（{$u['remain_qty']}）を超えています";
            continue;
        }
        if ((int)$u['delivered_qty'] + $qty < 0) {
            $errors[] = "発注No.{$key}：マイナス納品が納品済み数（{$u['delivered_qty']}）を超えています";
            continue;
        }
        if (mb_strlen($memo) > 200) {
            $errors[] = "発注No.{$key}：メモは200文字以内で入力してください";
            continue;
        }
        $lines[] = ['u' => $u, 'qty' => $qty, 'memo' => $memo === '' ? null : $memo];
    }

    // 2. DB更新
    if (!$errors) {
        $by = currentOperator()['operator_code'];
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('INSERT INTO t_delivery (delivery_date, supplier_code, is_return, created_by, updated_by)
                                 VALUES (:date, :supplier, 0, :by, :by2)');
            $st->execute([':date' => $deliveryDate, ':supplier' => $supplierCode, ':by' => $by, ':by2' => $by]);
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
                    ':order_no'   => $l['u']['order_no'],
                    ':order_line' => $l['u']['line_no'],
                    ':code'       => $l['u']['product_code'],
                    ':qty'        => $l['qty'],
                    ':price'      => $l['u']['contract_price'],
                    ':amount'     => (int)$l['u']['contract_price'] * $l['qty'],
                    ':memo'       => $l['memo'],
                    ':by'         => $by,
                    ':by2'        => $by,
                ]);
            }
            $pdo->commit();
            // 3. 完了メッセージ → 同じ画面へ
            setFlash('success', "納品伝票No.{$deliveryNo} を登録しました。納品確定で在庫に反映してください");
            redirect('/delivery/delivery_input.php?supplier_code=' . rawurlencode($supplierCode));
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = '登録に失敗しました：' . $e->getMessage();
        }
    }
    setFlash('error', implode("\n", $errors));
}

// GET時：表示用データ
$suppliers = getSupplierOptions();
$list = $supplierCode !== '' ? getUndeliveredList(['supplier_code' => $supplierCode]) : [];

$pageTitle  = '納品入力';
$pageScript = 'delivery_input.js';
require_once __DIR__ . '/../common/header.php';
?>
<h2>① 卸業者を選ぶ</h2>
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
<h2>② 未納品一覧 ／ ③ 納品された商品をチェック</h2>
<?php if (!$list): ?>
  <p>この卸業者宛の未納品はありません。</p>
<?php else: ?>
<form method="post" data-confirm="チェックした明細で納品伝票（未確定）を登録します。よろしいですか？">
  <input type="hidden" name="supplier_code" value="<?= h($supplierCode) ?>">
  <div class="form-grid">
    <label for="deliveryDate">納品日</label>
    <input type="date" id="deliveryDate" name="delivery_date" value="<?= h($deliveryDate) ?>" required>
  </div>
  <table class="data-table delivery-input-table">
    <thead>
      <tr><th><input type="checkbox" class="js-check-all" title="すべて選択"></th><th>発注No</th><th>発注日</th><th>商品</th><th>入数</th><th>発注数</th><th>取消</th><th>納品済</th><th>残数</th><th>納品数量</th><th>納品金額</th><th>メモ</th></tr>
    </thead>
    <tbody>
      <?php foreach ($list as $u):
          $key = $u['order_no'] . '-' . $u['line_no'];
          $isChecked = in_array($key, $checked, true);
          $qtyValue = $qtyInput[$key] ?? $u['remain_qty']; ?>
      <tr class="js-delivery-row" data-price="<?= h($u['contract_price']) ?>">
        <td><input type="checkbox" name="check[]" value="<?= h($key) ?>" class="js-check" <?= $isChecked ? 'checked' : '' ?>></td>
        <td><?= h($key) ?></td>
        <td><?= h(formatDate($u['order_date'])) ?></td>
        <td><?= h($u['product_code'] . ' ' . $u['product_name'] . ' ' . $u['spec']) ?><?= storageBadge($u['storage_type']) ?>
          <?php if ($u['memo']): ?><br><small>メモ：<?= h($u['memo']) ?></small><?php endif; ?></td>
        <td class="num"><?= h($u['pack_qty']) ?></td>
        <td class="num"><?= h($u['order_qty']) ?></td>
        <td class="num <?= minusClass($u['cancel_qty']) ?>"><?= (int)$u['cancel_qty'] !== 0 ? h($u['cancel_qty']) : '' ?></td>
        <td class="num"><?= h($u['delivered_qty']) ?></td>
        <td class="num"><strong><?= h($u['remain_qty']) ?></strong></td>
        <td><input type="number" name="delivery_qty[<?= h($key) ?>]" class="js-qty" value="<?= h($qtyValue) ?>" step="1" max="<?= h($u['remain_qty']) ?>" <?= $isChecked ? '' : 'disabled' ?>></td>
        <td class="num js-amount"></td>
        <td><input type="text" name="memo[<?= h($key) ?>]" value="<?= h($memoInput[$key] ?? '') ?>" maxlength="200" <?= $isChecked ? '' : 'disabled' ?>></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="10" class="num">チェックした行の合計</td><td class="num" id="deliveryTotal"></td><td></td></tr>
    </tfoot>
  </table>
  <div class="btn-area">
    <button type="submit" class="btn btn-primary">登録</button>
    <a href="/menu.php" class="btn">メニューへ戻る</a>
  </div>
</form>
<?php endif; ?>
<?php endif; ?>
<p><a href="/delivery/delivery_confirm.php">納品確定へ</a> ／ <a href="/delivery/undelivered_print.php">未納品一覧表へ</a></p>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
