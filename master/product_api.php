<?php
// 商品情報取得API（処理のみ・JSON）。発注入力（F-10）の JS から呼ぶ
// ?code=XXX（?product_code=XXX でも可）→ 商品名・商品名カナ・規格・入数・契約単価・卸業者名・保存区分・在庫数。
// 存在しない・削除済みなら {}
// stock_qty は t_stock の在庫数（int）。在庫行が無い商品は null
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';

header('Content-Type: application/json; charset=UTF-8');
if (empty($_SESSION['operator_code'])) {
    http_response_code(401);
    echo '{}';
    exit;
}

$code = $_GET['code'] ?? ($_GET['product_code'] ?? null);
$code = is_string($code) ? trim($code) : '';
$st = getDb()->prepare(
    'SELECT p.product_code, p.product_name, p.product_kana, p.spec, p.pack_qty, p.unit, p.contract_price, p.list_price,
            p.storage_type, p.supplier_code, s.supplier_name, t.stock_qty
       FROM m_product p
       JOIN m_supplier s ON s.supplier_code = p.supplier_code
       LEFT JOIN t_stock t ON t.product_code = p.product_code
      WHERE p.product_code = :code AND p.is_deleted = 0'
);
$st->execute([':code' => $code]);
$row = $st->fetch();
if ($row === false) {
    echo '{}';
    exit;
}
foreach (['pack_qty', 'contract_price', 'list_price', 'storage_type'] as $k) {
    $row[$k] = (int)$row[$k];
}
$row['stock_qty'] = $row['stock_qty'] === null ? null : (int)$row['stock_qty'];
echo json_encode($row, JSON_UNESCAPED_UNICODE);
