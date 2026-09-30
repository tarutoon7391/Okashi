<?php
// SC-13 卸業者別検品一覧表（F-13）担当B　PDF帳票（質問No.6）
//   ?order_no=X       … その発注伝票の検品一覧表
//   ?order_no=X,Y,Z   … 指定した伝票（発注書画面の「まとめて印刷」）
//   ?order_date=Y     … その発注日に確定した全伝票
// 同じ卸業者・同じ発注日の伝票は1ページにまとめる（卸業者ごとに1ページ）
// 各行に「検品□」と、手書き用の「備考」欄を付ける（06-2）
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/query.php';
require_once __DIR__ . '/../common/pdf.php';
requireLogin();
$pdo = getDb();

$orderNos = [];
if (isset($_GET['order_no'])) {
    $orderNos = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$_GET['order_no'])))));
} elseif (is_string($_GET['order_date'] ?? null) && isValidDate($_GET['order_date'])) {
    $st = $pdo->prepare('SELECT order_no FROM t_order WHERE is_confirmed = 1 AND order_date = :date ORDER BY supplier_code, order_no');
    $st->execute([':date' => $_GET['order_date']]);
    $orderNos = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// 卸業者＋発注日ごとにまとめる
$groups = [];
foreach ($orderNos as $orderNo) {
    $slip = getOrderSlip($orderNo);
    if ($slip !== null && (int)$slip['is_confirmed'] === 1) {
        $groups[$slip['supplier_code'] . '|' . $slip['order_date']][] = $slip;
    }
}
if (!$groups) {
    setFlash('error', '確定済みの発注伝票が見つかりません');
    redirect('/order/order_print.php');
}
ksort($groups);

// 1ページ分（同じ卸業者・同じ発注日の伝票をまとめたもの）の HTML
function buildInspectionPageHtml(array $slips): string
{
    $first = $slips[0];
    $html  = '<h1 style="text-align:center;font-size:16pt;">卸業者別 検品一覧表</h1>';
    $html .= '<table cellpadding="3" width="100%"><tr>'
           . '<td width="60%">卸業者コード：' . h($first['supplier_code']) . '　卸業者名：' . h($first['supplier_name']) . '</td>'
           . '<td width="40%" align="right">発注日：' . h(formatDate($first['order_date'])) . '<br>'
           . '発注伝票No：' . h(implode(', ', array_column($slips, 'order_no'))) . '</td>'
           . '</tr></table><br>';

    $html .= '<table border="1" cellpadding="3" width="100%">'
           . '<tr style="background-color:#e6ecf5;">'
           . '<th width="7%">伝票No</th><th width="10%">商品コード</th><th width="18%">商品名</th>'
           . '<th width="9%">規格</th><th width="5%">入数</th><th width="6%">数量</th>'
           . '<th width="8%">契約単価</th><th width="9%">納品金額</th><th width="12%">発注時メモ</th>'
           . '<th width="5%">検品</th><th width="11%">備考</th>'
           . '</tr>';
    $totalQty = 0;
    $totalAmount = 0;
    foreach ($slips as $slip) {
        foreach ($slip['details'] as $d) {
            $color = (int)$d['order_qty'] < 0 ? ' style="color:#c53030;"' : '';
            $storage = storageTypeName((int)$d['storage_type']);
            $html .= '<tr' . $color . '>'
                   . '<td>' . h($d['order_no'] . '-' . $d['line_no']) . '</td>'
                   . '<td>' . h($d['product_code']) . '</td>'
                   . '<td>' . h($d['product_name']) . ((int)$d['storage_type'] > 0 ? '【' . h($storage) . '】' : '') . '</td>'
                   . '<td>' . h($d['spec']) . '</td>'
                   . '<td align="right">' . h($d['pack_qty']) . '</td>'
                   . '<td align="right">' . h($d['order_qty']) . '</td>'
                   . '<td align="right">' . h(formatYen($d['contract_price'])) . '</td>'
                   . '<td align="right">' . h(formatYen($d['amount'])) . '</td>'
                   . '<td>' . h($d['memo']) . '</td>'
                   . '<td align="center">□</td>'
                   . '<td></td>'   // 備考（手書き用の空欄）
                   . '</tr>';
        }
        $totalQty    += (int)$slip['total_qty'];
        $totalAmount += (int)$slip['total_amount'];
    }
    $html .= '<tr style="background-color:#f0f0f0;"><td colspan="5" align="right">合計</td>'
           . '<td align="right">' . h($totalQty) . '</td><td></td>'
           . '<td align="right">' . h(formatYen($totalAmount)) . '</td>'
           . '<td colspan="3"></td></tr>';
    $html .= '</table>';
    $html .= '<p>※ マイナス数量の行（赤字）は取消（訂正）です。</p>';
    return $html;
}

$pages = array_map('buildInspectionPageHtml', array_values($groups));
// ページ区切り（TCPDF は <br pagebreak="true">、印刷用HTMLでは改ページ）
$html = implode('<br pagebreak="true" style="page-break-after:always;">', $pages);
outputPdf('卸業者別検品一覧表', $html, 'inspection.pdf', 'I');
