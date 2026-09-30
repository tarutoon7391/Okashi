<?php
// SC-00 ログイン（F-00）
// 操作者コード＋パスワード → 登録メールへワンタイムコードを送って otp_input.php へ
require_once __DIR__ . '/common/auth.php';

if (!empty($_SESSION['operator_code'])) {
    redirect('/menu.php');
}

$operatorCode = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $operatorCode = postStr('operator_code');
    $password     = postStr('password');
    unset($_SESSION['otp']);
    if ($operatorCode === '' || $password === '') {
        setFlash('error', '操作者コードとパスワードを入力してください');
    } elseif (($remain = loginLockRemain($operatorCode)) > 0) {
        // 総当たり対策：ロック中はパスワードを照合しない
        setFlash('error', 'パスワードを' . LOGIN_MAX_FAILS . '回まちがえたため、ロックしています。約' . (int)ceil($remain / 60) . '分後にやり直してください');
    } else {
        try {
            if (login($operatorCode, $password)) {
                clearLoginFailures($operatorCode);
                redirect('/otp_input.php');
            }
            if (isset($_SESSION['otp'])) {
                // パスワードは合っていたがメールが送れなかった
                unset($_SESSION['otp']);
                clearLoginFailures($operatorCode);
                setFlash('error', 'ワンタイムコードのメール送信に失敗しました。管理者に連絡してください');
            } else {
                recordLoginFailure($operatorCode);
                setFlash('error', '操作者コードまたはパスワードが違います（' . LOGIN_MAX_FAILS . '回まちがえると' . LOGIN_LOCK_MIN . '分ロックします）');
            }
        } catch (Throwable $e) {
            error_log('[login] ' . $e->getMessage());
            setFlash('error', 'データベースに接続できません。時間をおいて再度お試しください');
        }
    }
}

$pageTitle = 'ログイン';
$isPublic  = true;
require_once __DIR__ . '/common/header.php';
?>
<form method="post" class="login-box">
  <div class="form-grid">
    <label for="operatorCode">操作者コード</label>
    <input type="text" id="operatorCode" name="operator_code" value="<?= h($operatorCode) ?>" maxlength="10" required autofocus>
    <label for="password">パスワード</label>
    <input type="password" id="password" name="password" required>
  </div>
  <div class="btn-area">
    <button type="submit" class="btn btn-primary">ログイン</button>
  </div>
  <p class="note">ログイン後、登録済みのメールアドレスにワンタイムコード（6桁）が届きます。</p>
</form>
<?php require_once __DIR__ . '/common/footer.php'; ?>
