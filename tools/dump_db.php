<?php
// データベースの中身を INSERT 文で書き出す（コマンドライン専用。mysqldump が無い Railway のコンテナ内でも使える）
//
//   php tools/dump_db.php > backup/omiyage_order_YYYYMMDD.sql
//   Railway：railway ssh --service web -- php tools/dump_db.php > backup/omiyage_order_YYYYMMDD.sql
//
// 戻すときは、空のDB（01_create_tables.sql を流した直後）にこのファイルを流す
// ※ 操作者のメールアドレス等が入るので、GitHub など公開の場所に置かないこと（backup/ は .gitignore 済み）
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../common/db.php';

$pdo    = getDb();
$tables = ['m_operator', 'm_supplier', 'm_product', 't_stock', 't_order', 't_order_detail',
           't_delivery', 't_delivery_detail', 't_sales', 't_stocktaking'];

echo "-- " . DB_NAME . " のバックアップ（" . date('Y-m-d H:i:s') . "）\n";
echo "USE `" . DB_NAME . "`;\nSET FOREIGN_KEY_CHECKS = 0;\n";
foreach ($tables as $t) {
    $rows = $pdo->query("SELECT * FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
    echo "\n-- {$t}：" . count($rows) . " 行\n";
    foreach ($rows as $row) {
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($row)));
        $vals = implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row)));
        echo "INSERT INTO `{$t}` ({$cols}) VALUES ({$vals});\n";
    }
}
echo "SET FOREIGN_KEY_CHECKS = 1;\n";
