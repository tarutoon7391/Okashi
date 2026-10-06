<?php
// SC-67 売上集計（F-67）担当D
// 指定期間内に売上げた商品の合計数（GROUP BY product_code。マイナス行も含めて合算）。金額＝定価×数量
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
    "SELECT t.product_code, p.product_name, p.spec, p.storage_type, p.list_price,
            SUM(t.sales_qty) AS qty, SUM(t.amount) AS amount, COUNT(DISTINCT t.sales_date) AS day_cnt
       FROM t_sales t JOIN m_product p ON p.product_code = t.product_code
      WHERE {$where}
      GROUP BY t.product_code, p.product_name, p.spec, p.storage_type, p.list_price, p.product_kana
      ORDER BY p.product_kana, t.product_code"
);
$st->execute($params);
$rows = $st->fetchAll();

$pageTitle = '売上集計';
require_once __DIR__ . '/../common/header.php';
?>
<?php renderReportFilter($filter, ['supplier' => false]); ?>
<div class="card tbl-scroll">
<table class="data-table">
  <thead>
    <tr><th>商品コード</th><th>商品名</th><th>規格</th><th>定価</th><th>売上日数</th><th>売上数合計</th><th>売上金額合計</th></tr>
  </thead>
  <tbody>
    <?php if (!$rows): ?><tr><td colspan="7">該当するデータがありません</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
    <tr class="<?= minusClass($r['qty']) ?>">
      <td><?= h($r['product_code']) ?></td>
      <td><?= h($r['product_name']) ?><?= storageBadge($r['storage_type']) ?></td>
      <td><?= h($r['spec']) ?></td>
      <td class="num"><?= h(formatYen($r['list_price'])) ?></td>
      <td class="num"><?= h($r['day_cnt']) ?></td>
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
</div>
<div class="action-bar"><div class="bar-in">
  <span class="bar-spacer"></span>
  <a href="/menu.php" class="btn">メニューへ戻る</a>
</div></div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
