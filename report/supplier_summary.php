<?php
// SC-69 卸業者別納品金額（F-69）担当E
// 期間を指定し、卸業者別の 納品金額・返品金額・純額（返品を差し引いた額）を表と円グラフで表示
// 円グラフは js/supplier_summary.js が canvas で描く（ライブラリなし）。データは JSON で埋め込む
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/report.php';
requireLogin();
$pdo = getDb();

$filter = getReportFilter();
$params = [];
$where  = buildReportWhere($filter, ['date' => 'h.delivery_date', 'status' => 'h.is_confirmed'], $params);
$st = $pdo->prepare(
    "SELECT s.supplier_code, s.supplier_name,
            COALESCE(SUM(CASE WHEN h.is_return = 0 THEN d.amount END), 0) AS delivery_amount,
            COALESCE(SUM(CASE WHEN h.is_return = 1 THEN d.amount END), 0) AS return_amount,
            COALESCE(SUM(d.amount), 0) AS net_amount
       FROM t_delivery_detail d
       JOIN t_delivery h ON h.delivery_no = d.delivery_no
       JOIN m_supplier s ON s.supplier_code = h.supplier_code
      WHERE {$where}
      GROUP BY s.supplier_code, s.supplier_name
      ORDER BY net_amount DESC, s.supplier_code"
);
$st->execute($params);
$rows = $st->fetchAll();

// 構成比は純額がプラスの卸業者だけで計算（純額0以下はグラフに出さない）
$chartTotal = array_sum(array_map(fn($r) => max(0, (int)$r['net_amount']), $rows));
$colors = ['#2b6cb0', '#dd6b20', '#38a169', '#d53f8c', '#805ad5', '#d69e2e', '#319795', '#718096'];
$chartData = [];
// 色はグラフに出す（純額プラスの）卸業者にだけ順に割り当てる。純額0以下は凡例の色も出さない
$colorIndex = 0;
foreach ($rows as &$r) {
    $r['color'] = null;
    $r['ratio'] = $chartTotal > 0 && (int)$r['net_amount'] > 0 ? round((int)$r['net_amount'] / $chartTotal * 100, 1) : null;
    if ((int)$r['net_amount'] > 0) {
        $r['color'] = $colors[$colorIndex++ % count($colors)];
        $chartData[] = ['label' => $r['supplier_name'], 'value' => (int)$r['net_amount'], 'color' => $r['color']];
    }
}
unset($r);

$pageTitle  = '卸業者別納品金額';
$pageScript = 'supplier_summary.js';
require_once __DIR__ . '/../common/header.php';
?>
<?php renderReportFilter($filter, ['supplier' => false, 'product' => false]); ?>
<div class="chart-layout">
  <div class="card tbl-scroll">
  <table class="data-table">
    <thead>
      <tr><th></th><th>卸業者コード</th><th>卸業者名</th><th>納品金額</th><th>返品金額</th><th>純額</th><th>構成比</th></tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="7">該当するデータがありません</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?php if ($r['color'] !== null): ?><span class="legend-color" style="background:<?= h($r['color']) ?>"></span><?php endif; ?></td>
        <td><?= h($r['supplier_code']) ?></td>
        <td><?= h($r['supplier_name']) ?></td>
        <td class="num"><?= h(formatYen($r['delivery_amount'])) ?></td>
        <td class="num qty-minus"><?= (int)$r['return_amount'] !== 0 ? h(formatYen($r['return_amount'])) : '' ?></td>
        <td class="num"><strong><?= h(formatYen($r['net_amount'])) ?></strong></td>
        <td class="num"><?= $r['ratio'] !== null ? h($r['ratio']) . '%' : '―' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="3" class="num">合計</td>
        <td class="num"><?= h(formatYen(array_sum(array_column($rows, 'delivery_amount')))) ?></td>
        <td class="num"><?= h(formatYen(array_sum(array_column($rows, 'return_amount')))) ?></td>
        <td class="num"><?= h(formatYen(array_sum(array_column($rows, 'net_amount')))) ?></td>
        <td></td></tr>
    </tfoot>
  </table>
  </div>
  <div class="chart-box">
    <canvas id="supplierChart" width="320" height="320" aria-label="卸業者別納品金額の円グラフ"></canvas>
    <p id="chartEmpty" class="note" hidden>グラフに出せるデータがありません</p>
  </div>
</div>
<script id="chartData" type="application/json"><?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<div class="action-bar"><div class="bar-in">
  <span class="bar-spacer"></span>
  <a href="/menu.php" class="btn">メニューへ戻る</a>
</div></div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
