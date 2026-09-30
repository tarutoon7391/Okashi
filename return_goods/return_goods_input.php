<?php
// SC-50 返品伝票入力（F-50）担当C
// 返品は独立テーブルを持たず、納品伝票のマイナス伝票（t_delivery.is_return=1）として作る（質問No.4）
// 一覧は選んだ卸業者の確定済み納品明細（納品日・伝票No ごと。06-3 プロンプト6）
// 明細は返品する商品の元の発注明細（order_no / order_line_no）を指し、数量はマイナス、memo に返品理由
//   ※ t_delivery_detail には元の納品明細を指す列が無いので、返品可能数は発注明細単位で数える
//     （同じ発注明細の納品が複数あるときは、それらの行の返品数の合計が発注明細の返品可能数以下）
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
$qtyInput  = [];
$memoInput = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. 入力値の取得・バリデーション（形式のチェック）
    //    name はカラム名に合わせる：返品日 = delivery_date、返品数 = delivery_qty（プラスで入力 → マイナスで記録）、返品理由 = memo
    $supplierCode = postStr('supplier_code');
    $returnDate   = postStr('delivery_date');
    $qtyInput     = postArray('delivery_qty');
    $memoInput    = postArray('memo');
    $supplierCodes = array_column(getSupplierOptions(), 'supplier_code');
    if ($supplierCode === '' || !in_array($supplierCode, $supplierCodes, true)) {
        $errors[] = '卸業者を選択してください';
    }
    if (!isValidDate($returnDate)) {
        $errors[] = '返品日を正しく入力してください';
    }
    $inputs = [];   // 'delivery_no-line_no' => ['qty' =>, 'memo' =>]
    foreach ($qtyInput as $key => $qtyRaw) {
        if (!is_string($qtyRaw) || trim($qtyRaw) === '') {
            continue;
        }
        $key = (string)$key;
        if (!preg_match('/\A\d+-\d+\z/', $key)) {
            $errors[] = '明細の指定が正しくありません';
            continue;
        }
        $qty  = toIntOrNull($qtyRaw);
        $memo = is_string($memoInput[$key] ?? null) ? trim($memoInput[$key]) : '';
        if ($qty === null || $qty <= 0) {
            $errors[] = "納品No.{$key}：返品数は1以上の整数で入力してください";
            continue;
        }
        if ($memo === '' || mb_strlen($memo) > 200) {
            $errors[] = "納品No.{$key}：返品理由を200文字以内で入力してください";
            continue;
        }
        $inputs[$key] = ['qty' => $qty, 'memo' => $memo];
    }
    if (!$errors && !$inputs) {
        $errors[] = '返品数を1行以上入力してください';
    }

    // 2. 返品可能数のチェックとDB更新
    if (!$errors) {
        $by = currentOperator()['operator_code'];
        try {
            // 納品明細 → 発注明細の対応（確定済みの納品明細は変わらないので、ロック前に引いてよい）
            $deliveryNos = array_values(array_unique(array_map(fn($k) => (int)explode('-', $k)[0], array_keys($inputs))));
            $in = implode(',', array_fill(0, count($deliveryNos), '?'));
            $st = $pdo->prepare("SELECT delivery_no, line_no, order_no, order_line_no FROM t_delivery_detail WHERE delivery_no IN ($in)");
            $st->execute($deliveryNos);
            $orderKeys = [];
            foreach ($st->fetchAll() as $d) {
                if (isset($inputs[$d['delivery_no'] . '-' . $d['line_no']])) {
                    $orderKeys[] = $d['order_no'] . '-' . $d['order_line_no'];
                }
            }

            $pdo->beginTransaction();
            // 同じ発注明細への返品・訂正の同時登録を防ぐため、発注明細を FOR UPDATE でロックしてから数え直す
            getOrderDetailsForUpdate($orderKeys);
            $returnable = [];
            foreach (getReturnableDeliveryDetails($supplierCode) as $r) {
                $returnable[$r['delivery_no'] . '-' . $r['line_no']] = $r;
            }
            $lines   = [];
            $perLine = [];   // 発注明細ごとの返品数の合計
            foreach ($inputs as $key => $inp) {
                $r = $returnable[$key] ?? null;
                if ($r === null) {
                    $errors[] = "納品No.{$key}：返品できる納品明細ではありません（返品済み、または他の人が先に登録した可能性があります）";
                    continue;
                }
                if ($returnDate < $r['delivery_date']) {
                    $errors[] = "納品No.{$key}：返品日が納品日（" . formatDate($r['delivery_date']) . '）より前です';
                    continue;
                }
                if ($inp['qty'] > (int)$r['row_max_qty']) {
                    $errors[] = "納品No.{$key}：返品数が返品できる数（{$r['row_max_qty']}）を超えています";
                    continue;
                }
                $orderKey = $r['order_no'] . '-' . $r['order_line_no'];
                $perLine[$orderKey] = ($perLine[$orderKey] ?? 0) + $inp['qty'];
                if ($perLine[$orderKey] > (int)$r['line_returnable_qty']) {
                    $errors[] = "発注No.{$orderKey}：返品数の合計が返品できる数（{$r['line_returnable_qty']}）を超えています";
                    continue;
                }
                $lines[] = ['r' => $r, 'qty' => -$inp['qty'], 'memo' => $inp['memo']];
            }

            if ($errors) {
                $pdo->rollBack();
            } else {
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
                        ':memo'       => $l['memo'],
                        ':by'         => $by,
                        ':by2'        => $by,
                    ]);
                }
                $pdo->commit();
                setFlash('success', "返品伝票（納品伝票No.{$deliveryNo}）を登録しました。納品確定で確定すると在庫が減ります");
                redirect('/return_goods/return_goods_input.php?supplier_code=' . rawurlencode($supplierCode));
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[return_goods_input] ' . $e->getMessage());
            $errors[] = '登録に失敗しました。もう一度やり直してください';
        }
    }
    setFlash('error', implode("\n", $errors));
}

// GET時：表示用データ
$suppliers = getSupplierOptions();
$list = $supplierCode !== '' ? getReturnableDeliveryDetails($supplierCode) : [];

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
    <input type="date" id="returnDate" name="delivery_date" value="<?= h($returnDate) ?>" required>
  </div>
  <p class="note">返品する行だけ返品数（プラスで入力）と理由を入れてください。伝票にはマイナス数量で記録されます。<br>
    「発注明細の返品可能数」は同じ発注明細の納品すべてで共通です（返品済み・訂正済みの分を差し引いた数）。</p>
  <table class="data-table">
    <thead>
      <tr><th>納品日</th><th>伝票No</th><th>発注No</th><th>発注日</th><th>商品</th><th>入数</th><th>納品数</th><th>発注明細の返品済</th><th>発注明細の返品可能数</th><th>返品数</th><th>返品理由</th></tr>
    </thead>
    <tbody>
      <?php foreach ($list as $r): $key = $r['delivery_no'] . '-' . $r['line_no']; ?>
      <tr>
        <td><?= h(formatDate($r['delivery_date'])) ?></td>
        <td><?= h($key) ?></td>
        <td><?= h($r['order_no'] . '-' . $r['order_line_no']) ?></td>
        <td><?= h(formatDate($r['order_date'])) ?></td>
        <td><?= h($r['product_code'] . ' ' . $r['product_name'] . ' ' . $r['spec']) ?><?= storageBadge($r['storage_type']) ?></td>
        <td class="num"><?= h($r['pack_qty']) ?></td>
        <td class="num"><?= h($r['delivery_qty']) ?></td>
        <td class="num <?= minusClass($r['returned_qty']) ?>"><?= (int)$r['returned_qty'] !== 0 ? h($r['returned_qty']) : '' ?></td>
        <td class="num"><strong><?= h($r['line_returnable_qty']) ?></strong></td>
        <td><input type="number" name="delivery_qty[<?= h($key) ?>]" value="<?= h($qtyInput[$key] ?? '') ?>" min="1" max="<?= h($r['row_max_qty']) ?>" step="1"></td>
        <td><input type="text" name="memo[<?= h($key) ?>]" value="<?= h($memoInput[$key] ?? '') ?>" maxlength="200" placeholder="例：破損・賞味期限切れ"></td>
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
