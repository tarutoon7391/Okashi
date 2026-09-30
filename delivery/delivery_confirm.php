<?php
// SC-21 納品確定（F-21）担当C
// 未確定の納品伝票（返品伝票を含む。返品は .row-return で色分け）を confirmDelivery() で確定
// → 在庫が増える（返品・マイナス納品は減る）→ SC-22 未納品一覧表へ
// 未確定の伝票は「削除」できる（入力間違いのやり直し用）。確定済みは削除しない（訂正はマイナス数量の新規伝票）
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/query.php';
require_once __DIR__ . '/../common/slip.php';
requireLogin();
$pdo = getDb();

// 削除（未確定の伝票1枚。明細 → ヘッダの順に DELETE）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && postStr('mode') === 'delete') {
    $no = postInt('delivery_no');
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT is_confirmed, is_return FROM t_delivery WHERE delivery_no = :no FOR UPDATE');
        $st->execute([':no' => $no]);
        $row = $st->fetch();
        if ($row === false || (int)$row['is_confirmed'] === 1) {
            $pdo->rollBack();
            setFlash('error', "伝票No.{$no}：削除できません（存在しないか、既に確定済みです）");
            redirect('/delivery/delivery_confirm.php');
        }
        // 納品入力・返品入力の残数チェックと順番を合わせるため、対象の発注明細もロックする
        $st = $pdo->prepare("SELECT CONCAT(order_no, '-', order_line_no) FROM t_delivery_detail WHERE delivery_no = :no");
        $st->execute([':no' => $no]);
        getOrderDetailsForUpdate($st->fetchAll(PDO::FETCH_COLUMN));
        $pdo->prepare('DELETE FROM t_delivery_detail WHERE delivery_no = :no')->execute([':no' => $no]);
        $pdo->prepare('DELETE FROM t_delivery WHERE delivery_no = :no AND is_confirmed = 0')->execute([':no' => $no]);
        $pdo->commit();
        setFlash('success', ((int)$row['is_return'] === 1 ? '返品伝票' : '納品伝票') . "No.{$no} を削除しました");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[delivery_confirm] 削除失敗 No.' . $no . '：' . $e->getMessage());
        setFlash('error', "伝票No.{$no} の削除に失敗しました。もう一度やり直してください");
    }
    redirect('/delivery/delivery_confirm.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deliveryNos = array_values(array_unique(array_filter(array_map('intval', postArray('delivery_no')))));
    if (!$deliveryNos) {
        setFlash('error', '確定する伝票を選んでください');
        redirect('/delivery/delivery_confirm.php');
    }
    $confirmed = [];
    $messages  = [];
    foreach ($deliveryNos as $no) {
        try {
            confirmDelivery($no);
            $confirmed[] = $no;
        } catch (Throwable $e) {
            // 「既に確定済みです」など slip.php が投げる業務エラー（RuntimeException）だけ画面に出す
            error_log('[delivery_confirm] 確定失敗 No.' . $no . '：' . $e->getMessage());
            $messages[] = "伝票No.{$no}：" . ($e instanceof RuntimeException ? $e->getMessage() : '確定に失敗しました');
        }
    }
    if (!$confirmed) {
        setFlash('error', implode("\n", $messages));
        redirect('/delivery/delivery_confirm.php');
    }
    // 在庫がマイナスになった商品は警告（更新は許可：04 t_stock）
    $in = implode(',', array_fill(0, count($confirmed), '?'));
    $st = $pdo->prepare("SELECT DISTINCT product_code FROM t_delivery_detail WHERE delivery_no IN ($in)");
    $st->execute($confirmed);
    foreach (getMinusStockProducts($st->fetchAll(PDO::FETCH_COLUMN)) as $m) {
        $messages[] = "在庫がマイナスです：{$m['product_code']} {$m['product_name']}（{$m['stock_qty']}）";
    }
    $done = count($confirmed) . '件の納品伝票を確定し、在庫を更新しました（No.' . implode(', ', $confirmed) . '）';
    setFlash($messages ? 'warning' : 'success', $messages ? $done . "\n" . implode("\n", $messages) : $done);
    redirect('/delivery/undelivered_print.php');
}

// GET時：未確定の納品伝票一覧（明細つき）
$slips = $pdo->query(
    'SELECT d.delivery_no, d.delivery_date, d.supplier_code, s.supplier_name, d.is_return
       FROM t_delivery d JOIN m_supplier s ON s.supplier_code = d.supplier_code
      WHERE d.is_confirmed = 0
      ORDER BY d.delivery_date, d.delivery_no'
)->fetchAll();
$st = $pdo->prepare(
    'SELECT dd.line_no, dd.order_no, dd.order_line_no, dd.product_code, p.product_name, p.spec, p.storage_type,
            dd.delivery_qty, dd.amount, dd.memo
       FROM t_delivery_detail dd JOIN m_product p ON p.product_code = dd.product_code
      WHERE dd.delivery_no = :no ORDER BY dd.line_no'
);
foreach ($slips as &$slip) {
    $st->execute([':no' => $slip['delivery_no']]);
    $slip['details'] = $st->fetchAll();
    $slip['total']   = array_sum(array_column($slip['details'], 'amount'));
}
unset($slip);

$pageTitle = '納品確定';
require_once __DIR__ . '/../common/header.php';
?>
<?php if (!$slips): ?>
  <p>未確定の納品伝票はありません。</p>
<?php else: ?>
<form method="post" data-confirm="チェックした納品伝票を確定し、在庫を更新します。確定後は変更できません。よろしいですか？">
  <table class="data-table">
    <thead>
      <tr><th><input type="checkbox" class="js-check-all" title="すべて選択"></th><th>伝票No</th><th>区分</th><th>納品日</th><th>卸業者</th><th>明細</th><th>合計金額</th><th>状態</th><th>削除</th></tr>
    </thead>
    <tbody>
      <?php foreach ($slips as $slip): ?>
      <tr class="<?= (int)$slip['is_return'] === 1 ? 'row-return' : '' ?>">
        <td><input type="checkbox" name="delivery_no[]" value="<?= h($slip['delivery_no']) ?>" class="js-check"></td>
        <td class="num"><?= h($slip['delivery_no']) ?></td>
        <td><?= (int)$slip['is_return'] === 1 ? '返品' : '納品' ?></td>
        <td><?= h(formatDate($slip['delivery_date'])) ?></td>
        <td><?= h($slip['supplier_code'] . ' ' . $slip['supplier_name']) ?></td>
        <td>
          <table class="inner-table">
            <?php foreach ($slip['details'] as $d): ?>
            <tr class="<?= minusClass($d['delivery_qty']) ?>">
              <td><small>発注<?= h($d['order_no'] . '-' . $d['order_line_no']) ?></small></td>
              <td><?= h($d['product_code'] . ' ' . $d['product_name'] . ' ' . $d['spec']) ?><?= storageBadge($d['storage_type']) ?></td>
              <td class="num"><?= h($d['delivery_qty']) ?></td>
              <td class="num"><?= h(formatYen($d['amount'])) ?></td>
              <td><?= h($d['memo']) ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        </td>
        <td class="num <?= minusClass($slip['total']) ?>"><?= h(formatYen($slip['total'])) ?></td>
        <?= statusCell(0) ?>
        <td><button type="submit" form="deleteForm<?= h($slip['delivery_no']) ?>" class="btn btn-small btn-danger">削除</button></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="btn-area">
    <button type="submit" class="btn btn-danger">確定</button>
    <a href="/menu.php" class="btn">メニューへ戻る</a>
  </div>
</form>
<?php // 削除ボタン用のフォーム（確定フォームの中に form は入れられないので外に置き、ボタンの form 属性で指す） ?>
<?php foreach ($slips as $slip): ?>
<form method="post" id="deleteForm<?= h($slip['delivery_no']) ?>" class="inline-form" data-confirm="<?= h(((int)$slip['is_return'] === 1 ? '返品伝票' : '納品伝票') . 'No.' . $slip['delivery_no']) ?> を削除します。よろしいですか？">
  <input type="hidden" name="mode" value="delete">
  <input type="hidden" name="delivery_no" value="<?= h($slip['delivery_no']) ?>">
</form>
<?php endforeach; ?>
<?php endif; ?>
<p><a href="/delivery/delivery_input.php">納品入力へ</a> ／ <a href="/return_goods/return_goods_input.php">返品伝票へ</a> ／ <a href="/delivery/undelivered_print.php">未納品一覧表へ</a></p>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
