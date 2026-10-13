<?php
// ログイン中の画面から数秒ごとに呼ばれる（js/common.js）。JSON を返す
//   ログイン中 → {"ok":true}（「ログイン中」表示のために最終通信時刻も記録）
//   強制ログアウトされた・ログインしていない → {"ok":false}（画面側がログイン画面へ移る。メッセージはセッションに入れてある）
require_once __DIR__ . '/common/auth.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (empty($_SESSION['operator_code']) || logoutIfKicked()) {
    echo json_encode(['ok' => false]);
    exit;
}
touchLastSeen($_SESSION['operator_code']);
echo json_encode(['ok' => true]);
