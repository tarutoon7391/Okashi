<?php
// セッション開始・ログイン判定・権限判定（05 §3）
// 二要素認証はメールのワンタイムコード方式（質問No.10）。
// MAIL_DRIVER=log のときはメールを送らず、otp_input.php にコードを表示する（開発・デモ用）
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mail.php';

if (session_status() === PHP_SESSION_NONE) {
    // Railway はプロキシの後ろで https になるので X-Forwarded-Proto も見る
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// 未ログインなら index.php（ログイン画面）へ
function requireLogin(): void
{
    if (empty($_SESSION['operator_code'])) {
        redirect('/index.php');
    }
}

// 発注承認可（can_approve_order=1）の操作者だけ通す。発注確定画面で使う
function requireApprover(): void
{
    requireLogin();
    if ((int)($_SESSION['can_approve_order'] ?? 0) !== 1) {
        setFlash('error', '権限がありません');
        redirect('/menu.php');
    }
}

// ログイン用に操作者を1件取得（削除済みは除く）。無ければ null
function findOperatorForLogin(string $code): ?array
{
    $st = getDb()->prepare(
        'SELECT operator_code, operator_name, email, password_hash, can_approve_order
           FROM m_operator
          WHERE operator_code = :code AND is_deleted = 0'
    );
    $st->execute([':code' => $code]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

// 1段階目（パスワード）の認証。成功したら verifySecondFactor() でコードを送る
// true が返ったら呼び出し側は otp_input.php へ遷移する
function login(string $code, string $password): bool
{
    $op = findOperatorForLogin($code);
    if ($op === null || !password_verify($password, $op['password_hash'])) {
        return false;
    }
    return verifySecondFactor($op);
}

// 2段階目の準備：6桁のコードを作ってセッションに保存し、メールで送る
function verifySecondFactor(array $op): bool
{
    $otp = sprintf('%06d', random_int(0, 999999));
    $_SESSION['otp'] = [
        'operator_code' => $op['operator_code'],
        'email'         => $op['email'],
        'hash'          => password_hash($otp, PASSWORD_DEFAULT),
        'expires'       => time() + OTP_EXPIRE_MIN * 60,
        'tries'         => 0,
    ];
    if (MAIL_DRIVER === 'log') {
        $_SESSION['otp']['dev_code'] = $otp;   // 開発モードだけ画面に出す
    }
    return sendOtpMail($op['email'], $otp);
}

// otp_input.php（SC-00b）で入力されたコードを照合する
// 一致したらログイン完了で true。
// 期限切れ・5回失敗のときは $_SESSION['otp'] を消す（呼び出し側はそれを見てログイン画面へ戻す）
function verifyOtp(string $input): bool
{
    $s = $_SESSION['otp'] ?? null;
    if ($s === null || time() > $s['expires'] || $s['tries'] >= OTP_MAX_TRIES) {
        unset($_SESSION['otp']);
        return false;
    }
    $_SESSION['otp']['tries']++;
    if (!password_verify($input, $s['hash'])) {
        if ($_SESSION['otp']['tries'] >= OTP_MAX_TRIES) {
            unset($_SESSION['otp']);
        }
        return false;
    }
    $op = findOperatorForLogin($s['operator_code']);
    unset($_SESSION['otp']);
    if ($op === null) {
        return false;
    }
    completeLogin($op);
    return true;
}

// ログイン完了：セッションIDを作り直して操作者情報を保存
function completeLogin(array $op): void
{
    session_regenerate_id(true);
    $_SESSION['operator_code']     = $op['operator_code'];
    $_SESSION['operator_name']     = $op['operator_name'];
    $_SESSION['can_approve_order'] = (int)$op['can_approve_order'];
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ログイン中の操作者。created_by / updated_by / confirmed_by に使う
function currentOperator(): array
{
    return [
        'operator_code'     => $_SESSION['operator_code'] ?? '',
        'operator_name'     => $_SESSION['operator_name'] ?? '',
        'can_approve_order' => (int)($_SESSION['can_approve_order'] ?? 0),
    ];
}
