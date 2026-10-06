<?php
// SC-60 発注明細表（F-60）担当E
// 指定期間内の発注伝票を卸業者別に一覧表示（取消のマイナス行も含めて表示）
// 起票者（created_by → m_operator.operator_name）と、プラスの行には取消済数
// （確定済みのマイナス明細で ref_order_no / ref_line_no がその行を指すものの合計）を出す
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
    "SELECT o.order_no, d.line_no, o.order_date, o.supplier_code, s.supplier_name, o.is_confirmed,
            d.product_code, p.product_name, p.spec, p.storage_type, d.order_qty, d.contract_price, d.amount, d.memo,
            d.ref_order_no, d.ref_line_no, o.created_by, op.operator_name AS created_name,
            (SELECT COALESCE(SUM(c.order_qty), 0)
               FROM t_order_detail c
               JOIN t_order co ON co.order_no = c.order_no
              WHERE co.is_confirmed = 1 AND c.ref_order_no = d.order_no AND c.ref_line_no = d.line_no) AS cancel_qty
       FROM t_order_detail d
       JOIN t_order o    ON o.order_no = d.order_no
       JOIN m_supplier s ON s.supplier_code = o.supplier_code
       JOIN m_product p  ON p.product_code = d.product_code
       LEFT JOIN m_operator op ON op.operator_code = o.created_by
      WHERE {$where}
      ORDER BY o.supplier_code, o.order_date, o.order_no, d.line_no"
);
$st->execute($params);
$groups = [];
foreach ($st->fetchAll() as $r) {
    $groups[$r['supplier_code']]['name'] = $r['supplier_name'];
    $groups[$r['supplier_code']]['rows'][] = $r;
}
$grandQty = 0;
$grandAmount = 0;

$pageTitle = '発注明細表';
require_once __DIR__ . '/../common/header.php';
?>
<?php renderReportFilter($filter); ?>
<div class="card tbl-scroll">
<table class="data-table">
  <thead>
    <tr><th>発注日</th><th>伝票No</th><th>商品コード</th><th>商品名</th><th>数量</th><th>取消済数</th><th>契約単価</th><th>納品金額</th><th>発注時メモ</th><th>起票者</th><th>状態</th></tr>
  </thead>
  <?php if (!$groups): ?>
    <tbody><tr><td colspan="11">該当するデータがありません</td></tr></tbody>
  <?php endif; ?>
  <?php foreach ($groups as $code => $g):
      $subQty = array_sum(array_column($g['rows'], 'order_qty'));
      $subAmount = array_sum(array_column($g['rows'], 'amount'));
      $grandQty += $subQty;
      $grandAmount += $subAmount; ?>
  <tbody>
    <tr class="group-row"><td colspan="11"><?= h($code . ' ' . $g['name']) ?></td></tr>
    <?php foreach ($g['rows'] as $r): ?>
    <tr class="<?= minusClass($r['order_qty']) ?>">
      <td><?= h(formatDate($r['order_date'])) ?></td>
      <td><?= h($r['order_no'] . '-' . $r['line_no']) ?></td>
      <td><?= h($r['product_code']) ?></td>
      <td><?= h($r['product_name'] . ' ' . $r['spec']) ?><?= storageBadge($r['storage_type']) ?>
        <?php if ($r['ref_order_no'] !== null): ?><small>（取消元 No.<?= h($r['ref_order_no'] . '-' . $r['ref_line_no']) ?>）</small><?php endif; ?></td>
      <td class="num"><?= h($r['order_qty']) ?></td>
      <td class="num qty-minus"><?= (int)$r['order_qty'] > 0 && (int)$r['cancel_qty'] !== 0 ? h($r['cancel_qty']) : '' ?></td>
      <td class="num"><?= h(formatYen($r['contract_price'])) ?></td>
      <td class="num"><?= h(formatYen($r['amount'])) ?></td>
      <td><?= h($r['memo']) ?></td>
      <td><?= h($r['created_name'] ?? $r['created_by'] ?? '') ?></td>
      <?= statusCell($r['is_confirmed']) ?>
    </tr>
    <?php endforeach; ?>
    <tr class="subtotal-row"><td colspan="4" class="num">小計</td><td class="num"><?= h($subQty) ?></td><td></td><td></td><td class="num"><?= h(formatYen($subAmount)) ?></td><td colspan="3"></td></tr>
  </tbody>
  <?php endforeach; ?>
  <tfoot>
    <tr><td colspan="4" class="num">合計</td><td class="num"><?= h($grandQty) ?></td><td></td><td></td><td class="num"><?= h(formatYen($grandAmount)) ?></td><td colspan="3"></td></tr>
  </tfoot>
</table>
</div>
<div class="action-bar"><div class="bar-in">
  <span class="bar-spacer"></span>
  <a href="/menu.php" class="btn">メニューへ戻る</a>
</div></div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
