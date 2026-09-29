<?php
// SC-61 発注集計（F-61）担当E
// 指定期間内に発注した商品の合計数（マイナス伝票も含めて合算＝相殺後の数字）
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/report.php';
requireLogin();
$pdo = getDb();

$filter = getReportFilter();
$params = [];
$where  = buildReportWhere($filter, [
    'date' => 'o.order_date', 'supplier' => 'o.supplier_code', 'product' => 'd.product_code', 'status' => 'o.is_confirmed',
], $params);
$st = $pdo->prepare(
    "SELECT d.product_code, p.product_name, p.spec, p.storage_type, s.supplier_name,
            SUM(d.order_qty) AS qty, SUM(d.amount) AS amount, COUNT(*) AS line_cnt
       FROM t_order_detail d
       JOIN t_order o    ON o.order_no = d.order_no
       JOIN m_product p  ON p.product_code = d.product_code
       JOIN m_supplier s ON s.supplier_code = o.supplier_code
      WHERE {$where}
      GROUP BY d.product_code, p.product_name, p.spec, p.storage_type, s.supplier_name, p.product_kana
      ORDER BY p.product_kana, d.product_code"
);
$st->execute($params);
$rows = $st->fetchAll();

$pageTitle = '発注集計';
require_once __DIR__ . '/../common/header.php';
?>
<?php renderReportFilter($filter); ?>
<table class="data-table">
  <thead>
    <tr><th>商品コード</th><th>商品名</th><th>規格</th><th>卸業者</th><th>明細数</th><th>発注数合計</th><th>金額合計</th></tr>
  </thead>
  <tbody>
    <?php if (!$rows): ?><tr><td colspan="7">該当するデータがありません</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
    <tr class="<?= minusClass($r['qty']) ?>">
      <td><?= h($r['product_code']) ?></td>
      <td><?= h($r['product_name']) ?><?= storageBadge($r['storage_type']) ?></td>
      <td><?= h($r['spec']) ?></td>
      <td><?= h($r['supplier_name']) ?></td>
      <td class="num"><?= h($r['line_cnt']) ?></td>
      <td class="num"><?= h($r['qty']) ?></td>
      <td class="num"><?= h(formatYen($r['amount'])) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><td colspan="5" class="num">合計</td>
      <td class="num"><?= h(array_sum(array_column($rows, 'qty'))) ?></td>
      <td class="num"><?= h(formatYen(array_sum(array_column($rows, 'amount')))) ?></td></tr>
  </tfoot>
</table>
<div class="btn-area"><a href="/menu.php" class="btn">メニューへ戻る</a></div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
