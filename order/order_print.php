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
        // 開発モード（MAIL_DRIVER=log）では実際には送っていないことが分かるようにする
        setFlash('success', "伝票No.{$orderNo} の発注書をメールで送信しました"
            . (isMailActuallySent() ? '' : '（開発モードのため実際には送信していません）'));
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

// 画面（起票者・確定者の名前も出す）
$slipSql = 'SELECT o.order_no, o.order_date, o.supplier_code, s.supplier_name, s.order_email, o.confirmed_at, o.mailed_at,
                   o.created_by, o.confirmed_by, oc.operator_name AS created_name, ocf.operator_name AS confirmed_name,
                   (SELECT SUM(amount) FROM t_order_detail d WHERE d.order_no = o.order_no) AS total
              FROM t_order o
              JOIN m_supplier s       ON s.supplier_code = o.supplier_code
              LEFT JOIN m_operator oc ON oc.operator_code = o.created_by
              LEFT JOIN m_operator ocf ON ocf.operator_code = o.confirmed_by
             WHERE o.is_confirmed = 1';
if ($orderNos) {
    $in = implode(',', array_fill(0, count($orderNos), '?'));
    $st = $pdo->prepare($slipSql . " AND o.order_no IN ($in) ORDER BY o.order_no");
    $st->execute($orderNos);
} else {
    $st = $pdo->query($slipSql . ' ORDER BY o.confirmed_at DESC, o.order_no DESC LIMIT 30');
}
$slips = $st->fetchAll();

// 明細。プラスの行には、この行を取消元にした取消数（確定済み／未確定）を出す（取消されたことが分かるように）
$details = [];
if ($slips) {
    $nos = array_map('intval', array_column($slips, 'order_no'));
    $in  = implode(',', array_fill(0, count($nos), '?'));
    $st  = $pdo->prepare(
        "SELECT d.order_no, d.line_no, d.product_code, p.product_name, p.spec, p.storage_type, d.order_qty,
                d.ref_order_no, d.ref_line_no,
                COALESCE(SUM(CASE WHEN xo.is_confirmed = 1 THEN x.order_qty END), 0) AS cancel_qty,
                COALESCE(SUM(CASE WHEN xo.is_confirmed = 0 THEN x.order_qty END), 0) AS pending_cancel_qty
           FROM t_order_detail d
           JOIN m_product p           ON p.product_code = d.product_code
           LEFT JOIN t_order_detail x ON x.ref_order_no = d.order_no AND x.ref_line_no = d.line_no
           LEFT JOIN t_order xo       ON xo.order_no = x.order_no
          WHERE d.order_no IN ($in)
          GROUP BY d.order_no, d.line_no, d.product_code, p.product_name, p.spec, p.storage_type, d.order_qty,
                   d.ref_order_no, d.ref_line_no
          ORDER BY d.order_no, d.line_no"
    );
    $st->execute($nos);
    foreach ($st->fetchAll() as $d) {
        $details[$d['order_no']][] = $d;
    }
}
// 検品一覧表をまとめて出すための発注日（同じ発注日・同じ卸業者の伝票は1ページにまとまる）
$orderDates = array_values(array_unique(array_column($slips, 'order_date')));

$pageTitle = '発注書';
require_once __DIR__ . '/../common/header.php';
renderTabs([
    ['href' => '/order/order_input.php',   'label' => '① 発注入力'],
    ['href' => '/order/order_confirm.php', 'label' => '② 発注確定'],
    ['href' => '/order/order_print.php',   'label' => '③ 発注書'],
]);
?>
<?php if (MAIL_DRIVER === 'log'): ?>
  <p class="dev-note">開発モード（MAIL_DRIVER=log）のため、メールは実際には送られません（送信日時も記録されません）。</p>
<?php endif; ?>
<?php if (!$slips): ?>
  <p class="hint">確定済みの発注伝票はありません。</p>
<?php else: ?>
<div class="card tbl-scroll">
<table class="data-table">
  <thead>
    <tr><th>伝票No</th><th>発注日</th><th>卸業者</th><th>明細</th><th>合計金額</th><th>起票者</th><th>確定者・確定日時</th><th>メール送信</th><th>帳票</th></tr>
  </thead>
  <tbody>
    <?php foreach ($slips as $s): ?>
    <tr>
      <td class="num"><?= h($s['order_no']) ?></td>
      <td><?= h(formatDate($s['order_date'])) ?></td>
      <td><?= h($s['supplier_code'] . ' ' . $s['supplier_name']) ?></td>
      <td>
        <table class="inner-table">
          <?php foreach ($details[$s['order_no']] ?? [] as $d): ?>
          <tr class="<?= minusClass($d['order_qty']) ?>">
            <td><?= h($d['product_code']) ?></td>
            <td><?= h($d['product_name'] . ' ' . $d['spec']) ?><?= storageBadge($d['storage_type']) ?>
              <?php if ($d['ref_order_no'] !== null): ?><small>（取消元 No.<?= h($d['ref_order_no'] . '-' . $d['ref_line_no']) ?>）</small><?php endif; ?>
            </td>
            <td class="num"><?= h($d['order_qty']) ?></td>
            <td>
              <?php if ((int)$d['cancel_qty'] < 0): ?><span class="qty-minus">取消済 <?= h(-(int)$d['cancel_qty']) ?></span><?php endif; ?>
              <?php if ((int)$d['pending_cancel_qty'] < 0): ?><span class="status-unconfirmed">取消 <?= h(-(int)$d['pending_cancel_qty']) ?>（未確定）</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </td>
      <td class="num <?= minusClass($s['total']) ?>"><?= h(formatYen($s['total'])) ?></td>
      <td><?= h($s['created_name'] ?? $s['created_by']) ?></td>
      <td><?= h($s['confirmed_name'] ?? $s['confirmed_by']) ?><br><small><?= h($s['confirmed_at']) ?></small></td>
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
</div>
<p>検品一覧表をまとめて印刷（同じ卸業者・同じ発注日の伝票は1ページにまとめます）：
  <?php if (count($slips) > 1 && $orderNos): ?>
    <a href="/order/inspection_print.php?order_no=<?= h(implode(',', array_column($slips, 'order_no'))) ?>" target="_blank" class="btn btn-small">この一覧の伝票すべて</a>
  <?php endif; ?>
  <?php foreach ($orderDates as $date): ?>
    <a href="/order/inspection_print.php?order_date=<?= h($date) ?>" target="_blank" class="btn btn-small">発注日 <?= h(formatDate($date)) ?> の全伝票</a>
  <?php endforeach; ?>
</p>
<?php endif; ?>
<div class="action-bar"><div class="bar-in">
  <span class="bar-spacer"></span>
  <?php if (currentOperator()['can_approve_order'] === 1): ?>
  <a href="/order/order_confirm.php" class="btn">発注確定へ戻る</a>
  <?php endif; ?>
  <a href="/menu.php" class="btn">メニューへ戻る</a>
</div></div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
