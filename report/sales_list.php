<?php
// SC-66 売上明細表（F-66）担当D
// 指定期間内の売上を一覧表示（訂正のマイナス行も含める）。卸業者の絞り込みは出さない
// ?pdf=1 で同じ内容を PDF（横向き A4）にする
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
            t.sales_qty, t.amount, t.is_confirmed,
            t.created_by, op.operator_name AS created_name, t.confirmed_by, cop.operator_name AS confirmed_name
       FROM t_sales t JOIN m_product p ON p.product_code = t.product_code
       LEFT JOIN m_operator op  ON op.operator_code = t.created_by
       LEFT JOIN m_operator cop ON cop.operator_code = t.confirmed_by
      WHERE {$where}
      ORDER BY t.sales_date, p.product_kana, t.sales_no"
);
$st->execute($params);
$rows = $st->fetchAll();

// PDF（?pdf=1）：画面と同じ条件・同じ行を横向き A4 で出す
if (isReportPdfRequested()) {
    $pdfRows = [];
    foreach ($rows as $r) {
        $pdfRows[] = ['minus' => (int)$r['sales_qty'] < 0, 'cells' => [
            formatDate($r['sales_date']), $r['sales_no'], $r['product_code'],
            pdfProductText($r['product_name'], $r['spec'], $r['storage_type']),
            formatYen($r['list_price']), $r['sales_qty'], formatYen($r['amount']),
            $r['created_name'] ?? $r['created_by'] ?? '', $r['confirmed_name'] ?? $r['confirmed_by'] ?? '',
            (int)$r['is_confirmed'] === 1 ? '確定済' : '未確定',
        ]];
    }
    outputReportPdf('売上明細表', 'sales_list', $filter, ['supplier' => false], [
        ['label' => '売上日', 'width' => 10], ['label' => '売上No', 'width' => 7, 'align' => 'right'],
        ['label' => '商品コード', 'width' => 9], ['label' => '商品名', 'width' => 30],
        ['label' => '定価', 'width' => 9, 'align' => 'right'], ['label' => '売上数', 'width' => 7, 'align' => 'right'],
        ['label' => '売上金額', 'width' => 10, 'align' => 'right'],
        ['label' => '入力者', 'width' => 6], ['label' => '確定者', 'width' => 6], ['label' => '状態', 'width' => 6, 'align' => 'center'],
    ], [['label' => null, 'rows' => $pdfRows, 'subtotal' => null]],
    ['', '', '', '', '合計', array_sum(array_column($rows, 'sales_qty')), formatYen(array_sum(array_column($rows, 'amount'))), '', '', '']);
}

$pageTitle = '売上明細表';
require_once __DIR__ . '/../common/header.php';
?>
<?php renderReportFilter($filter, ['supplier' => false]); ?>
<div class="card tbl-scroll">
<table class="data-table">
  <thead>
    <tr><th>売上日</th><th>売上No</th><th>商品コード</th><th>商品名</th><th>定価</th><th>売上数</th><th>売上金額</th><th>入力者</th><th>確定者</th><th>状態</th></tr>
  </thead>
  <tbody>
    <?php if (!$rows): ?><tr><td colspan="10">該当するデータがありません</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
    <tr class="<?= minusClass($r['sales_qty']) ?>">
      <td><?= h(formatDate($r['sales_date'])) ?></td>
      <td class="num"><?= h($r['sales_no']) ?></td>
      <td><?= h($r['product_code']) ?></td>
      <td><?= h(productLabel($r['product_name'], $r['spec'])) ?><?= storageBadge($r['storage_type']) ?></td>
      <td class="num"><?= h(formatYen($r['list_price'])) ?></td>
      <td class="num"><?= h($r['sales_qty']) ?></td>
      <td class="num"><?= h(formatYen($r['amount'])) ?></td>
      <td><?= h($r['created_name'] ?? $r['created_by'] ?? '') ?></td>
      <td><?= h($r['confirmed_name'] ?? $r['confirmed_by'] ?? '') ?></td>
      <?= statusCell($r['is_confirmed']) ?>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><td colspan="5" class="num">合計</td>
      <td class="num"><?= h(array_sum(array_column($rows, 'sales_qty'))) ?></td>
      <td class="num"><?= h(formatYen(array_sum(array_column($rows, 'amount')))) ?></td><td colspan="3"></td></tr>
  </tfoot>
</table>
</div>
<div class="action-bar"><div class="bar-in">
  <span class="bar-spacer"></span>
  <?= reportPdfButton() ?>
  <a href="/menu.php" class="btn">メニューへ戻る</a>
</div></div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
