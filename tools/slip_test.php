<?php
// slip.php（在庫更新）の動作確認スクリプト（コマンドライン専用・SE コマ4〜5）
//
//   php tools/slip_test.php
//
// 伝票を作って確定し、在庫が正しく動くかを確かめる。終わったら作った伝票と在庫の変化を元に戻す。
// sql/01 → 02 を流したDBで動かす（テスト用のDBで。DB_NAME=... を付けると別のDBを使える）
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../common/slip.php';

// ログイン中の操作者のふり（承認可の OP001）
$_SESSION['operator_code']     = 'OP001';
$_SESSION['operator_name']     = 'テスト';
$_SESSION['can_approve_order'] = 1;

$pdo  = getDb();
$code = 'P0001';
$ng   = 0;

function stockOf(string $code): int
{
    $st = getDb()->prepare('SELECT stock_qty FROM t_stock WHERE product_code = :code');
    $st->execute([':code' => $code]);
    return (int)$st->fetchColumn();
}

function check(string $label, $expected, $actual): void
{
    global $ng;
    $ok = $expected === $actual;
    $ng += $ok ? 0 : 1;
    echo ($ok ? '[OK] ' : '[NG] ') . $label . '（期待：' . var_export($expected, true) . ' 実際：' . var_export($actual, true) . "）\n";
}

function insertDelivery(int $orderNo, int $qty, int $isReturn): int
{
    $pdo = getDb();
    $pdo->prepare("INSERT INTO t_delivery (delivery_date, supplier_code, is_return, created_by, updated_by)
                   VALUES (CURDATE(), 'S001', :ret, 'OP001', 'OP001')")->execute([':ret' => $isReturn]);
    $no = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO t_delivery_detail (delivery_no, line_no, order_no, order_line_no, product_code, delivery_qty,
                          contract_price, amount, created_by, updated_by)
                   VALUES (:no, 1, :order_no, 1, 'P0001', :qty, 650, :amount, 'OP001', 'OP001')")
        ->execute([':no' => $no, ':order_no' => $orderNo, ':qty' => $qty, ':amount' => 650 * $qty]);
    return $no;
}

$before = stockOf($code);
echo "在庫（{$code}）開始時：{$before}\n";

// 発注伝票（テスト用）→ confirmOrder
$pdo->exec("INSERT INTO t_order (order_date, supplier_code, created_by, updated_by) VALUES (CURDATE(), 'S001', 'OP001', 'OP001')");
$orderNo = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO t_order_detail (order_no, line_no, product_code, order_qty, contract_price, amount, created_by, updated_by)
               VALUES (:no, 1, 'P0001', 7, 650, 4550, 'OP001', 'OP001')")->execute([':no' => $orderNo]);
confirmOrder($orderNo);
check('confirmOrder で在庫は動かない', $before, stockOf($code));
try {
    confirmOrder($orderNo);
    check('confirmOrder 2回目は例外', true, false);
} catch (RuntimeException $e) {
    check('confirmOrder 2回目は例外', '既に確定済みです', $e->getMessage());
}

// 納品 7 → confirmDelivery で +7
$deliveryNo = insertDelivery($orderNo, 7, 0);
confirmDelivery($deliveryNo);
check('納品確定で在庫 +7', $before + 7, stockOf($code));
try {
    confirmDelivery($deliveryNo);
    check('confirmDelivery 2回目は例外', true, false);
} catch (RuntimeException $e) {
    check('confirmDelivery 2回目は例外（在庫は変わらない）', $before + 7, stockOf($code));
}

// 返品 -2（is_return=1）→ confirmDelivery で -2
$returnNo = insertDelivery($orderNo, -2, 1);
confirmDelivery($returnNo);
check('返品伝票の確定で在庫 -2', $before + 5, stockOf($code));

// 売上 3 → confirmSales で -3
$pdo->exec("INSERT INTO t_sales (sales_date, product_code, sales_qty, amount, created_by, updated_by)
            VALUES (CURDATE(), 'P0001', 3, 2940, 'OP001', 'OP001')");
$salesNo = (int)$pdo->lastInsertId();
confirmSales($salesNo);
check('売上確定で在庫 -3', $before + 2, stockOf($code));

// 棚卸：実数で上書き
$actual = $before + 1;
check('棚卸（差異あり）は記録される', true, applyStocktaking($code, $actual, 'テスト'));
check('棚卸で在庫が実数になる', $actual, stockOf($code));
check('棚卸（差異0）は記録されない', false, applyStocktaking($code, $actual, 'テスト'));

// 後片付け：作った伝票を消して在庫を開始時に戻す（テストスクリプトだけの特別扱い）
$pdo->prepare('DELETE FROM t_stocktaking WHERE product_code = :code AND reason = :r')->execute([':code' => $code, ':r' => 'テスト']);
$pdo->prepare('DELETE FROM t_sales WHERE sales_no = :no')->execute([':no' => $salesNo]);
$pdo->prepare('DELETE FROM t_delivery_detail WHERE delivery_no IN (:a, :b)')->execute([':a' => $deliveryNo, ':b' => $returnNo]);
$pdo->prepare('DELETE FROM t_delivery WHERE delivery_no IN (:a, :b)')->execute([':a' => $deliveryNo, ':b' => $returnNo]);
$pdo->prepare('DELETE FROM t_order_detail WHERE order_no = :no')->execute([':no' => $orderNo]);
$pdo->prepare('DELETE FROM t_order WHERE order_no = :no')->execute([':no' => $orderNo]);
$pdo->prepare('UPDATE t_stock SET stock_qty = :qty WHERE product_code = :code')->execute([':qty' => $before, ':code' => $code]);
echo "後片付け済み（在庫 {$code}：" . stockOf($code) . "）\n";

echo $ng === 0 ? "すべてOK\n" : "NG {$ng} 件\n";
exit($ng === 0 ? 0 : 1);
