<?php
// 画面ファイルの標準テンプレート（01 命名規則 §4.3）
// 使い方：このファイルを担当フォルダ（order/ delivery/ など）にコピーし、
//         <業務>_<動作>.php にリネームしてから書き始める。
//         （例：order/order_input.php）
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
// require_once __DIR__ . '/../common/slip.php';    // 伝票の確定・在庫を動かす画面だけ
// require_once __DIR__ . '/../common/query.php';   // 未納品一覧・マスタの選択肢などを使う画面だけ
requireLogin();                 // 発注確定画面だけは requireApprover(); にする
$pdo = getDb();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. 入力値の取得・バリデーション（postStr() / postInt() / postArray() を使う）
    // 2. DB更新（try / catch で例外処理。在庫は slip.php の関数経由）
    // 3. 完了メッセージをセッションに入れて redirect()
    //
    // if (!$errors) {
    //     try {
    //         $pdo->beginTransaction();
    //         // INSERT ...
    //         $pdo->commit();
    //         setFlash('success', '登録しました');
    //         redirect('/order/order_input.php');
    //     } catch (Throwable $e) {
    //         $pdo->rollBack();
    //         $errors[] = '登録に失敗しました：' . $e->getMessage();
    //     }
    // }
    // エラーのときは入力値を残したまま再表示する（メッセージは header.php が出す）
    // setFlash('error', implode("\n", $errors));
}

// GET時：表示用データの取得
$pageTitle = '画面名';          // ← 画面一覧（02）の画面名にする
// $pageScript = 'order_input.js';   // 画面固有のJSがあるときだけ
require_once __DIR__ . '/../common/header.php';
?>
<!-- 画面本体 -->

<?php require_once __DIR__ . '/../common/footer.php'; ?>
