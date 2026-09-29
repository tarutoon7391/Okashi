<?php
// 商品削除（処理のみ・F-70）担当A
// DELETE は使わず is_deleted=1 に更新する（論理削除）→ SC-70
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/master/product_list.php');
}
$code = postStr('product_code');
$st = getDb()->prepare('UPDATE m_product SET is_deleted = 1, updated_by = :by WHERE product_code = :code AND is_deleted = 0');
$st->execute([':by' => currentOperator()['operator_code'], ':code' => $code]);
if ($st->rowCount() === 1) {
    setFlash('success', "商品 {$code} を削除しました");
} else {
    setFlash('error', '商品が見つかりません（既に削除済みの可能性があります）');
}
redirect('/master/product_list.php');
