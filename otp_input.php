<?php
// SC-00b ワンタイムコード入力（F-00・質問No.10）
// 期限切れ・5回失敗はログイン画面（SC-00）からやり直し
require_once __DIR__ . '/common/auth.php';

if (!empty($_SESSION['operator_code'])) {
    redirect('/menu.php');
}
if (!isset($_SESSION['otp'])) {
    setFlash('error', 'もう一度ログインしてください');
    redirect('/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = postStr('otp');
    try {
        if (verifyOtp($code)) {
            redirect('/menu.php');
        }
    } catch (Throwable $e) {
        error_log('[otp] ' . $e->getMessage());
        setFlash('error', 'データベースに接続できません');
        redirect('/index.php');
    }
    if (!isset($_SESSION['otp'])) {
        setFlash('error', 'コードの有効期限切れ、または入力回数の上限です。パスワードから入力し直してください');
        redirect('/index.php');
    }
    $rest = OTP_MAX_TRIES - $_SESSION['otp']['tries'];
    setFlash('error', "コードが違います（あと {$rest} 回）");
    redirect('/otp_input.php');
}

// 送信先は一部伏字（例：ab****@gmail.com）
$email  = $_SESSION['otp']['email'];
$at     = strpos($email, '@');
$masked = $at === false ? '****' : substr($email, 0, min(2, $at)) . '****' . substr($email, $at);

$pageTitle = 'ワンタイムコード入力';
$isPublic  = true;
require_once __DIR__ . '/common/header.php';
?>
<p><?= h($masked) ?> にワンタイムコードを送信しました。<?= OTP_EXPIRE_MIN ?> 分以内に入力してください。</p>
<?php if (MAIL_REDIRECT_TO !== ''): ?>
  <p class="note">（デモ設定のため、メールはすべて管理用のアドレスに届きます）</p>
<?php endif; ?>
<?php if (isset($_SESSION['otp']['dev_code'])): ?>
  <p class="dev-note">開発モード（MAIL_DRIVER=log）のためメールは送っていません。コード：<strong><?= h($_SESSION['otp']['dev_code']) ?></strong></p>
<?php endif; ?>
<form method="post" class="login-box">
  <div class="form-grid">
    <label for="otp">ワンタイムコード</label>
    <input type="text" id="otp" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code">
  </div>
  <div class="btn-area">
    <button type="submit" class="btn btn-primary">確認</button>
    <a href="/logout.php" class="btn">ログイン画面へ戻る</a>
  </div>
</form>
<?php require_once __DIR__ . '/common/footer.php'; ?>
