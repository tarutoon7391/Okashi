<?php
// SC-21 納品確定（F-21）担当C
// 未確定の納品伝票（返品伝票を含む。返品は .row-return で色分け）を confirmDelivery() で確定
// → 在庫が増える（返品・マイナス納品は減る）→ SC-22 未納品一覧表へ
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/slip.php';
requireLogin();
$pdo = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deliveryNos = array_values(array_unique(array_filter(array_map('intval', postArray('delivery_no')))));
    if (!$deliveryNos) {
        setFlash('error', '確定する伝票を選んでください');
        redirect('/delivery/delivery_confirm.php');
    }
    $confirmed = [];
    $messages  = [];
    foreach ($deliveryNos as $no) {
        try {
            confirmDelivery($no);
            $confirmed[] = $no;
        } catch (Throwable $e) {
            $messages[] = "伝票No.{$no}：" . $e->getMessage();
        }
    }
    if (!$confirmed) {
        setFlash('error', implode("\n", $messages));
        redirect('/delivery/delivery_confirm.php');
    }
    // 在庫がマイナスになった商品は警告（更新は許可：04 t_stock）
    $in = implode(',', array_fill(0, count($confirmed), '?'));
    $st = $pdo->prepare("SELECT DISTINCT product_code FROM t_delivery_detail WHERE delivery_no IN ($in)");
    $st->execute($confirmed);
    foreach (getMinusStockProducts($st->fetchAll(PDO::FETCH_COLUMN)) as $m) {
        $messages[] = "在庫がマイナスです：{$m['product_code']} {$m['product_name']}（{$m['stock_qty']}）";
    }
    $done = count($confirmed) . '件の納品伝票を確定し、在庫を更新しました（No.' . implode(', ', $confirmed) . '）';
    setFlash($messages ? 'warning' : 'success', $messages ? $done . "\n" . implode("\n", $messages) : $done);
    redirect('/delivery/undelivered_print.php');
}

// GET時：未確定の納品伝票一覧（明細つき）
$slips = $pdo->query(
    'SELECT d.delivery_no, d.delivery_date, d.supplier_code, s.supplier_name, d.is_return
       FROM t_delivery d JOIN m_supplier s ON s.supplier_code = d.supplier_code
      WHERE d.is_confirmed = 0
      ORDER BY d.delivery_date, d.delivery_no'
)->fetchAll();
$st = $pdo->prepare(
    'SELECT dd.line_no, dd.order_no, dd.order_line_no, dd.product_code, p.product_name, p.spec, p.storage_type,
            dd.delivery_qty, dd.amount, dd.memo
       FROM t_delivery_detail dd JOIN m_product p ON p.product_code = dd.product_code
      WHERE dd.delivery_no = :no ORDER BY dd.line_no'
);
foreach ($slips as &$slip) {
    $st->execute([':no' => $slip['delivery_no']]);
    $slip['details'] = $st->fetchAll();
    $slip['total']   = array_sum(array_column($slip['details'], 'amount'));
}
unset($slip);

$pageTitle = '納品確定';
require_once __DIR__ . '/../common/header.php';
?>
<?php if (!$slips): ?>
  <p>未確定の納品伝票はありません。</p>
<?php else: ?>
<form method="post" data-confirm="チェックした納品伝票を確定し、在庫を更新します。確定後は変更できません。よろしいですか？">
  <table class="data-table">
    <thead>
      <tr><th><input type="checkbox" class="js-check-all" title="すべて選択"></th><th>伝票No</th><th>区分</th><th>納品日</th><th>卸業者</th><th>明細</th><th>合計金額</th><th>状態</th></tr>
    </thead>
    <tbody>
      <?php foreach ($slips as $slip): ?>
      <tr class="<?= (int)$slip['is_return'] === 1 ? 'row-return' : '' ?>">
        <td><input type="checkbox" name="delivery_no[]" value="<?= h($slip['delivery_no']) ?>" class="js-check"></td>
        <td class="num"><?= h($slip['delivery_no']) ?></td>
        <td><?= (int)$slip['is_return'] === 1 ? '返品' : '納品' ?></td>
        <td><?= h(formatDate($slip['delivery_date'])) ?></td>
        <td><?= h($slip['supplier_code'] . ' ' . $slip['supplier_name']) ?></td>
        <td>
          <table class="inner-table">
            <?php foreach ($slip['details'] as $d): ?>
            <tr class="<?= minusClass($d['delivery_qty']) ?>">
              <td><small>発注<?= h($d['order_no'] . '-' . $d['order_line_no']) ?></small></td>
              <td><?= h($d['product_code'] . ' ' . $d['product_name'] . ' ' . $d['spec']) ?><?= storageBadge($d['storage_type']) ?></td>
              <td class="num"><?= h($d['delivery_qty']) ?></td>
              <td class="num"><?= h(formatYen($d['amount'])) ?></td>
              <td><?= h($d['memo']) ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        </td>
        <td class="num <?= minusClass($slip['total']) ?>"><?= h(formatYen($slip['total'])) ?></td>
        <?= statusCell(0) ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="btn-area">
    <button type="submit" class="btn btn-danger">確定</button>
    <a href="/menu.php" class="btn">メニューへ戻る</a>
  </div>
</form>
<?php endif; ?>
<p><a href="/delivery/delivery_input.php">納品入力へ</a> ／ <a href="/return_goods/return_goods_input.php">返品伝票へ</a> ／ <a href="/delivery/undelivered_print.php">未納品一覧表へ</a></p>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
