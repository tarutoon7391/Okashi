<?php
// DB接続情報・アプリ定数（05 共通部品設計書 §1）
// 税は扱わないため TAX_RATE は置かない（質問No.7）
//
// ■ 値の決まり方
//   Railway（本番）：サービスの Variables に入れた環境変数を使う
//   ローカル（XAMPP）：環境変数が無いので右側の初期値を使う
//   パスワード類はこのファイルに直接書かない（GitHub に公開されるため）

// 環境変数を読む。無ければ $default
function env(string $key, $default = null)
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

// DB（Railway の MySQL は MYSQLHOST などを自動で持っている。DB名はSQLが作る omiyage_order を使う）
define('DB_HOST', env('DB_HOST', env('MYSQLHOST', 'localhost')));
define('DB_PORT', (int)env('DB_PORT', env('MYSQLPORT', 3306)));
define('DB_NAME', env('DB_NAME', 'omiyage_order'));
define('DB_USER', env('DB_USER', env('MYSQLUSER', 'root')));
define('DB_PASS', env('DB_PASS', env('MYSQLPASSWORD', '')));

// アプリ
define('APP_NAME', '発注管理システム');
define('SHOP_NAME', env('SHOP_NAME', 'お土産処 みやげや'));   // 発注書の発注元に出す店名

// メール（mail.php）
//   MAIL_DRIVER = log    ：送信しない。ワンタイムコードは画面に表示（ローカル開発・デモ用）
//               = smtp   ：Gmail などの SMTP で送る（Railway は Pro プラン以外 SMTP がブロックされる）
//               = resend ：Resend の HTTP API で送る（Railway の無料/Hobby プランで送るならこれ）
define('MAIL_DRIVER', env('MAIL_DRIVER', 'log'));
// Resend で独自ドメインを登録していないときは onboarding@resend.dev からしか送れない
define('MAIL_FROM', env('MAIL_FROM', MAIL_DRIVER === 'resend' ? 'onboarding@resend.dev' : 'xxxx@gmail.com'));
// 設定すると、すべてのメールをこのアドレスに送る（本来の宛先は件名に付ける）。
// Resend の無料テスト送信は「Resend に登録した自分のアドレス」にしか届かないため、デモではここに総括のアドレスを入れる
define('MAIL_REDIRECT_TO', env('MAIL_REDIRECT_TO', ''));
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', APP_NAME));
define('SMTP_HOST', env('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', (int)env('SMTP_PORT', 587));
define('SMTP_USER', env('SMTP_USER', 'xxxx@gmail.com'));
define('SMTP_PASS', env('SMTP_PASS', ''));             // Gmail のアプリパスワード。ファイルに書かない
define('RESEND_API_KEY', env('RESEND_API_KEY', ''));

// 二要素認証（ワンタイムコードの有効期限：分）
define('OTP_EXPIRE_MIN', 10);
define('OTP_MAX_TRIES', 5);

// 商品写真の保存先（Railway では Volume をここにマウントする）
define('PRODUCT_IMG_DIR', __DIR__ . '/../img/product');

date_default_timezone_set('Asia/Tokyo');
