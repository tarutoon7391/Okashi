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
$st = $pdo->prepare(
    'SELECT t.product_code, p.product_name, p.spec, t.sales_qty, t.amount
       FROM t_sales t JOIN m_product p ON p.product_code = t.product_code
      WHERE t.is_confirmed = 0 AND t.sales_date = :date ORDER BY p.product_kana'
);
foreach ($days as &$d) {
    $st->execute([':date' => $d['sales_date']]);
    $d['details'] = $st->fetchAll();
}
unset($d);

$pageTitle = '売上確定';
require_once __DIR__ . '/../common/header.php';
?>
<?php if (!$days): ?>
  <p>未確定の売上はありません。</p>
<?php else: ?>
<form method="post" data-confirm="選んだ日の売上を確定し、在庫を減らします。確定後は変更できません。よろしいですか？">
  <table class="data-table">
    <thead>
      <tr><th><input type="checkbox" class="js-check-all" title="すべて選択"></th><th>売上日</th><th>明細</th><th>件数</th><th>数量合計</th><th>金額合計</th><th>状態</th></tr>
    </thead>
    <tbody>
      <?php foreach ($days as $d): ?>
      <tr>
        <td><input type="checkbox" name="sales_date[]" value="<?= h($d['sales_date']) ?>" class="js-check"></td>
        <td><a href="/sales/sales_input.php?sales_date=<?= h($d['sales_date']) ?>"><?= h(formatDate($d['sales_date'])) ?></a></td>
        <td>
          <table class="inner-table">
            <?php foreach ($d['details'] as $r): ?>
            <tr class="<?= minusClass($r['sales_qty']) ?>">
              <td><?= h($r['product_code'] . ' ' . $r['product_name'] . ' ' . $r['spec']) ?></td>
              <td class="num"><?= h($r['sales_qty']) ?></td>
              <td class="num"><?= h(formatYen($r['amount'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        </td>
        <td class="num"><?= h($d['cnt']) ?></td>
        <td class="num <?= minusClass($d['qty']) ?>"><?= h($d['qty']) ?></td>
        <td class="num <?= minusClass($d['amount']) ?>"><?= h(formatYen($d['amount'])) ?></td>
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
<p><a href="/sales/sales_input.php">売上入力へ</a></p>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
