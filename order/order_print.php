<?php
// SC-12 発注書（F-12）担当B　PDF帳票（質問No.6）
//   ?order_no=3,4        … 確定した伝票の発注書一覧（メール送信状況・再送ボタン・PDF・検品一覧表へ）
//   ?order_no=3&pdf=1    … 発注書PDFを表示
//   パラメータなし        … 最近確定した発注伝票の一覧
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/query.php';
require_once __DIR__ . '/../common/mail.php';
require_once __DIR__ . '/../common/pdf.php';
requireLogin();
$pdo = getDb();

$orderNos = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['order_no'] ?? '')))));

// 再送
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderNo = postInt('order_no');
    if (sendOrderMail($orderNo)) {
        setFlash('success', "伝票No.{$orderNo} の発注書をメールで送信しました");
    } else {
        setFlash('warning', "伝票No.{$orderNo} の発注書を送れませんでした（発注先メール未登録または送信失敗）");
    }
    $back = postStr('back');
    redirect('/order/order_print.php' . ($back !== '' ? '?order_no=' . rawurlencode($back) : ''));
}

// PDF
if (isset($_GET['pdf']) && count($orderNos) === 1) {
    $slip = getOrderSlip($orderNos[0]);
    if ($slip === null || (int)$slip['is_confirmed'] !== 1) {
        setFlash('error', '確定済みの発注伝票が見つかりません');
        redirect('/order/order_print.php');
    }
    outputPdf('発注書', buildOrderSheetHtml($slip, 'order'), "order_{$slip['order_no']}.pdf", 'I');
}

// 画面
if ($orderNos) {
    $in = implode(',', array_fill(0, count($orderNos), '?'));
    $st = $pdo->prepare(
        "SELECT o.order_no, o.order_date, o.supplier_code, s.supplier_name, s.order_email, o.confirmed_at, o.mailed_at,
                (SELECT SUM(amount) FROM t_order_detail d WHERE d.order_no = o.order_no) AS total
           FROM t_order o JOIN m_supplier s ON s.supplier_code = o.supplier_code
          WHERE o.is_confirmed = 1 AND o.order_no IN ($in) ORDER BY o.order_no"
    );
    $st->execute($orderNos);
} else {
    $st = $pdo->query(
        'SELECT o.order_no, o.order_date, o.supplier_code, s.supplier_name, s.order_email, o.confirmed_at, o.mailed_at,
                (SELECT SUM(amount) FROM t_order_detail d WHERE d.order_no = o.order_no) AS total
           FROM t_order o JOIN m_supplier s ON s.supplier_code = o.supplier_code
          WHERE o.is_confirmed = 1 ORDER BY o.confirmed_at DESC, o.order_no DESC LIMIT 30'
    );
}
$slips = $st->fetchAll();

$pageTitle = '発注書';
require_once __DIR__ . '/../common/header.php';
?>
<?php if (MAIL_DRIVER === 'log'): ?>
  <p class="dev-note">開発モード（MAIL_DRIVER=log）のため、メールは実際には送られず送信日時だけ記録されます。</p>
<?php endif; ?>
<?php if (!$slips): ?>
  <p>確定済みの発注伝票はありません。</p>
<?php else: ?>
<table class="data-table">
  <thead>
    <tr><th>伝票No</th><th>発注日</th><th>卸業者</th><th>合計金額</th><th>確定日時</th><th>メール送信</th><th>帳票</th></tr>
  </thead>
  <tbody>
    <?php foreach ($slips as $s): ?>
    <tr>
      <td class="num"><?= h($s['order_no']) ?></td>
      <td><?= h(formatDate($s['order_date'])) ?></td>
      <td><?= h($s['supplier_code'] . ' ' . $s['supplier_name']) ?></td>
      <td class="num <?= minusClass($s['total']) ?>"><?= h(formatYen($s['total'])) ?></td>
      <td><?= h($s['confirmed_at']) ?></td>
      <td>
        <?php if ($s['mailed_at'] !== null): ?>
          <span class="status-confirmed">送信済</span> <?= h($s['mailed_at']) ?>
        <?php elseif (!$s['order_email']): ?>
          <span class="error-text">発注先メール未登録</span>
        <?php else: ?>
          <span class="status-unconfirmed">未送信</span>
        <?php endif; ?>
        <?php if ($s['order_email']): ?>
        <form method="post" class="inline-form" data-confirm="<?= h($s['supplier_name']) ?> へ発注書を<?= $s['mailed_at'] ? '再' : '' ?>送信します。よろしいですか？">
          <input type="hidden" name="order_no" value="<?= h($s['order_no']) ?>">
          <input type="hidden" name="back" value="<?= h(implode(',', $orderNos)) ?>">
          <button type="submit" class="btn btn-small"><?= $s['mailed_at'] ? '再送' : '送信' ?></button>
        </form>
        <?php endif; ?>
      </td>
      <td>
        <a href="/order/order_print.php?order_no=<?= h($s['order_no']) ?>&amp;pdf=1" target="_blank" class="btn btn-small">発注書PDF</a>
        <a href="/order/inspection_print.php?order_no=<?= h($s['order_no']) ?>" target="_blank" class="btn btn-small">検品一覧表へ</a>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<div class="btn-area">
  <?php if (currentOperator()['can_approve_order'] === 1): ?>
  <a href="/order/order_confirm.php" class="btn">発注確定へ戻る</a>
  <?php endif; ?>
  <a href="/menu.php" class="btn">メニューへ戻る</a>
</div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
