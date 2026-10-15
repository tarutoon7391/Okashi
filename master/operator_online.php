<?php
// SC-74 用：ログイン中の操作者を JSON で返す（js/operator_list.js が数秒ごとに呼ぶ）。U001 だけ
// {"U002":"10:31", ...}（操作者コード => 最終通信の時刻）
require_once __DIR__ . '/../common/auth.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (($_SESSION['operator_code'] ?? '') !== KICK_ADMIN_CODE || logoutIfKicked()) {
    http_response_code(403);
    echo json_encode(new stdClass());
    exit;
}
$out = [];
foreach (onlineOperators() as $code => $t) {
    $out[$code] = date('H:i', $t);
}
echo json_encode((object)$out);
