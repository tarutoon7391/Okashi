<?php
// SC-65 返品集計（F-65）担当E
// 返品伝票（is_return=1）をもとに、指定期間内に返品した商品を卸業者別に集計する（符号は反転して表示）
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
    "SELECT h.supplier_code, s.supplier_name, d.product_code, p.product_name, p.spec, p.storage_type,
            -SUM(d.delivery_qty) AS qty, -SUM(d.amount) AS amount, COUNT(*) AS line_cnt
       FROM t_delivery_detail d
       JOIN t_delivery h ON h.delivery_no = d.delivery_no
       JOIN m_supplier s ON s.supplier_code = h.supplier_code
       JOIN m_product p  ON p.product_code = d.product_code
      WHERE h.is_return = 1 AND {$where}
      GROUP BY h.supplier_code, s.supplier_name, d.product_code, p.product_name, p.spec, p.storage_type, p.product_kana
      ORDER BY h.supplier_code, p.product_kana, d.product_code"
);
$st->execute($params);
$groups = [];
foreach ($st->fetchAll() as $r) {
    $groups[$r['supplier_code']]['name'] = $r['supplier_name'];
    $groups[$r['supplier_code']]['rows'][] = $r;
}
$grandQty = 0;
$grandAmount = 0;

$pageTitle = '返品集計';
require_once __DIR__ . '/../common/header.php';
?>
<?php renderReportFilter($filter); ?>
<table class="data-table">
  <thead>
    <tr><th>商品コード</th><th>商品名</th><th>規格</th><th>明細数</th><th>返品数合計</th><th>返品金額合計</th></tr>
  </thead>
  <?php if (!$groups): ?>
    <tbody><tr><td colspan="6">該当するデータがありません</td></tr></tbody>
  <?php endif; ?>
  <?php foreach ($groups as $code => $g):
      $subQty = array_sum(array_column($g['rows'], 'qty'));
      $subAmount = array_sum(array_column($g['rows'], 'amount'));
      $grandQty += $subQty;
      $grandAmount += $subAmount; ?>
  <tbody>
    <tr class="group-row"><td colspan="6"><?= h($code . ' ' . $g['name']) ?></td></tr>
    <?php foreach ($g['rows'] as $r): ?>
    <tr class="row-return">
      <td><?= h($r['product_code']) ?></td>
      <td><?= h($r['product_name']) ?><?= storageBadge($r['storage_type']) ?></td>
      <td><?= h($r['spec']) ?></td>
      <td class="num"><?= h($r['line_cnt']) ?></td>
      <td class="num"><?= h($r['qty']) ?></td>
      <td class="num"><?= h(formatYen($r['amount'])) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="subtotal-row"><td colspan="4" class="num">小計</td><td class="num"><?= h($subQty) ?></td><td class="num"><?= h(formatYen($subAmount)) ?></td></tr>
  </tbody>
  <?php endforeach; ?>
  <tfoot>
    <tr><td colspan="4" class="num">合計</td><td class="num"><?= h($grandQty) ?></td><td class="num"><?= h(formatYen($grandAmount)) ?></td></tr>
  </tfoot>
</table>
<div class="btn-area"><a href="/menu.php" class="btn">メニューへ戻る</a></div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
