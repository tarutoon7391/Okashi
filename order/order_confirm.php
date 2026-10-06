<?php
// SC-11 発注確定（F-11）担当B　※発注承認可の操作者のみ（質問No.5）
// チェックした伝票を confirmOrder() で確定 → 伝票ごとに sendOrderMail() で発注書PDFを卸業者へ送る
// → SC-12 発注書へ。メール送信に失敗しても確定は取り消さない（warning）
// 未確定の伝票は「削除」できる（入力ミスの直し用。確定済みの伝票は削除できない）
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/slip.php';
require_once __DIR__ . '/../common/mail.php';
requireApprover();
$pdo = getDb();

// 未確定伝票の削除（明細と見出しを物理削除。確定済みなら何もしない）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_no'])) {
    $orderNo = postInt('delete_no');
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT is_confirmed FROM t_order WHERE order_no = :no FOR UPDATE');
        $st->execute([':no' => $orderNo]);
        $row = $st->fetch();
        if ($row === false) {
            $pdo->rollBack();
            setFlash('error', "伝票No.{$orderNo} は見つかりません（既に削除されています）");
            redirect('/order/order_confirm.php');
        }
        if ((int)$row['is_confirmed'] === 1) {
            $pdo->rollBack();
            setFlash('error', "伝票No.{$orderNo} は確定済みのため削除できません。取消はマイナス数量の発注で行ってください");
            redirect('/order/order_confirm.php');
        }
        $pdo->prepare('DELETE FROM t_order_detail WHERE order_no = :no')->execute([':no' => $orderNo]);
        $pdo->prepare('DELETE FROM t_order WHERE order_no = :no AND is_confirmed = 0')->execute([':no' => $orderNo]);
        $pdo->commit();
        setFlash('success', "未確定の発注伝票 No.{$orderNo} を削除しました");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[order_confirm delete] ' . $e->getMessage());
        setFlash('error', "伝票No.{$orderNo} を削除できませんでした。時間をおいてもう一度やり直してください");
    }
    redirect('/order/order_confirm.php');
}

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
            // confirmOrder が投げる業務エラー（RuntimeException）は画面に出す。DBエラーなどはログだけ
            if ($e instanceof RuntimeException && !($e instanceof PDOException)) {
                $messages[] = "伝票No.{$orderNo}：" . $e->getMessage();
            } else {
                error_log('[order_confirm] ' . $e->getMessage());
                $messages[] = "伝票No.{$orderNo}：確定に失敗しました。時間をおいてもう一度やり直してください";
            }
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
    if (!isMailActuallySent()) {
        // 開発モード（MAIL_DRIVER=log）では発注書メールを実際には送っていない
        $done .= "\n発注書メールは開発モードのため実際には送信していません";
    }
    setFlash($messages ? 'warning' : 'success', $messages ? $done . "\n" . implode("\n", $messages) : $done);
    redirect('/order/order_print.php?order_no=' . implode(',', $confirmed));
}

// GET時：未確定の発注伝票一覧（明細つき）。起票者も出す
$slips = $pdo->query(
    'SELECT o.order_no, o.order_date, o.supplier_code, s.supplier_name, s.order_email, o.created_by,
            op.operator_name AS created_name
       FROM t_order o
       JOIN m_supplier s       ON s.supplier_code = o.supplier_code
       LEFT JOIN m_operator op ON op.operator_code = o.created_by
      WHERE o.is_confirmed = 0
      ORDER BY o.order_date, o.order_no'
)->fetchAll();
// 取消（マイナス）行は取消元の 発注数・確定済みの取消数・納品数 も出す（確定してよいか判断できるように）
$st = $pdo->prepare(
    'SELECT d.line_no, d.product_code, p.product_name, p.spec, p.storage_type, d.order_qty, d.amount, d.memo,
            d.ref_order_no, d.ref_line_no, r.order_qty AS ref_order_qty,
            (SELECT COALESCE(SUM(x.order_qty), 0)
               FROM t_order_detail x JOIN t_order xo ON xo.order_no = x.order_no AND xo.is_confirmed = 1
              WHERE x.ref_order_no = d.ref_order_no AND x.ref_line_no = d.ref_line_no) AS ref_cancel_qty,
            (SELECT COALESCE(SUM(dd.delivery_qty), 0)
               FROM t_delivery_detail dd JOIN t_delivery dh ON dh.delivery_no = dd.delivery_no AND dh.is_return = 0
              WHERE dd.order_no = d.ref_order_no AND dd.order_line_no = d.ref_line_no) AS ref_delivered_qty
       FROM t_order_detail d
       JOIN m_product p           ON p.product_code = d.product_code
       LEFT JOIN t_order_detail r ON r.order_no = d.ref_order_no AND r.line_no = d.ref_line_no
      WHERE d.order_no = :no ORDER BY d.line_no'
);
foreach ($slips as &$slip) {
    $st->execute([':no' => $slip['order_no']]);
    $slip['details'] = $st->fetchAll();
    $slip['total']   = array_sum(array_column($slip['details'], 'amount'));
}
unset($slip);

$totalAmount = array_sum(array_column($slips, 'total'));
$noMailCount = count(array_filter($slips, fn($s) => !$s['order_email']));

$pageTitle = '発注確定';
require_once __DIR__ . '/../common/header.php';
renderTabs([
    ['href' => '/order/order_input.php',   'label' => '① 発注入力'],
    ['href' => '/order/order_confirm.php', 'label' => '② 発注確定'],
    ['href' => '/order/order_print.php',   'label' => '③ 発注書'],
]);
?>
<div class="cards">
  <?= statCard('未確定の伝票', h(count($slips)) . '枚') ?>
  <?= statCard('未確定の金額', h(formatYen($totalAmount))) ?>
  <?= statCard('メール未登録の卸業者', h($noMailCount) . '枚', $noMailCount > 0 ? 'warn' : '') ?>
</div>
<?php if (!$slips): ?>
  <div class="card form-card"><p class="hint" style="margin:0">未確定の発注伝票はありません。<a href="/order/order_input.php">発注入力</a>で登録した伝票がここに出ます。</p></div>
  <div class="action-bar"><div class="bar-in">
    <span class="bar-spacer"></span>
    <a href="/order/order_input.php" class="btn">発注入力へ</a>
    <a href="/menu.php" class="btn">メニューへ戻る</a>
  </div></div>
<?php else: ?>
<p class="hint">確定する発注伝票を選んでください。確定すると変更できず、卸業者へ発注書をメールで送ります。</p>
<form method="post" data-confirm="チェックした発注伝票を確定します。確定後は変更できず、卸業者へ発注書をメールで送ります。よろしいですか？">
  <p class="note"><label><input type="checkbox" class="js-check-all"> すべての伝票を選択</label></p>
  <?php foreach ($slips as $i => $slip): $qty = array_sum(array_column($slip['details'], 'order_qty')); ?>
  <div class="card slip-card" data-n="<?= count($slip['details']) ?>" data-q="<?= h($qty) ?>" data-a="<?= h($slip['total']) ?>">
    <label class="slip-head">
      <input type="checkbox" name="order_no[]" value="<?= h($slip['order_no']) ?>" class="js-check" aria-label="伝票No.<?= h($slip['order_no']) ?>を選択">
      <span class="slip-title">No.<?= h($slip['order_no']) ?>　<?= h($slip['supplier_name']) ?> <?= statusPill(0) ?>
        <small><?= h(formatDate($slip['order_date'])) ?>・起票 <?= h($slip['created_name'] ?? $slip['created_by']) ?></small></span>
      <span class="slip-sum">
        <span><small>明細</small><b><?= count($slip['details']) ?>件</b></span>
        <span><small>数量合計</small><b class="<?= $qty < 0 ? 'neg' : '' ?>"><?= h($qty) ?></b></span>
        <span><small>合計金額</small><b class="<?= (int)$slip['total'] < 0 ? 'neg' : '' ?>"><?= h(formatYen($slip['total'])) ?></b></span>
      </span>
    </label>
    <?php if (!$slip['order_email']): ?>
      <div class="warn-box">⚠ この卸業者は発注先メールが未登録のため、発注書は送信されません（確定はできます）</div>
    <?php endif; ?>
    <details<?= $i === 0 ? ' open' : '' ?>>
      <summary>明細を見る</summary>
      <div class="tbl-scroll">
        <table class="data-table compact">
          <thead><tr><th>商品コード</th><th>商品名</th><th class="num">数量</th><th class="num">金額</th><th>発注時メモ</th></tr></thead>
          <tbody>
            <?php foreach ($slip['details'] as $d): ?>
            <tr class="<?= minusClass($d['order_qty']) ?>">
              <td><?= h($d['product_code']) ?></td>
              <td><?= h($d['product_name'] . ' ' . $d['spec']) ?><?= storageBadge($d['storage_type']) ?>
                <?php if ($d['ref_order_no'] !== null): ?>
                  <br><small class="note">取消元 No.<?= h($d['ref_order_no'] . '-' . $d['ref_line_no']) ?>：発注<?= h($d['ref_order_qty']) ?>
                    ・取消済<?= h(-(int)$d['ref_cancel_qty']) ?>・納品<?= h($d['ref_delivered_qty']) ?>
                    → 残<?= h((int)$d['ref_order_qty'] + (int)$d['ref_cancel_qty'] - (int)$d['ref_delivered_qty']) ?></small>
                <?php endif; ?>
              </td>
              <td class="num"><?= h($d['order_qty']) ?></td>
              <td class="num"><?= h(formatYen($d['amount'])) ?></td>
              <td><?= h($d['memo']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
    <div class="slip-foot">
      <span class="note">発注先メール：<?= $slip['order_email'] ? h($slip['order_email']) : '<span class="error-text">未登録</span>' ?></span>
      <span style="margin-left:auto"></span>
      <button type="submit" form="deleteForm<?= h($slip['order_no']) ?>" class="btn btn-small">この伝票を削除</button>
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
    <a href="/order/order_input.php" class="btn">発注入力へ</a>
    <a href="/menu.php" class="btn">メニューへ戻る</a>
  </div></div>
</form>
<?php // 削除ボタン用のフォーム（確定フォームの中に form は置けないので外に置き、ボタンの form 属性で指定する） ?>
<?php foreach ($slips as $slip): ?>
<form method="post" id="deleteForm<?= h($slip['order_no']) ?>" class="inline-form" data-confirm="未確定の発注伝票 No.<?= h($slip['order_no']) ?>（<?= h($slip['supplier_name']) ?>）を削除します。元に戻せません。よろしいですか？">
  <input type="hidden" name="delete_no" value="<?= h($slip['order_no']) ?>">
</form>
<?php endforeach; ?>
<?php endif; ?>
<p class="page-links">確定済みの発注書は <a href="/order/order_print.php">③ 発注書</a> から確認できます。</p>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
