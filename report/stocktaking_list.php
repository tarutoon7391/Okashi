<?php
// SC-68 棚卸調整一覧表（F-68）担当D
// 棚卸で調整した商品の 帳簿数・実数・差異・理由 を表示する（t_stocktaking には差異がある商品だけ入っている）
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/report.php';
requireLogin();
$pdo = getDb();

$filter = getReportFilter();
$params = [];
$where  = buildReportWhere($filter, ['date' => 't.stocktaking_date', 'product' => 't.product_code'], $params);
$st = $pdo->prepare(
    "SELECT t.stocktaking_no, t.stocktaking_date, t.product_code, p.product_name, p.spec, p.storage_type,
            t.book_qty, t.actual_qty, t.diff_qty, t.reason, t.created_by, o.operator_name
       FROM t_stocktaking t
       JOIN m_product p ON p.product_code = t.product_code
       LEFT JOIN m_operator o ON o.operator_code = t.created_by
      WHERE {$where}
      ORDER BY t.stocktaking_date, t.stocktaking_no"
);
$st->execute($params);
$rows = $st->fetchAll();

$pageTitle = '棚卸調整一覧表';
require_once __DIR__ . '/../common/header.php';
?>
<?php renderReportFilter($filter, ['supplier' => false, 'status' => false]); ?>
<div class="card tbl-scroll">
<table class="data-table">
  <thead>
    <tr><th>棚卸日</th><th>棚卸No</th><th>商品コード</th><th>商品名</th><th>帳簿数</th><th>実数</th><th>差異</th><th>差異の原因・理由</th><th>担当</th></tr>
  </thead>
  <tbody>
    <?php if (!$rows): ?><tr><td colspan="9">該当するデータがありません</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
    <tr class="<?= minusClass($r['diff_qty']) ?>">
      <td><?= h(formatDate($r['stocktaking_date'])) ?></td>
      <td class="num"><?= h($r['stocktaking_no']) ?></td>
      <td><?= h($r['product_code']) ?></td>
      <td><?= h($r['product_name'] . ' ' . $r['spec']) ?><?= storageBadge($r['storage_type']) ?></td>
      <td class="num"><?= h($r['book_qty']) ?></td>
      <td class="num"><?= h($r['actual_qty']) ?></td>
      <td class="num"><?= (int)$r['diff_qty'] > 0 ? '+' : '' ?><?= h($r['diff_qty']) ?></td>
      <td><?= h($r['reason']) ?></td>
      <td><?= h($r['operator_name'] ?? $r['created_by']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><td colspan="6" class="num">差異合計</td><td class="num"><?= h(array_sum(array_column($rows, 'diff_qty'))) ?></td><td colspan="2"></td></tr>
  </tfoot>
</table>
</div>
<div class="action-bar"><div class="bar-in">
  <span class="bar-spacer"></span>
  <a href="/menu.php" class="btn">メニューへ戻る</a>
</div></div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
