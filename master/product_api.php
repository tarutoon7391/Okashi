<?php
// 商品情報取得API（処理のみ・JSON）。発注入力（F-10）の JS から呼ぶ
// ?code=XXX → 商品名・規格・入数・契約単価・卸業者名・保存区分。存在しない・削除済みなら {}
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';

header('Content-Type: application/json; charset=UTF-8');
if (empty($_SESSION['operator_code'])) {
    http_response_code(401);
    echo '{}';
    exit;
}

$code = is_string($_GET['code'] ?? null) ? trim($_GET['code']) : '';
$st = getDb()->prepare(
    'SELECT p.product_code, p.product_name, p.spec, p.pack_qty, p.unit, p.contract_price, p.list_price,
            p.storage_type, p.supplier_code, s.supplier_name
       FROM m_product p JOIN m_supplier s ON s.supplier_code = p.supplier_code
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
echo json_encode($row, JSON_UNESCAPED_UNICODE);
