<?php
// SC-75 操作者登録・編集（F-72）担当A
// ・メールアドレスは必須（ワンタイムコードの送信先：質問No.10）
// ・パスワード（初期パスワード含む）と発注承認可否は、発注承認可の操作者だけが設定できる（質問No.5）
//   承認不可の人が開いたときは欄を出さず、POST されても無視する。新規登録も承認可の人だけ
// ・パスワードは新規時のみ必須。編集時は空欄なら変更しない
// ・承認不可の操作者は「自分自身」の操作者名・メールアドレスだけ編集できる。他人の編集・新規登録は不可
// ・GET パラメータは ?operator_code=XXX（01 §4.1）。旧 ?code=XXX も互換のため受け付ける
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
requireLogin();
$pdo = getDb();
$errors = [];

$me = currentOperator();
// 権限はセッションではなく DB の最新値で判定する（ログイン中に権限を外された場合に備える）
$st = $pdo->prepare('SELECT can_approve_order FROM m_operator WHERE operator_code = :code AND is_deleted = 0');
$st->execute([':code' => $me['operator_code']]);
$me['can_approve_order'] = (int)$st->fetchColumn();
$isApprover = $me['can_approve_order'] === 1;
$editCode = $_GET['operator_code'] ?? ($_GET['code'] ?? null);
$editCode = is_string($editCode) ? $editCode : '';
$isEdit = $editCode !== '';
$operator = ['operator_code' => '', 'operator_name' => '', 'email' => '', 'can_approve_order' => 0];

if (!$isEdit && !$isApprover) {
    setFlash('error', '操作者の新規登録は発注承認可の操作者だけが行えます');
    redirect('/master/operator_list.php');
}
if ($isEdit && !$isApprover && $editCode !== $me['operator_code']) {
    setFlash('error', '他の操作者の編集は発注承認可の操作者だけが行えます');
    redirect('/master/operator_list.php');
}
if ($isEdit) {
    $st = $pdo->prepare('SELECT operator_code, operator_name, email, can_approve_order FROM m_operator
                          WHERE operator_code = :code AND is_deleted = 0');
    $st->execute([':code' => $editCode]);
    $found = $st->fetch();
    if ($found === false) {
        setFlash('error', '操作者が見つかりません');
        redirect('/master/operator_list.php');
    }
    $operator = $found;
}

// 承認可の操作者が何人いるか（最後の1人の権限を外さないため）
function countApprovers(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM m_operator WHERE can_approve_order = 1 AND is_deleted = 0')->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. 入力値の取得・バリデーション
    if (!$isEdit) {
        $operator['operator_code'] = postStr('operator_code');
    }
    $operator['operator_name'] = postStr('operator_name');
    $operator['email']         = postStr('email');
    $password = '';
    $wasApprover = (int)$operator['can_approve_order'];
    if ($isApprover) {
        $password = $_POST['password'] ?? '';
        $password = is_string($password) ? $password : '';
        $operator['can_approve_order'] = postStr('can_approve_order') === '1' ? 1 : 0;
    }
    $o = $operator;
    if (!preg_match('/\A[A-Za-z0-9_-]{1,10}\z/', $o['operator_code'])) {
        $errors[] = '操作者コードは英数字10文字以内で入力してください';
    } elseif (!$isEdit) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM m_operator WHERE operator_code = :code');
        $st->execute([':code' => $o['operator_code']]);
        if ((int)$st->fetchColumn() > 0) {
            $errors[] = 'その操作者コードは既に使われています（削除済みを含む）';
        }
    }
    if ($o['operator_name'] === '' || mb_strlen($o['operator_name']) > 50) {
        $errors[] = '操作者名を50文字以内で入力してください';
    }
    if (!filter_var($o['email'], FILTER_VALIDATE_EMAIL) || strlen($o['email']) > 100) {
        $errors[] = 'メールアドレスを正しく入力してください（ワンタイムコードの送信先・必須）';
    }
    if (!$isEdit && $password === '') {
        $errors[] = '初期パスワードを入力してください';
    }
    if ($password !== '' && (strlen($password) < 8 || strlen($password) > 72)) {
        $errors[] = 'パスワードは8〜72文字で入力してください';
    }
    if ($isEdit && $wasApprover === 1 && (int)$o['can_approve_order'] === 0 && countApprovers($pdo) <= 1) {
        $errors[] = '発注承認可の操作者が1人もいなくなるため、承認権限は外せません';
    }

    // 2. DB更新
    if (!$errors) {
        $by = $me['operator_code'];
        try {
            if ($isEdit) {
                $sql = 'UPDATE m_operator SET operator_name = :name, email = :email, updated_by = :by';
                $values = [':name' => $o['operator_name'], ':email' => $o['email'], ':by' => $by, ':code' => $o['operator_code']];
                if ($isApprover) {
                    $sql .= ', can_approve_order = :approve';
                    $values[':approve'] = (int)$o['can_approve_order'];
                    if ($password !== '') {
                        $sql .= ', password_hash = :hash';
                        $values[':hash'] = password_hash($password, PASSWORD_DEFAULT);
                    }
                }
                $st = $pdo->prepare($sql . ' WHERE operator_code = :code AND is_deleted = 0');
                $st->execute($values);
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO m_operator (operator_code, operator_name, email, password_hash, can_approve_order, created_by, updated_by)
                     VALUES (:code, :name, :email, :hash, :approve, :by, :by2)'
                );
                $st->execute([
                    ':code'    => $o['operator_code'],
                    ':name'    => $o['operator_name'],
                    ':email'   => $o['email'],
                    ':hash'    => password_hash($password, PASSWORD_DEFAULT),
                    ':approve' => (int)$o['can_approve_order'],
                    ':by'      => $by,
                    ':by2'     => $by,
                ]);
            }
            // 自分の名前・権限を変えたときはセッションにも反映
            if ($o['operator_code'] === $me['operator_code']) {
                $_SESSION['operator_name']     = $o['operator_name'];
                $_SESSION['can_approve_order'] = (int)$o['can_approve_order'];
            }
            setFlash('success', '操作者「' . $o['operator_name'] . '」を' . ($isEdit ? '更新' : '登録') . 'しました');
            redirect('/master/operator_list.php');
        } catch (Throwable $e) {
            error_log('operator_edit: ' . $e->getMessage());
            $errors[] = '保存に失敗しました。時間をおいてもう一度お試しください';
        }
    }
    setFlash('error', implode("\n", $errors));
}

$pageTitle = $isEdit ? '操作者編集' : '操作者登録';
require_once __DIR__ . '/../common/header.php';
?>
<form method="post" autocomplete="off">
  <div class="form-grid">
    <label for="operatorCode">操作者コード <span class="req">必須</span></label>
    <?php if ($isEdit): ?>
      <div><strong><?= h($operator['operator_code']) ?></strong>（変更できません）</div>
    <?php else: ?>
      <input type="text" id="operatorCode" name="operator_code" value="<?= h($operator['operator_code']) ?>" maxlength="10" pattern="[A-Za-z0-9_\-]+" required>
    <?php endif; ?>
    <label for="operatorName">操作者名 <span class="req">必須</span></label>
    <input type="text" id="operatorName" name="operator_name" value="<?= h($operator['operator_name']) ?>" maxlength="50" required>
    <label for="email">メールアドレス <span class="req">必須</span></label>
    <div>
      <input type="email" id="email" name="email" value="<?= h($operator['email']) ?>" maxlength="100" required>
      <br><small>ログイン時のワンタイムコードがここに届きます</small>
    </div>
    <?php if ($isApprover): ?>
    <label for="password">パスワード<?= $isEdit ? '' : ' <span class="req">必須</span>' ?></label>
    <div>
      <input type="password" id="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>>
      <?php if ($isEdit): ?><br><small>変更するときだけ入力（空欄なら変わりません）</small><?php endif; ?>
    </div>
    <label>発注承認</label>
    <label class="radio"><input type="checkbox" name="can_approve_order" value="1" <?= (int)$operator['can_approve_order'] === 1 ? 'checked' : '' ?>> 発注確定を許可する</label>
    <?php else: ?>
    <label>発注承認</label>
    <div><?= (int)$operator['can_approve_order'] === 1 ? '可' : '不可' ?>（変更は発注承認可の操作者が行います）</div>
    <?php endif; ?>
  </div>
  <div class="btn-area">
    <button type="submit" class="btn btn-primary">保存</button>
    <a href="/master/operator_list.php" class="btn">一覧へ戻る</a>
  </div>
</form>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
