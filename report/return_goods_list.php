<?php
// SC-64 返品明細表（F-64）担当E
// 指定期間内の返品（t_delivery.is_return=1）を卸業者別に一覧表示
// 伝票には数量・金額がマイナスで入っているので、表示時に符号を反転する（質問No.4）
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/report.php';
requireLogin();
$pdo = getDb();

$filter = getReportFilter();
$params = [];
$where  = buildReportWhere($filter, [
    'date' => 'h.delivery_date', 'supplier' => 'h.supplier_code', 'product' => 'd.product_code', 'status' => 'h.is_confirmed',
], $params);
$st = $pdo->prepare(
    "SELECT h.delivery_no, d.line_no, h.delivery_date, h.supplier_code, s.supplier_name, h.is_confirmed,
            d.order_no, d.order_line_no, d.product_code, p.product_name, p.spec, p.storage_type,
            -d.delivery_qty AS return_qty, d.contract_price, -d.amount AS return_amount, d.memo
       FROM t_delivery_detail d
       JOIN t_delivery h ON h.delivery_no = d.delivery_no
       JOIN m_supplier s ON s.supplier_code = h.supplier_code
       JOIN m_product p  ON p.product_code = d.product_code
      WHERE h.is_return = 1 AND {$where}
      ORDER BY h.supplier_code, h.delivery_date, h.delivery_no, d.line_no"
);
$st->execute($params);
$groups = [];
foreach ($st->fetchAll() as $r) {
    $groups[$r['supplier_code']]['name'] = $r['supplier_name'];
    $groups[$r['supplier_code']]['rows'][] = $r;
}
$grandQty = 0;
$grandAmount = 0;

$pageTitle = '返品明細表';
require_once __DIR__ . '/../common/header.php';
?>
<?php renderReportFilter($filter); ?>
<table class="data-table">
  <thead>
    <tr><th>返品日</th><th>返品伝票No</th><th>元の発注No</th><th>商品コード</th><th>商品名</th><th>返品数</th><th>契約単価</th><th>返品金額</th><th>返品理由</th><th>状態</th></tr>
  </thead>
  <?php if (!$groups): ?>
    <tbody><tr><td colspan="10">該当するデータがありません</td></tr></tbody>
  <?php endif; ?>
  <?php foreach ($groups as $code => $g):
      $subQty = array_sum(array_column($g['rows'], 'return_qty'));
      $subAmount = array_sum(array_column($g['rows'], 'return_amount'));
      $grandQty += $subQty;
      $grandAmount += $subAmount; ?>
  <tbody>
    <tr class="group-row"><td colspan="10"><?= h($code . ' ' . $g['name']) ?></td></tr>
    <?php foreach ($g['rows'] as $r): ?>
    <tr class="row-return">
      <td><?= h(formatDate($r['delivery_date'])) ?></td>
      <td><?= h($r['delivery_no'] . '-' . $r['line_no']) ?></td>
      <td><?= h($r['order_no'] . '-' . $r['order_line_no']) ?></td>
      <td><?= h($r['product_code']) ?></td>
      <td><?= h($r['product_name'] . ' ' . $r['spec']) ?><?= storageBadge($r['storage_type']) ?></td>
      <td class="num"><?= h($r['return_qty']) ?></td>
      <td class="num"><?= h(formatYen($r['contract_price'])) ?></td>
      <td class="num"><?= h(formatYen($r['return_amount'])) ?></td>
      <td><?= h($r['memo']) ?></td>
      <?= statusCell($r['is_confirmed']) ?>
    </tr>
    <?php endforeach; ?>
    <tr class="subtotal-row"><td colspan="5" class="num">小計</td><td class="num"><?= h($subQty) ?></td><td></td><td class="num"><?= h(formatYen($subAmount)) ?></td><td colspan="2"></td></tr>
  </tbody>
  <?php endforeach; ?>
  <tfoot>
    <tr><td colspan="5" class="num">合計</td><td class="num"><?= h($grandQty) ?></td><td></td><td class="num"><?= h(formatYen($grandAmount)) ?></td><td colspan="2"></td></tr>
  </tfoot>
</table>
<div class="btn-area"><a href="/menu.php" class="btn">メニューへ戻る</a></div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
