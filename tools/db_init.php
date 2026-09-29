<?php
// DB初期化（コマンドライン専用。ブラウザからは開けない）
//
//   php tools/db_init.php          … テーブルが無ければ sql/01 → 02 を流す（あれば何もしない）
//   php tools/db_init.php --test   … 上に加えて sql/03（テストデータ）も流す（テーブルを新しく作ったときだけ）
//   php tools/db_init.php --reset  … データベースを消して作り直す（01 → 02。--test も付けられる）
//
// Railway ではコンテナ起動時（docker/start.sh）に自動で実行される。
// 環境変数 SEED_TEST_DATA=1 のときは --test と同じ動きになる
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../common/config.php';

$args     = array_slice($argv, 1);
$reset    = in_array('--reset', $args, true);
$withTest = in_array('--test', $args, true) || env('SEED_TEST_DATA') === '1';

// DB名を付けずに接続（01 が CREATE DATABASE するため）。起動直後でDBが準備中のことがあるので数回待つ
$pdo = null;
for ($i = 1; $i <= 10; $i++) {
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        break;
    } catch (PDOException $e) {
        fwrite(STDERR, "[db_init] 接続待ち（{$i}/10）：{$e->getMessage()}\n");
        sleep(3);
    }
}
if ($pdo === null) {
    fwrite(STDERR, "[db_init] データベースに接続できませんでした\n");
    exit(1);
}

if ($reset) {
    $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '', DB_NAME) . '`');
    echo "[db_init] データベース " . DB_NAME . " を削除しました\n";
}

$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db AND table_name = :t');
$st->execute([':db' => DB_NAME, ':t' => 'm_operator']);
if ((int)$st->fetchColumn() > 0) {
    echo "[db_init] テーブルは作成済みです（何もしません）\n";
    exit(0);
}

$files = ['01_create_tables.sql', '02_insert_master.sql'];
if ($withTest) {
    $files[] = '03_test_data.sql';
}
foreach ($files as $file) {
    $sql = file_get_contents(__DIR__ . '/../sql/' . $file);
    // SQL ファイル中の DB 名を環境に合わせる（既定は omiyage_order）
    if (DB_NAME !== 'omiyage_order') {
        $sql = str_replace('omiyage_order', DB_NAME, $sql);
    }
    foreach (splitSql($sql) as $statement) {
        $pdo->exec($statement);
    }
    echo "[db_init] {$file} を実行しました\n";
}
echo "[db_init] 完了\n";

// SQL を文ごとに分ける（'…' の中の ; と -- は無視。-- から行末はコメント）
function splitSql(string $sql): array
{
    $statements = [];
    $buf = '';
    $inString = false;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($inString) {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                $buf .= $sql[++$i];
            } elseif ($c === "'") {
                $inString = false;
            }
            continue;
        }
        if ($c === "'") {
            $inString = true;
            $buf .= $c;
        } elseif ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            $buf .= "\n";
        } elseif ($c === ';') {
            if (trim($buf) !== '') {
                $statements[] = trim($buf);
            }
            $buf = '';
        } else {
            $buf .= $c;
        }
    }
    if (trim($buf) !== '') {
        $statements[] = trim($buf);
    }
    return $statements;
}
