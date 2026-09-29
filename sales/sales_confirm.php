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
    $in = implode(',', array_fill(0, count($dates), '?'));
    $st = $pdo->prepare("SELECT sales_no, product_code FROM t_sales WHERE is_confirmed = 0 AND sales_date IN ($in) ORDER BY sales_no");
    $st->execute($dates);
    $rows = $st->fetchAll();
    $count = 0;
    $messages = [];
    foreach ($rows as $r) {
        try {
            confirmSales((int)$r['sales_no']);
            $count++;
        } catch (Throwable $e) {
            $messages[] = "売上No.{$r['sales_no']}：" . $e->getMessage();
        }
    }
    foreach (getMinusStockProducts(array_unique(array_column($rows, 'product_code'))) as $m) {
        $messages[] = "在庫がマイナスです：{$m['product_code']} {$m['product_name']}（{$m['stock_qty']}）。棚卸で実数を確認してください";
    }
    $done = "{$count}件の売上を確定し、在庫を減らしました";
    setFlash($messages ? 'warning' : 'success', $messages ? $done . "\n" . implode("\n", $messages) : $done);
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
