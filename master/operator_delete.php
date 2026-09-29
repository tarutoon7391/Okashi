<?php
// 操作者削除（処理のみ・F-72）担当A
// is_deleted=1 に更新する（論理削除）。自分自身と、最後の発注承認可の操作者は削除しない → SC-74
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/master/operator_list.php');
}
$pdo  = getDb();
$code = postStr('operator_code');
$me   = currentOperator();

if ($code === $me['operator_code']) {
    setFlash('error', '自分自身は削除できません');
    redirect('/master/operator_list.php');
}
$st = $pdo->prepare('SELECT can_approve_order FROM m_operator WHERE operator_code = :code AND is_deleted = 0');
$st->execute([':code' => $code]);
$target = $st->fetch();
if ($target === false) {
    setFlash('error', '操作者が見つかりません（既に削除済みの可能性があります）');
    redirect('/master/operator_list.php');
}
if ((int)$target['can_approve_order'] === 1) {
    if ($me['can_approve_order'] !== 1) {
        setFlash('error', '発注承認可の操作者を削除できるのは、発注承認可の操作者だけです');
        redirect('/master/operator_list.php');
    }
    $cnt = (int)$pdo->query('SELECT COUNT(*) FROM m_operator WHERE can_approve_order = 1 AND is_deleted = 0')->fetchColumn();
    if ($cnt <= 1) {
        setFlash('error', '発注承認可の操作者が1人もいなくなるため削除できません');
        redirect('/master/operator_list.php');
    }
}
$st = $pdo->prepare('UPDATE m_operator SET is_deleted = 1, updated_by = :by WHERE operator_code = :code AND is_deleted = 0');
$st->execute([':by' => $me['operator_code'], ':code' => $code]);
setFlash('success', "操作者 {$code} を削除しました");
redirect('/master/operator_list.php');
