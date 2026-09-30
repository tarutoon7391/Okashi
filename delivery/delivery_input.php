<?php
// SC-20 納品入力（F-20）担当C
// ①卸業者を選ぶ → ②その業者宛の未納品一覧 → ③納品された行をチェックして数量入力 → 納品伝票（未確定）
// 未納品数は取消（マイナス発注）分を差し引き、返品伝票は含めずに計算（getUndeliveredList）
// 「納品済みの訂正（マイナス）も表示」をONにすると、納品が完了した（残数0の）明細も出し、マイナス数量で訂正できる
// 残数を超える納品は警告のみ（03-3）。「残数超過を承知で登録」にチェックしたときだけ登録する
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/query.php';
requireLogin();
$pdo = getDb();
$errors = [];

$supplierCode  = is_string($_GET['supplier_code'] ?? null) ? $_GET['supplier_code'] : '';
$withDelivered = ($_GET['with_delivered'] ?? '') === '1';
$deliveryDate  = date('Y-m-d');
$checked   = [];
$qtyInput  = [];
$memoInput = [];
$allowOver = false;
$overKeys  = [];   // 残数を超えている明細（警告を出して「承知で登録」を求める）

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. 入力値の取得・バリデーション（形式のチェック）
    $supplierCode  = postStr('supplier_code');
    $withDelivered = postStr('with_delivered') === '1';
    $deliveryDate  = postStr('delivery_date');
    $checked   = array_values(array_filter(postArray('sel'), 'is_string'));
    $qtyInput  = postArray('delivery_qty');
    $memoInput = postArray('memo');
    $allowOver = postStr('allow_over') === '1';
    $supplierCodes = array_column(getSupplierOptions(), 'supplier_code');
    if ($supplierCode === '' || !in_array($supplierCode, $supplierCodes, true)) {
        $errors[] = '卸業者を選択してください';
    }
    if (!isValidDate($deliveryDate)) {
        $errors[] = '納品日を正しく入力してください';
    }
    if (!$checked) {
        $errors[] = '納品された明細をチェックしてください';
    }
    $inputs = [];   // 'order_no-line_no' => ['qty' =>, 'memo' =>]
    foreach ($checked as $key) {
        if (!preg_match('/\A\d+-\d+\z/', $key)) {
            $errors[] = '明細の指定が正しくありません';
            continue;
        }
        $qty  = toIntOrNull($qtyInput[$key] ?? '');
        $memo = is_string($memoInput[$key] ?? null) ? trim($memoInput[$key]) : '';
        if ($qty === null || $qty === 0) {
            $errors[] = "発注No.{$key}：納品数量は0以外の整数で入力してください（マイナス可）";
            continue;
        }
        if (mb_strlen($memo) > 200) {
            $errors[] = "発注No.{$key}：メモは200文字以内で入力してください";
            continue;
        }
        $inputs[$key] = ['qty' => $qty, 'memo' => $memo === '' ? null : $memo];
    }

    // 2. 残数のチェックとDB更新（同じ発注明細への同時登録を防ぐため、発注明細を FOR UPDATE でロックしてから数え直す）
    if (!$errors) {
        $by = currentOperator()['operator_code'];
        try {
            $pdo->beginTransaction();
            getOrderDetailsForUpdate(array_keys($inputs));
            // 一覧は1回だけ取ってキーで引く。残数0の明細もマイナス訂正の対象なので含める
            $undelivered = indexUndeliveredList(getUndeliveredList(['supplier_code' => $supplierCode, 'include_delivered' => true]));
            $lines    = [];
            $warnings = [];
            foreach ($inputs as $key => $in) {
                $u = $undelivered[$key] ?? null;
                if ($u === null) {
                    $errors[] = "発注No.{$key}：納品できる明細ではありません（他の人が先に登録した可能性があります）";
                    continue;
                }
                if ($deliveryDate < $u['order_date']) {
                    $errors[] = "発注No.{$key}：納品日が発注日（" . formatDate($u['order_date']) . '）より前です';
                    continue;
                }
                $qty       = $in['qty'];
                $remain    = (int)$u['remain_qty'];
                $delivered = (int)$u['delivered_qty'];
                if ($qty > 0 && $qty > $remain) {
                    // 残数超過は警告のみ（03-3）。承知のチェックが無ければ登録しない
                    if (!$allowOver) {
                        $overKeys[] = $key;
                        $errors[] = "発注No.{$key}：納品数量（{$qty}）が残数（{$remain}）を超えています";
                        continue;
                    }
                    $warnings[] = "発注No.{$key}：残数（{$remain}）を超えて {$qty} を登録しました";
                }
                if ($qty < 0) {
                    // マイナス（訂正）は納品済み数まで。返品済みの分はもう手元に無いので、それも差し引いた数まで
                    if ($delivered + $qty < 0) {
                        $errors[] = "発注No.{$key}：マイナス納品が納品済み数（{$delivered}）を超えています";
                        continue;
                    }
                    if ($delivered + (int)$u['returned_qty'] + $qty < 0) {
                        $errors[] = "発注No.{$key}：マイナス納品が返品分を除いた納品済み数（" . ($delivered + (int)$u['returned_qty']) . '）を超えています';
                        continue;
                    }
                }
                $lines[] = ['u' => $u, 'qty' => $qty, 'memo' => $in['memo']];
            }

            if ($errors) {
                $pdo->rollBack();
            } else {
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
                // 3. 完了メッセージ → 同じ画面へ（残数超過があれば警告として出す）
                $done = "納品伝票No.{$deliveryNo} を登録しました。納品確定で在庫に反映してください";
                setFlash($warnings ? 'warning' : 'success', $warnings ? $done . "\n" . implode("\n", $warnings) : $done);
                redirect('/delivery/delivery_input.php?supplier_code=' . rawurlencode($supplierCode) . ($withDelivered ? '&with_delivered=1' : ''));
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[delivery_input] ' . $e->getMessage());
            $errors[] = '登録に失敗しました。もう一度やり直してください';
        }
    }
    if ($overKeys) {
        $errors[] = '残数を超えて納品する場合は、「残数超過を承知で登録」にチェックしてもう一度登録してください';
    }
    setFlash('error', implode("\n", $errors));
}

// GET時：表示用データ（訂正表示ONなら、納品が完了した明細も出す）
$suppliers = getSupplierOptions();
$list = $supplierCode !== '' ? getUndeliveredList(['supplier_code' => $supplierCode, 'include_delivered' => $withDelivered]) : [];

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
  <label><input type="checkbox" name="with_delivered" value="1" onchange="this.form.submit()" <?= $withDelivered ? 'checked' : '' ?>> 納品済みの訂正（マイナス）も表示</label>
  <noscript><button type="submit" class="btn">表示</button></noscript>
</form>

<?php if ($supplierCode !== ''): ?>
<h2>② 未納品一覧<?= $withDelivered ? '（納品済みの訂正を含む）' : '' ?> ／ ③ 納品された商品をチェック</h2>
<?php if ($withDelivered): ?>
  <p class="note">納品済みの訂正：数量をマイナスで入力すると、納品済み数を減らす訂正になります（納品済み数まで）。未確定の伝票の間違いは、納品確定画面で伝票ごと削除できます。</p>
<?php endif; ?>
<?php if (!$list): ?>
  <p>この卸業者宛の未納品はありません。</p>
<?php else: ?>
<form method="post" data-confirm="チェックした明細で納品伝票（未確定）を登録します。よろしいですか？">
  <input type="hidden" name="supplier_code" value="<?= h($supplierCode) ?>">
  <input type="hidden" name="with_delivered" value="<?= $withDelivered ? '1' : '' ?>">
  <div class="form-grid">
    <label for="deliveryDate">納品日</label>
    <input type="date" id="deliveryDate" name="delivery_date" value="<?= h($deliveryDate) ?>" required>
  </div>
  <table class="data-table delivery-input-table">
    <thead>
      <tr><th><input type="checkbox" class="js-check-all" title="すべて選択"></th><th>発注No</th><th>発注日</th><th>商品</th><th>入数</th><th>発注数</th><th>取消</th><th>納品済</th><th>残数</th><th>契約単価</th><th>納品数量</th><th>納品金額</th><th>メモ</th></tr>
    </thead>
    <tbody>
      <?php foreach ($list as $u):
          $key = $u['order_no'] . '-' . $u['line_no'];
          $isChecked = in_array($key, $checked, true);
          $isDone    = (int)$u['remain_qty'] <= 0;   // 納品完了（訂正のみ）の明細
          $qtyValue  = $qtyInput[$key] ?? ($isDone ? '' : $u['remain_qty']); ?>
      <tr class="js-delivery-row" data-price="<?= h($u['contract_price']) ?>">
        <td><input type="checkbox" name="sel[]" value="<?= h($key) ?>" class="js-check" <?= $isChecked ? 'checked' : '' ?>></td>
        <td><?= h($key) ?></td>
        <td><?= h(formatDate($u['order_date'])) ?></td>
        <td><?= h($u['product_code'] . ' ' . $u['product_name'] . ' ' . $u['spec']) ?><?= storageBadge($u['storage_type']) ?>
          <?php if ($u['memo']): ?><br><small>メモ：<?= h($u['memo']) ?></small><?php endif; ?></td>
        <td class="num"><?= h($u['pack_qty']) ?></td>
        <td class="num"><?= h($u['order_qty']) ?></td>
        <td class="num <?= minusClass($u['cancel_qty']) ?>"><?= (int)$u['cancel_qty'] !== 0 ? h($u['cancel_qty']) : '' ?></td>
        <td class="num"><?= h($u['delivered_qty']) ?>
          <?php if ((int)$u['returned_qty'] !== 0): ?><br><small class="qty-minus">返品<?= h($u['returned_qty']) ?></small><?php endif; ?></td>
        <td class="num <?= minusClass($u['remain_qty']) ?>"><strong><?= h($u['remain_qty']) ?></strong>
          <?php if ($isDone): ?><br><small class="note">納品完了（訂正のみ）</small><?php endif; ?></td>
        <td class="num"><?= h(formatYen($u['contract_price'])) ?></td>
        <td><input type="number" name="delivery_qty[<?= h($key) ?>]" class="js-qty" value="<?= h($qtyValue) ?>" step="1" <?= $isDone ? 'placeholder="例：-1"' : '' ?> <?= $isChecked ? '' : 'disabled' ?>></td>
        <td class="num js-amount"></td>
        <td><input type="text" name="memo[<?= h($key) ?>]" value="<?= h($memoInput[$key] ?? '') ?>" maxlength="200" <?= $isChecked ? '' : 'disabled' ?>></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="11" class="num">チェックした行の合計</td><td class="num" id="deliveryTotal"></td><td></td></tr>
    </tfoot>
  </table>
  <p class="note">納品金額は 契約単価×納品数量。チェックしていない行の金額は参考表示です（合計には入りません）。</p>
  <?php if ($overKeys || $allowOver): ?>
  <p class="flash-warning"><label><input type="checkbox" name="allow_over" value="1" <?= $allowOver ? 'checked' : '' ?>> 残数超過を承知で登録</label>（対象：<?= h(implode('、', $overKeys ?: ['なし'])) ?>）</p>
  <?php endif; ?>
  <div class="btn-area">
    <button type="submit" class="btn btn-primary">登録</button>
    <a href="/menu.php" class="btn">メニューへ戻る</a>
  </div>
</form>
<?php endif; ?>
<?php endif; ?>
<p><a href="/delivery/delivery_confirm.php">納品確定へ</a> ／ <a href="/delivery/undelivered_print.php">未納品一覧表へ</a></p>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
