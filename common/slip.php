<?php
// 伝票確定・在庫更新（05 §5）
// t_stock を更新するコードはこのファイルにしか書かない。各画面は下の関数を呼ぶだけ。
// すべてトランザクション内で動き、失敗したら例外を投げてロールバックする。
//
// 在庫が動くのは 納品確定（＋）・売上確定（−）・棚卸（実数で上書き）の3か所だけ（04 ER概要）
// 返品は納品のマイナス伝票なので confirmDelivery で確定する。applyReturn は作らない（質問No.4）
require_once __DIR__ . '/auth.php';

// 発注確定。在庫は動かさない。requireApprover() 済みが前提
// 確定後に画面側で sendOrderMail() を呼ぶ（送信失敗でも確定は取り消さない）
function confirmOrder(int $orderNo): void
{
    $pdo = getDb();
    $op  = currentOperator();
    if ($op['can_approve_order'] !== 1) {
        throw new RuntimeException('発注確定の権限がありません');
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT is_confirmed FROM t_order WHERE order_no = :no FOR UPDATE');
        $st->execute([':no' => $orderNo]);
        $row = $st->fetch();
        if ($row === false) {
            throw new RuntimeException('伝票が存在しません');
        }
        if ((int)$row['is_confirmed'] === 1) {
            throw new RuntimeException('既に確定済みです');
        }
        $st = $pdo->prepare('UPDATE t_order SET is_confirmed = 1, confirmed_at = NOW(), confirmed_by = :by,
                             updated_by = :by2 WHERE order_no = :no');
        $st->execute([':by' => $op['operator_code'], ':by2' => $op['operator_code'], ':no' => $orderNo]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// 納品確定。明細ごとに在庫を +delivery_qty
// 返品伝票（is_return=1、数量マイナス）も同じ関数で確定し、在庫が減る
function confirmDelivery(int $deliveryNo): void
{
    $pdo = getDb();
    $op  = currentOperator();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT is_confirmed FROM t_delivery WHERE delivery_no = :no FOR UPDATE');
        $st->execute([':no' => $deliveryNo]);
        $row = $st->fetch();
        if ($row === false) {
            throw new RuntimeException('伝票が存在しません');
        }
        if ((int)$row['is_confirmed'] === 1) {
            throw new RuntimeException('既に確定済みです');
        }
        $st = $pdo->prepare('SELECT product_code, delivery_qty FROM t_delivery_detail WHERE delivery_no = :no');
        $st->execute([':no' => $deliveryNo]);
        foreach ($st->fetchAll() as $d) {
            addStock($d['product_code'], (int)$d['delivery_qty'], $op['operator_code']);
        }
        $st = $pdo->prepare('UPDATE t_delivery SET is_confirmed = 1, confirmed_at = NOW(), confirmed_by = :by,
                             updated_by = :by2 WHERE delivery_no = :no');
        $st->execute([':by' => $op['operator_code'], ':by2' => $op['operator_code'], ':no' => $deliveryNo]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// 売上確定。在庫を −sales_qty（訂正行でマイナスなら在庫が戻る）
function confirmSales(int $salesNo): void
{
    $pdo = getDb();
    $op  = currentOperator();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT product_code, sales_qty, is_confirmed FROM t_sales WHERE sales_no = :no FOR UPDATE');
        $st->execute([':no' => $salesNo]);
        $row = $st->fetch();
        if ($row === false) {
            throw new RuntimeException('売上が存在しません');
        }
        if ((int)$row['is_confirmed'] === 1) {
            throw new RuntimeException('既に確定済みです');
        }
        addStock($row['product_code'], -(int)$row['sales_qty'], $op['operator_code']);
        $st = $pdo->prepare('UPDATE t_sales SET is_confirmed = 1, confirmed_at = NOW(), confirmed_by = :by,
                             updated_by = :by2 WHERE sales_no = :no');
        $st->execute([':by' => $op['operator_code'], ':by2' => $op['operator_code'], ':no' => $salesNo]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// 棚卸。現在庫を帳簿数として t_stocktaking に記録し、在庫を実数で上書きする
// 差異0なら何もしないで false、記録したら true。$date を省略すると今日
function applyStocktaking(string $productCode, int $actualQty, string $reason, ?string $date = null): bool
{
    $pdo = getDb();
    $op  = currentOperator();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT stock_qty FROM t_stock WHERE product_code = :code FOR UPDATE');
        $st->execute([':code' => $productCode]);
        $row = $st->fetch();
        $bookQty = $row === false ? 0 : (int)$row['stock_qty'];
        $diff = $actualQty - $bookQty;
        if ($diff === 0) {
            $pdo->commit();
            return false;
        }
        $st = $pdo->prepare('INSERT INTO t_stocktaking
                                 (stocktaking_date, product_code, book_qty, actual_qty, diff_qty, reason, created_by, updated_by)
                             VALUES (:date, :code, :book, :actual, :diff, :reason, :by, :by2)');
        $st->execute([
            ':date'   => $date ?? date('Y-m-d'),
            ':code'   => $productCode,
            ':book'   => $bookQty,
            ':actual' => $actualQty,
            ':diff'   => $diff,
            ':reason' => $reason,
            ':by'     => $op['operator_code'],
            ':by2'    => $op['operator_code'],
        ]);
        addStock($productCode, $diff, $op['operator_code']);   // 帳簿数＋差異＝実数
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// 在庫がマイナスになっている商品（確定後の警告表示用。04 t_stock：マイナスは警告して更新は許可）
function getMinusStockProducts(array $productCodes): array
{
    if (!$productCodes) {
        return [];
    }
    $in = implode(',', array_fill(0, count($productCodes), '?'));
    $st = getDb()->prepare("SELECT s.product_code, p.product_name, s.stock_qty
                              FROM t_stock s JOIN m_product p ON p.product_code = s.product_code
                             WHERE s.stock_qty < 0 AND s.product_code IN ($in)");
    $st->execute(array_values($productCodes));
    return $st->fetchAll();
}

// 内部関数：在庫を $delta だけ増減する。行が無ければ INSERT
// 呼び出し元のトランザクションの中で使う。各画面から直接呼ばない
function addStock(string $productCode, int $delta, string $by): void
{
    $pdo = getDb();
    $st  = $pdo->prepare('SELECT stock_qty FROM t_stock WHERE product_code = :code FOR UPDATE');
    $st->execute([':code' => $productCode]);
    if ($st->fetch() === false) {
        $st = $pdo->prepare('INSERT INTO t_stock (product_code, stock_qty, created_by, updated_by)
                             VALUES (:code, :delta, :by, :by2)');
        $st->execute([':code' => $productCode, ':delta' => $delta, ':by' => $by, ':by2' => $by]);
        return;
    }
    $st = $pdo->prepare('UPDATE t_stock SET stock_qty = stock_qty + :delta, updated_by = :by
                         WHERE product_code = :code');
    $st->execute([':delta' => $delta, ':by' => $by, ':code' => $productCode]);
}
