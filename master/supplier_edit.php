<?php
// SC-73 卸業者登録・編集（F-71）担当A
// ?code=XXX があれば編集モード、無ければ新規
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
requireLogin();
$pdo = getDb();
$errors = [];

$fields = ['supplier_code', 'supplier_name', 'office_address', 'order_email', 'contact_name', 'memo'];
$editCode = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
$isEdit = $editCode !== '';
$supplier = array_fill_keys($fields, '');

if ($isEdit) {
    $st = $pdo->prepare('SELECT * FROM m_supplier WHERE supplier_code = :code AND is_deleted = 0');
    $st->execute([':code' => $editCode]);
    $found = $st->fetch();
    if ($found === false) {
        setFlash('error', '卸業者が見つかりません');
        redirect('/master/supplier_list.php');
    }
    $supplier = $found;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. 入力値の取得・バリデーション
    foreach ($fields as $f) {
        $supplier[$f] = postStr($f);
    }
    if ($isEdit) {
        $supplier['supplier_code'] = $editCode;
    }
    $s = $supplier;
    if (!preg_match('/\A[A-Za-z0-9_-]{1,10}\z/', $s['supplier_code'])) {
        $errors[] = '卸業者コードは英数字10文字以内で入力してください';
    } elseif (!$isEdit) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM m_supplier WHERE supplier_code = :code');
        $st->execute([':code' => $s['supplier_code']]);
        if ((int)$st->fetchColumn() > 0) {
            $errors[] = 'その卸業者コードは既に使われています（削除済みを含む）';
        }
    }
    if ($s['supplier_name'] === '' || mb_strlen($s['supplier_name']) > 100) {
        $errors[] = '卸業者名を100文字以内で入力してください';
    }
    if (mb_strlen($s['office_address']) > 200) {
        $errors[] = '営業所住所は200文字以内で入力してください';
    }
    if ($s['order_email'] !== '' && (!filter_var($s['order_email'], FILTER_VALIDATE_EMAIL) || strlen($s['order_email']) > 100)) {
        $errors[] = '発注先メールアドレスの形式が正しくありません';
    }
    if (mb_strlen($s['contact_name']) > 50) {
        $errors[] = '担当者名は50文字以内で入力してください';
    }

    // 2. DB更新
    if (!$errors) {
        $by = currentOperator()['operator_code'];
        $values = [
            ':code'    => $s['supplier_code'],
            ':name'    => $s['supplier_name'],
            ':address' => $s['office_address'] === '' ? null : $s['office_address'],
            ':email'   => $s['order_email'] === '' ? null : $s['order_email'],
            ':contact' => $s['contact_name'] === '' ? null : $s['contact_name'],
            ':memo'    => $s['memo'] === '' ? null : $s['memo'],
            ':by'      => $by,
        ];
        try {
            if ($isEdit) {
                $st = $pdo->prepare(
                    'UPDATE m_supplier SET supplier_name = :name, office_address = :address, order_email = :email,
                            contact_name = :contact, memo = :memo, updated_by = :by
                      WHERE supplier_code = :code AND is_deleted = 0'
                );
            } else {
                $values[':by2'] = $by;
                $st = $pdo->prepare(
                    'INSERT INTO m_supplier (supplier_code, supplier_name, office_address, order_email, contact_name, memo, created_by, updated_by)
                     VALUES (:code, :name, :address, :email, :contact, :memo, :by, :by2)'
                );
            }
            $st->execute($values);
            setFlash('success', '卸業者「' . $s['supplier_name'] . '」を' . ($isEdit ? '更新' : '登録') . 'しました');
            redirect('/master/supplier_list.php');
        } catch (Throwable $e) {
            $errors[] = '保存に失敗しました：' . $e->getMessage();
        }
    }
    setFlash('error', implode("\n", $errors));
}

$pageTitle = $isEdit ? '卸業者編集' : '卸業者登録';
require_once __DIR__ . '/../common/header.php';
?>
<form method="post">
  <div class="form-grid">
    <label for="supplierCode">卸業者コード <span class="req">必須</span></label>
    <?php if ($isEdit): ?>
      <div><strong><?= h($supplier['supplier_code']) ?></strong>（変更できません）</div>
    <?php else: ?>
      <input type="text" id="supplierCode" name="supplier_code" value="<?= h($supplier['supplier_code']) ?>" maxlength="10" pattern="[A-Za-z0-9_\-]+" required>
    <?php endif; ?>
    <label for="supplierName">卸業者名 <span class="req">必須</span></label>
    <input type="text" id="supplierName" name="supplier_name" value="<?= h($supplier['supplier_name']) ?>" maxlength="100" required>
    <label for="officeAddress">営業所住所</label>
    <input type="text" id="officeAddress" name="office_address" value="<?= h($supplier['office_address']) ?>" maxlength="200">
    <label for="orderEmail">発注先メールアドレス</label>
    <div>
      <input type="email" id="orderEmail" name="order_email" value="<?= h($supplier['order_email']) ?>" maxlength="100">
      <br><small>発注確定時に発注書PDFをこのアドレスへ送ります。未登録なら送信しません。</small>
    </div>
    <label for="contactName">担当者名</label>
    <input type="text" id="contactName" name="contact_name" value="<?= h($supplier['contact_name']) ?>" maxlength="50">
    <label for="memo">補足メモ</label>
    <textarea id="memo" name="memo" rows="3"><?= h($supplier['memo']) ?></textarea>
  </div>
  <div class="btn-area">
    <button type="submit" class="btn btn-primary">保存</button>
    <a href="/master/supplier_list.php" class="btn">一覧へ戻る</a>
  </div>
</form>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
