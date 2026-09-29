<?php
// SC-66 売上明細表（F-66）担当D
// 指定期間内の売上を一覧表示（訂正のマイナス行も含める）。卸業者の絞り込みは出さない
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/report.php';
requireLogin();
$pdo = getDb();

$filter = getReportFilter();
$params = [];
$where  = buildReportWhere($filter, [
    'date' => 't.sales_date', 'product' => 't.product_code', 'status' => 't.is_confirmed',
], $params);
$st = $pdo->prepare(
    "SELECT t.sales_no, t.sales_date, t.product_code, p.product_name, p.spec, p.storage_type, p.list_price,
            t.sales_qty, t.amount, t.is_confirmed
       FROM t_sales t JOIN m_product p ON p.product_code = t.product_code
      WHERE {$where}
      ORDER BY t.sales_date, p.product_kana, t.sales_no"
);
$st->execute($params);
$rows = $st->fetchAll();

$pageTitle = '売上明細表';
require_once __DIR__ . '/../common/header.php';
?>
<?php renderReportFilter($filter, ['supplier' => false]); ?>
<table class="data-table">
  <thead>
    <tr><th>売上日</th><th>売上No</th><th>商品コード</th><th>商品名</th><th>定価</th><th>売上数</th><th>売上金額</th><th>状態</th></tr>
  </thead>
  <tbody>
    <?php if (!$rows): ?><tr><td colspan="8">該当するデータがありません</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
    <tr class="<?= minusClass($r['sales_qty']) ?>">
      <td><?= h(formatDate($r['sales_date'])) ?></td>
      <td class="num"><?= h($r['sales_no']) ?></td>
      <td><?= h($r['product_code']) ?></td>
      <td><?= h($r['product_name'] . ' ' . $r['spec']) ?><?= storageBadge($r['storage_type']) ?></td>
      <td class="num"><?= h(formatYen($r['list_price'])) ?></td>
      <td class="num"><?= h($r['sales_qty']) ?></td>
      <td class="num"><?= h(formatYen($r['amount'])) ?></td>
      <?= statusCell($r['is_confirmed']) ?>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><td colspan="5" class="num">合計</td>
      <td class="num"><?= h(array_sum(array_column($rows, 'sales_qty'))) ?></td>
      <td class="num"><?= h(formatYen(array_sum(array_column($rows, 'amount')))) ?></td><td></td></tr>
  </tfoot>
</table>
<div class="btn-area"><a href="/menu.php" class="btn">メニューへ戻る</a></div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
