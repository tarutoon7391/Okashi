<?php
// SC-31 売上確定（F-31）担当D
// 未確定の売上を日付ごとに一覧 → 選んだ日付の未確定行をすべて confirmSales() で確定 → 在庫が減る
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/slip.php';
requireLogin();
$pdo = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dates = array_values(array_filter(postArray('sales_date'), fn($d) => is_string($d) && isValidDate($d)));
    if (!$dates) {
        setFlash('error', '確定する売上日を選んでください');
        redirect('/sales/sales_confirm.php');
    }
    // 売上日を1単位として確定する。confirmSales() は1行ごとに自分でトランザクションを張るため
    // 日付全体を1トランザクションにはできない（slip.php の仕様）。そこで
    //   ① 確定前に、その日の未確定行をもう一度読み直す（別の人が確定・変更していないか）
    //   ② 1行でも失敗したら、その日の残りの行は確定しないで止める（エラーとして報告）
    // という形で「日付ごとにまとめて確定」に近づけている
    sort($dates);
    $st = $pdo->prepare('SELECT sales_no, product_code FROM t_sales WHERE is_confirmed = 0 AND sales_date = :date ORDER BY sales_no');
    $count    = 0;
    $okDates  = [];
    $errors   = [];
    $products = [];
    foreach (array_unique($dates) as $date) {
        $st->execute([':date' => $date]);
        $rows = $st->fetchAll();
        if (!$rows) {
            $errors[] = formatDate($date) . '：未確定の売上がありません（他の人が確定した可能性があります）';
            continue;
        }
        $done = 0;
        foreach ($rows as $r) {
            try {
                confirmSales((int)$r['sales_no']);
                $done++;
                $products[] = $r['product_code'];
            } catch (Throwable $e) {
                // PDOException などシステムの詳細は画面に出さずログへ。slip.php が投げる業務メッセージ（RuntimeException）だけ表示する
                error_log('[sales_confirm] sales_no=' . $r['sales_no'] . ' ' . $e->getMessage());
                $reason = ($e instanceof RuntimeException && !($e instanceof PDOException)) ? $e->getMessage() : 'システムエラーが発生しました';
                $errors[] = formatDate($date) . "：売上No.{$r['sales_no']} の確定に失敗したため、この日の残りの売上は確定していません（{$reason}）"
                          . ($done > 0 ? "。この日のうち{$done}件は確定済みです" : '');
                break;
            }
        }
        $count += $done;
        if ($done === count($rows)) {
            $okDates[] = formatDate($date);
        }
    }
    $warnings = [];
    foreach (getMinusStockProducts(array_values(array_unique($products))) as $m) {
        $warnings[] = "在庫がマイナスです：{$m['product_code']} {$m['product_name']}（{$m['stock_qty']}）。棚卸で実数を確認してください";
    }
    $doneMsg = $okDates
        ? implode('・', $okDates) . " の売上を確定し、在庫を減らしました（{$count}件）"
        : "{$count}件の売上を確定しました";
    if ($errors) {
        // 失敗があれば error（06-4 プロンプト2：例外はまとめて error）
        setFlash('error', implode("\n", array_merge($errors, [$doneMsg], $warnings)));
    } elseif ($warnings) {
        setFlash('warning', $doneMsg . "\n" . implode("\n", $warnings));
    } else {
        setFlash('success', $doneMsg);
    }
    redirect('/sales/sales_confirm.php');
}

// GET時：未確定の売上（日付別）
$days = $pdo->query(
    'SELECT sales_date, COUNT(*) AS cnt, SUM(sales_qty) AS qty, SUM(amount) AS amount
       FROM t_sales WHERE is_confirmed = 0 GROUP BY sales_date ORDER BY sales_date'
)->fetchAll();
// 明細には現在庫も出す（確定後の在庫 ＝ 現在庫 − 数量 を見せて、マイナスになる商品を事前に警告する）
$st = $pdo->prepare(
    'SELECT t.product_code, p.product_name, p.spec, p.storage_type, t.sales_qty, t.amount, COALESCE(s.stock_qty, 0) AS stock_qty
       FROM t_sales t JOIN m_product p ON p.product_code = t.product_code
       LEFT JOIN t_stock s ON s.product_code = t.product_code
      WHERE t.is_confirmed = 0 AND t.sales_date = :date ORDER BY p.product_kana'
);
$totalLines = 0;
$totalAmount = 0;
$minusDays = 0;
foreach ($days as &$d) {
    $st->execute([':date' => $d['sales_date']]);
    $d['details'] = $st->fetchAll();
    // 同じ商品が複数行あるときは合算して確定後の在庫を出す
    $after = [];
    foreach ($d['details'] as $r) {
        $after[$r['product_code']] = ($after[$r['product_code']] ?? (int)$r['stock_qty']) - (int)$r['sales_qty'];
    }
    $d['after'] = $after;
    $d['minus'] = [];
    foreach ($d['details'] as $r) {
        if ($after[$r['product_code']] < 0 && !in_array($r['product_name'] . ' ' . $r['spec'], $d['minus'], true)) {
            $d['minus'][] = $r['product_name'] . ' ' . $r['spec'];
        }
    }
    $totalLines  += (int)$d['cnt'];
    $totalAmount += (int)$d['amount'];
    if ($d['minus']) {
        $minusDays++;
    }
}
unset($d);

$pageTitle = '売上確定';
require_once __DIR__ . '/../common/header.php';
renderTabs([
    ['href' => '/sales/sales_input.php',   'label' => '① 売上入力・確認'],
    ['href' => '/sales/sales_confirm.php', 'label' => '② 売上確定'],
]);
?>
<div class="cards">
  <?= statCard('未確定の日', h(count($days)) . '日') ?>
  <?= statCard('未確定の件数', h($totalLines) . '件') ?>
  <?= statCard('未確定の金額', h(formatYen($totalAmount))) ?>
  <?= statCard('在庫がマイナスになる日', h($minusDays) . '日', $minusDays > 0 ? 'warn' : '') ?>
</div>
<?php if (!$days): ?>
  <div class="card form-card"><p class="hint" style="margin:0">未確定の売上はありません。<a href="/sales/sales_input.php">売上入力</a>で登録した売上がここに出ます。</p></div>
  <div class="action-bar"><div class="bar-in">
    <span class="bar-spacer"></span>
    <a href="/sales/sales_input.php" class="btn">売上入力へ</a>
    <a href="/menu.php" class="btn">メニューへ戻る</a>
  </div></div>
<?php else: ?>
<p class="hint">確定する売上日を選んでください。確定すると在庫が減り、あとから修正できません（訂正は売上入力でマイナスの差分として登録します）。明細の右に確定後の在庫を表示しています。</p>
<form method="post" data-confirm="選んだ日の売上を確定し、在庫を減らします。確定後は変更できません。よろしいですか？">
  <p class="note"><label><input type="checkbox" class="js-check-all"> すべての日を選択</label></p>
  <?php foreach ($days as $i => $d): ?>
  <div class="card slip-card" data-n="<?= h($d['cnt']) ?>" data-q="<?= h($d['qty']) ?>" data-a="<?= h($d['amount']) ?>">
    <label class="slip-head">
      <input type="checkbox" name="sales_date[]" value="<?= h($d['sales_date']) ?>" class="js-check" aria-label="<?= h(formatDate($d['sales_date'])) ?>を選択">
      <span class="slip-title"><?= h(formatDate($d['sales_date'])) ?> <?= statusPill(0) ?></span>
      <span class="slip-sum">
        <span><small>件数</small><b><?= h($d['cnt']) ?>件</b></span>
        <span><small>数量合計</small><b class="<?= (int)$d['qty'] < 0 ? 'neg' : '' ?>"><?= h($d['qty']) ?></b></span>
        <span><small>金額合計</small><b class="<?= (int)$d['amount'] < 0 ? 'neg' : '' ?>"><?= h(formatYen($d['amount'])) ?></b></span>
      </span>
    </label>
    <?php if ($d['minus']): ?>
      <div class="warn-box">⚠ 確定すると在庫がマイナスになる商品があります：<?= h(implode('、', $d['minus'])) ?>（棚卸で実数を確認してください）</div>
    <?php endif; ?>
    <details<?= $i === 0 ? ' open' : '' ?>>
      <summary>明細を見る</summary>
      <div class="tbl-scroll">
        <table class="data-table compact">
          <thead><tr><th>商品コード</th><th>商品名</th><th class="num">数量</th><th class="num">金額</th><th class="num">在庫（現在 → 確定後）</th></tr></thead>
          <tbody>
            <?php foreach ($d['details'] as $r): $after = $d['after'][$r['product_code']]; ?>
            <tr>
              <td><?= h($r['product_code']) ?></td>
              <td><?= h($r['product_name'] . ' ' . $r['spec']) ?><?= storageBadge($r['storage_type']) ?></td>
              <td class="num <?= minusClass($r['sales_qty']) ?>"><?= h($r['sales_qty']) ?></td>
              <td class="num <?= minusClass($r['amount']) ?>"><?= h(formatYen($r['amount'])) ?></td>
              <td class="num after"><?= h($r['stock_qty']) ?> → <b class="<?= $after < 0 ? 'neg' : '' ?>"><?= h($after) ?></b></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
    <div class="slip-foot"><a href="/sales/sales_input.php?sales_date=<?= h($d['sales_date']) ?>">この日の売上入力を開く</a></div>
  </div>
  <?php endforeach; ?>

  <div class="action-bar"><div class="bar-in">
    <div class="bar-sum">
      <span><small>選択</small><b id="selCount">0</b>日</span>
      <span><small>件数</small><b id="selLines">0件</b></span>
      <span><small>数量</small><b id="selQty">0</b></span>
      <span><small>金額</small><b id="selAmount">¥0</b></span>
    </div>
    <button type="submit" class="btn btn-danger" id="btnConfirm" disabled>選択した日を確定する</button>
    <a href="/sales/sales_input.php" class="btn">売上入力へ</a>
    <a href="/menu.php" class="btn">メニューへ戻る</a>
  </div></div>
</form>
<?php endif; ?>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
