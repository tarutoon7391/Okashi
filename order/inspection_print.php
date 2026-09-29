<?php
// SC-13 卸業者別検品一覧表（F-13）担当B　PDF帳票（質問No.6）
//   ?order_no=X     … その発注伝票の検品一覧表
//   ?order_date=Y   … その発注日に確定した全伝票（卸業者ごとに1ページ）
// 項目は発注書とほぼ同じなので getOrderSlip() / buildOrderSheetHtml() を共通で使う
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/query.php';
require_once __DIR__ . '/../common/pdf.php';
requireLogin();
$pdo = getDb();

$orderNos = [];
if (isset($_GET['order_no'])) {
    $orderNos = [(int)$_GET['order_no']];
} elseif (is_string($_GET['order_date'] ?? null) && isValidDate($_GET['order_date'])) {
    $st = $pdo->prepare('SELECT order_no FROM t_order WHERE is_confirmed = 1 AND order_date = :date ORDER BY supplier_code, order_no');
    $st->execute([':date' => $_GET['order_date']]);
    $orderNos = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

$pages = [];
foreach ($orderNos as $orderNo) {
    $slip = getOrderSlip($orderNo);
    if ($slip !== null && (int)$slip['is_confirmed'] === 1) {
        $pages[] = buildOrderSheetHtml($slip, 'inspection');
    }
}
if (!$pages) {
    setFlash('error', '確定済みの発注伝票が見つかりません');
    redirect('/order/order_print.php');
}
// ページ区切り（TCPDF は <br pagebreak="true">、印刷用HTMLでは改ページ）
$html = implode('<br pagebreak="true" style="page-break-after:always;">', $pages);
outputPdf('卸業者別検品一覧表', $html, 'inspection.pdf', 'I');
