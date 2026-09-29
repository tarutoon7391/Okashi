<?php
// SC-11 発注確定（F-11）担当B　※発注承認可の操作者のみ（質問No.5）
// チェックした伝票を confirmOrder() で確定 → 伝票ごとに sendOrderMail() で発注書PDFを卸業者へ送る
// → SC-12 発注書へ。メール送信に失敗しても確定は取り消さない（warning）
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/slip.php';
require_once __DIR__ . '/../common/mail.php';
requireApprover();
$pdo = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderNos = array_values(array_unique(array_filter(array_map('intval', postArray('order_no')))));
    if (!$orderNos) {
        setFlash('error', '確定する伝票を選んでください');
        redirect('/order/order_confirm.php');
    }
    $confirmed = [];
    $messages  = [];
    foreach ($orderNos as $orderNo) {
        try {
            confirmOrder($orderNo);
            $confirmed[] = $orderNo;
        } catch (Throwable $e) {
            $messages[] = "伝票No.{$orderNo}：" . $e->getMessage();
        }
    }
    foreach ($confirmed as $orderNo) {
        if (!sendOrderMail($orderNo)) {
            $messages[] = "伝票No.{$orderNo}：発注書メールを送れませんでした（発注先メール未登録または送信失敗）。発注書画面から再送してください";
        }
    }
    if (!$confirmed) {
        setFlash('error', implode("\n", $messages));
        redirect('/order/order_confirm.php');
    }
    $done = count($confirmed) . '件の発注伝票を確定しました（No.' . implode(', ', $confirmed) . '）';
    setFlash($messages ? 'warning' : 'success', $messages ? $done . "\n" . implode("\n", $messages) : $done);
    redirect('/order/order_print.php?order_no=' . implode(',', $confirmed));
}

// GET時：未確定の発注伝票一覧（明細つき）
$slips = $pdo->query(
    'SELECT o.order_no, o.order_date, o.supplier_code, s.supplier_name, s.order_email, o.created_by
       FROM t_order o JOIN m_supplier s ON s.supplier_code = o.supplier_code
      WHERE o.is_confirmed = 0
      ORDER BY o.order_date, o.order_no'
)->fetchAll();
$st = $pdo->prepare(
    'SELECT d.line_no, d.product_code, p.product_name, p.spec, p.storage_type, d.order_qty, d.amount, d.memo,
            d.ref_order_no, d.ref_line_no
       FROM t_order_detail d JOIN m_product p ON p.product_code = d.product_code
      WHERE d.order_no = :no ORDER BY d.line_no'
);
foreach ($slips as &$slip) {
    $st->execute([':no' => $slip['order_no']]);
    $slip['details'] = $st->fetchAll();
    $slip['total']   = array_sum(array_column($slip['details'], 'amount'));
}
unset($slip);

$pageTitle = '発注確定';
require_once __DIR__ . '/../common/header.php';
?>
<?php if (!$slips): ?>
  <p>未確定の発注伝票はありません。</p>
<?php else: ?>
<form method="post" data-confirm="チェックした発注伝票を確定します。確定後は変更できず、卸業者へ発注書をメールで送ります。よろしいですか？">
  <table class="data-table">
    <thead>
      <tr><th><input type="checkbox" class="js-check-all" title="すべて選択"></th><th>伝票No</th><th>発注日</th><th>卸業者</th><th>明細</th><th>合計金額</th><th>発注先メール</th><th>状態</th></tr>
    </thead>
    <tbody>
      <?php foreach ($slips as $slip): ?>
      <tr>
        <td><input type="checkbox" name="order_no[]" value="<?= h($slip['order_no']) ?>" class="js-check"></td>
        <td class="num"><?= h($slip['order_no']) ?></td>
        <td><?= h(formatDate($slip['order_date'])) ?></td>
        <td><?= h($slip['supplier_code'] . ' ' . $slip['supplier_name']) ?></td>
        <td>
          <table class="inner-table">
            <?php foreach ($slip['details'] as $d): ?>
            <tr class="<?= minusClass($d['order_qty']) ?>">
              <td><?= h($d['product_code']) ?></td>
              <td><?= h($d['product_name'] . ' ' . $d['spec']) ?><?= storageBadge($d['storage_type']) ?>
                <?php if ($d['ref_order_no'] !== null): ?><small>（取消元 No.<?= h($d['ref_order_no'] . '-' . $d['ref_line_no']) ?>）</small><?php endif; ?>
              </td>
              <td class="num"><?= h($d['order_qty']) ?></td>
              <td class="num"><?= h(formatYen($d['amount'])) ?></td>
              <td><?= h($d['memo']) ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        </td>
        <td class="num <?= minusClass($slip['total']) ?>"><?= h(formatYen($slip['total'])) ?></td>
        <td><?= $slip['order_email'] ? h($slip['order_email']) : '<span class="error-text">未登録（送信されません）</span>' ?></td>
        <?= statusCell(0) ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="btn-area">
    <button type="submit" class="btn btn-danger">確定</button>
    <a href="/menu.php" class="btn">メニューへ戻る</a>
  </div>
</form>
<?php endif; ?>
<h2>確定済みの発注書</h2>
<p><a href="/order/order_print.php">最近確定した発注書の一覧へ</a></p>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
