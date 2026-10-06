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

$totalAmount = array_sum(array_column($slips, 'total'));
$returnCount = count(array_filter($slips, fn($s) => (int)$s['is_return'] === 1));

$pageTitle = '納品確定';
require_once __DIR__ . '/../common/header.php';
renderTabs([
    ['href' => '/delivery/delivery_input.php',          'label' => '① 納品入力'],
    ['href' => '/delivery/delivery_confirm.php',        'label' => '② 納品確定'],
    ['href' => '/return_goods/return_goods_input.php',  'label' => '返品伝票入力'],
    ['href' => '/delivery/undelivered_print.php',       'label' => '未納品一覧表'],
]);
?>
<div class="cards">
  <?= statCard('未確定の伝票', h(count($slips)) . '枚') ?>
  <?= statCard('うち返品伝票', h($returnCount) . '枚') ?>
  <?= statCard('未確定の金額', h(formatYen($totalAmount))) ?>
</div>
<?php if (!$slips): ?>
  <div class="card form-card"><p class="hint" style="margin:0">未確定の納品伝票・返品伝票はありません。<a href="/delivery/delivery_input.php">納品入力</a>・<a href="/return_goods/return_goods_input.php">返品伝票入力</a>で登録した伝票がここに出ます。</p></div>
  <div class="action-bar"><div class="bar-in">
    <span class="bar-spacer"></span>
    <a href="/delivery/delivery_input.php" class="btn">納品入力へ</a>
    <a href="/menu.php" class="btn">メニューへ戻る</a>
  </div></div>
<?php else: ?>
<p class="hint">確定する伝票を選んでください。確定すると在庫が更新され（納品は増・返品/訂正は減）、あとから変更できません。</p>
<form method="post" data-confirm="チェックした納品伝票を確定し、在庫を更新します。確定後は変更できません。よろしいですか？">
  <p class="note"><label><input type="checkbox" class="js-check-all"> すべての伝票を選択</label></p>
  <?php foreach ($slips as $i => $slip): $isReturn = (int)$slip['is_return'] === 1; $qty = array_sum(array_column($slip['details'], 'delivery_qty')); ?>
  <div class="card slip-card" data-n="<?= count($slip['details']) ?>" data-q="<?= h($qty) ?>" data-a="<?= h($slip['total']) ?>">
    <label class="slip-head <?= $isReturn ? 'row-return' : '' ?>">
      <input type="checkbox" name="delivery_no[]" value="<?= h($slip['delivery_no']) ?>" class="js-check" aria-label="伝票No.<?= h($slip['delivery_no']) ?>を選択">
      <span class="slip-title">No.<?= h($slip['delivery_no']) ?>　<?= h($slip['supplier_name']) ?>
        <?= $isReturn ? '<span class="st st-return">返品</span>' : '<span class="st st-none">納品</span>' ?> <?= statusPill(0) ?>
        <small><?= h(formatDate($slip['delivery_date'])) ?></small></span>
      <span class="slip-sum">
        <span><small>明細</small><b><?= count($slip['details']) ?>件</b></span>
        <span><small>数量合計</small><b class="<?= $qty < 0 ? 'neg' : '' ?>"><?= h($qty) ?></b></span>
        <span><small>合計金額</small><b class="<?= (int)$slip['total'] < 0 ? 'neg' : '' ?>"><?= h(formatYen($slip['total'])) ?></b></span>
      </span>
    </label>
    <details<?= $i === 0 ? ' open' : '' ?>>
      <summary>明細を見る</summary>
      <div class="tbl-scroll">
        <table class="data-table compact">
          <thead><tr><th>発注No</th><th>商品コード</th><th>商品名</th><th class="num">数量</th><th class="num">金額</th><th>メモ</th></tr></thead>
          <tbody>
            <?php foreach ($slip['details'] as $d): ?>
            <tr class="<?= minusClass($d['delivery_qty']) ?>">
              <td><?= h($d['order_no'] . '-' . $d['order_line_no']) ?></td>
              <td><?= h($d['product_code']) ?></td>
              <td><?= h($d['product_name'] . ' ' . $d['spec']) ?><?= storageBadge($d['storage_type']) ?></td>
              <td class="num"><?= h($d['delivery_qty']) ?></td>
              <td class="num"><?= h(formatYen($d['amount'])) ?></td>
              <td><?= h($d['memo']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
    <div class="slip-foot">
      <span style="margin-left:auto"></span>
      <button type="submit" form="deleteForm<?= h($slip['delivery_no']) ?>" class="btn btn-small">この伝票を削除</button>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="action-bar"><div class="bar-in">
    <div class="bar-sum">
      <span><small>選択</small><b id="selCount">0</b>枚</span>
      <span><small>明細</small><b id="selLines">0件</b></span>
      <span><small>数量</small><b id="selQty">0</b></span>
      <span><small>金額</small><b id="selAmount">¥0</b></span>
    </div>
    <button type="submit" class="btn btn-danger" id="btnConfirm" disabled>選択した伝票を確定する</button>
    <a href="/delivery/delivery_input.php" class="btn">納品入力へ</a>
    <a href="/menu.php" class="btn">メニューへ戻る</a>
  </div></div>
</form>
<?php // 削除ボタン用のフォーム（確定フォームの中に form は入れられないので外に置き、ボタンの form 属性で指す） ?>
<?php foreach ($slips as $slip): ?>
<form method="post" id="deleteForm<?= h($slip['delivery_no']) ?>" class="inline-form" data-confirm="<?= h(((int)$slip['is_return'] === 1 ? '返品伝票' : '納品伝票') . 'No.' . $slip['delivery_no']) ?> を削除します。よろしいですか？">
  <input type="hidden" name="mode" value="delete">
  <input type="hidden" name="delivery_no" value="<?= h($slip['delivery_no']) ?>">
</form>
<?php endforeach; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
