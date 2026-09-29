<?php
// PDO接続を1回だけ作って返す（05 §2）
// 各画面で new PDO しないこと。必ず getDb() を使う
require_once __DIR__ . '/config.php';

function getDb(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Railway の MySQL は UTC なので、NOW() / CURRENT_TIMESTAMP を日本時間にそろえる
        $pdo->exec("SET time_zone = '+09:00'");
    }
    return $pdo;
}
