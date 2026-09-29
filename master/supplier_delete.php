<?php
// 卸業者削除（処理のみ・F-71）担当A
// is_deleted=1 に更新する（論理削除）。有効な商品が残っている卸業者は削除しない → SC-72
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/master/supplier_list.php');
}
$pdo  = getDb();
$code = postStr('supplier_code');

$st = $pdo->prepare('SELECT COUNT(*) FROM m_product WHERE supplier_code = :code AND is_deleted = 0');
$st->execute([':code' => $code]);
if ((int)$st->fetchColumn() > 0) {
    setFlash('error', "卸業者 {$code} には有効な商品があるため削除できません。先に商品の契約卸を変更するか商品を削除してください");
    redirect('/master/supplier_list.php');
}
$st = $pdo->prepare('UPDATE m_supplier SET is_deleted = 1, updated_by = :by WHERE supplier_code = :code AND is_deleted = 0');
$st->execute([':by' => currentOperator()['operator_code'], ':code' => $code]);
if ($st->rowCount() === 1) {
    setFlash('success', "卸業者 {$code} を削除しました");
} else {
    setFlash('error', '卸業者が見つかりません（既に削除済みの可能性があります）');
}
redirect('/master/supplier_list.php');
