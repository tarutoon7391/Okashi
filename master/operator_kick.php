<?php
// 強制ログアウト（処理のみ）→ SC-74
// OP001 だけが実行できる。相手は次に画面を開いたとき、メッセージ付きでログイン画面へ戻る
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/master/operator_list.php');
}
$me   = currentOperator();
$code = postStr('operator_code');
$msg  = mb_substr(trim(postStr('message')), 0, 100);

if ($me['operator_code'] !== KICK_ADMIN_CODE) {
    setFlash('error', '強制ログアウトは ' . KICK_ADMIN_CODE . ' だけが行えます');
    redirect('/master/operator_list.php');
}
if ($code === $me['operator_code']) {
    setFlash('error', '自分自身は強制ログアウトできません');
    redirect('/master/operator_list.php');
}
$st = getDb()->prepare('SELECT operator_name FROM m_operator WHERE operator_code = :code AND is_deleted = 0');
$st->execute([':code' => $code]);
$name = $st->fetchColumn();
if ($name === false) {
    setFlash('error', '操作者が見つかりません');
    redirect('/master/operator_list.php');
}
kickOperator($code, $msg, $me['operator_code']);
setFlash('success', "{$name}（{$code}）を強制ログアウトしました。次に画面を操作したときにログイン画面へ戻ります");
redirect('/master/operator_list.php');
