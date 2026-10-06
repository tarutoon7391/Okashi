<?php
// SC-30 売上入力（F-30）担当D
// 売上日を選び、全商品の売上数を一括入力する（未確定）
// 同一日・同一商品は「上書き」（質問No.8）：
//   未確定の行がある → その行を（入力値 − 確定済み合計）に UPDATE（0 になるなら未確定行を消す）
//   確定済みの行しか無い → 差分（入力値 − 確定済み合計）を訂正行として INSERT（確定済み行は書き換えない）
// 売上金額 amount = 定価 × 数量
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
requireLogin();
$pdo = getDb();
$errors = [];

$salesDate = is_string($_GET['sales_date'] ?? null) && isValidDate($_GET['sales_date']) ? $_GET['sales_date'] : date('Y-m-d');

// 指定日の商品ごとの登録状況（確定済み合計・未確定行）
function getSalesOfDay(PDO $pdo, string $date): array
{
    $st = $pdo->prepare(
        'SELECT product_code,
                SUM(CASE WHEN is_confirmed = 1 THEN sales_qty ELSE 0 END) AS confirmed_qty,
                SUM(CASE WHEN is_confirmed = 0 THEN sales_qty ELSE 0 END) AS unconfirmed_qty,
                MAX(CASE WHEN is_confirmed = 0 THEN sales_no END)         AS unconfirmed_no
           FROM t_sales WHERE sales_date = :date GROUP BY product_code'
    );
    $st->execute([':date' => $date]);
    $result = [];
    foreach ($st->fetchAll() as $r) {
        $result[$r['product_code']] = $r;
    }
    return $result;
}

$products = $pdo->query(
    'SELECT p.product_code, p.product_name, p.spec, p.pack_qty, p.list_price, p.storage_type, COALESCE(s.stock_qty, 0) AS stock_qty
       FROM m_product p LEFT JOIN t_stock s ON s.product_code = p.product_code
      WHERE p.is_deleted = 0 ORDER BY p.product_kana, p.product_code'
)->fetchAll();
$qtyInput = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. 入力値の取得・バリデーション
    $salesDate = postStr('sales_date');
    $qtyInput  = postArray('sales_qty');
    if (!isValidDate($salesDate)) {
        $errors[] = '売上日を正しく入力してください';
    }
    $targets = [];
    foreach ($products as $p) {
        $raw = $qtyInput[$p['product_code']] ?? '';
        if (!is_string($raw) || trim($raw) === '') {
            continue;   // 空欄は登録しない
        }
        $qty = toIntOrNull($raw);
        if ($qty === null) {
            $errors[] = "{$p['product_code']} {$p['product_name']}：売上数は整数で入力してください";
            continue;
        }
        $targets[$p['product_code']] = ['qty' => $qty, 'price' => (int)$p['list_price']];
    }

    // 2. DB更新
    if (!$errors) {
        $by = currentOperator()['operator_code'];
        $changed = 0;
        try {
            $pdo->beginTransaction();
            $day = getSalesOfDay($pdo, $salesDate);
            $upd = $pdo->prepare('UPDATE t_sales SET sales_qty = :qty, amount = :amount, updated_by = :by
                                   WHERE sales_no = :no AND is_confirmed = 0');
            $del = $pdo->prepare('DELETE FROM t_sales WHERE sales_no = :no AND is_confirmed = 0');
            $ins = $pdo->prepare('INSERT INTO t_sales (sales_date, product_code, sales_qty, amount, created_by, updated_by)
                                  VALUES (:date, :code, :qty, :amount, :by, :by2)');
            foreach ($targets as $code => $t) {
                $cur = $day[$code] ?? null;
                $confirmedQty = $cur ? (int)$cur['confirmed_qty'] : 0;
                $currentTotal = $cur ? $confirmedQty + (int)$cur['unconfirmed_qty'] : 0;
                if ($cur === null && $t['qty'] === 0) {
                    continue;                              // 0 は登録しない
                }
                if ($t['qty'] === $currentTotal) {
                    continue;                              // 変化なし
                }
                $diff = $t['qty'] - $confirmedQty;         // 未確定行として持つべき数
                if ($cur !== null && $cur['unconfirmed_no'] !== null) {
                    // 未確定行を1本にまとめて上書き（未確定行は確定前なので書き換えてよい）
                    $pdo->prepare('DELETE FROM t_sales WHERE sales_date = :date AND product_code = :code
                                     AND is_confirmed = 0 AND sales_no <> :no')
                        ->execute([':date' => $salesDate, ':code' => $code, ':no' => $cur['unconfirmed_no']]);
                    if ($diff === 0) {
                        $del->execute([':no' => $cur['unconfirmed_no']]);
                    } else {
                        $upd->execute([':qty' => $diff, ':amount' => $diff * $t['price'], ':by' => $by, ':no' => $cur['unconfirmed_no']]);
                    }
                } else {
                    $ins->execute([':date' => $salesDate, ':code' => $code, ':qty' => $diff,
                                   ':amount' => $diff * $t['price'], ':by' => $by, ':by2' => $by]);
                }
                $changed++;
            }
            $pdo->commit();
            setFlash('success', formatDate($salesDate) . " の売上を登録しました（{$changed}商品）。売上確定で在庫に反映してください");
            redirect('/sales/sales_input.php?sales_date=' . rawurlencode($salesDate));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // 詳しい原因はログにだけ出す（画面には出さない）
            error_log('[sales_input] ' . $e->getMessage());
            $errors[] = '登録に失敗しました。時間をおいてもう一度お試しください';
        }
    }
    setFlash('error', implode("\n", $errors));
}

// GET時：同じ売上日に登録済みの数量を初期表示
$day = isValidDate($salesDate) ? getSalesOfDay($pdo, $salesDate) : [];

// この日の状態（未確定の行がある商品の数・確定済みだけの商品の数）
$unconfirmedCount = 0;
$confirmedCount   = 0;
foreach ($day as $r) {
    if ($r['unconfirmed_no'] !== null) {
        $unconfirmedCount++;
    } else {
        $confirmedCount++;
    }
}

// 集計タイル用：登録済みの件数・金額、在庫0の商品数
$registeredCount  = count($day);
$registeredAmount = 0;
foreach ($day as $code => $r) {
    foreach ($products as $p) {
        if ($p['product_code'] === $code) {
            $registeredAmount += ((int)$r['confirmed_qty'] + (int)$r['unconfirmed_qty']) * (int)$p['list_price'];
            break;
        }
    }
}
$zeroStockCount = count(array_filter($products, fn($p) => (int)$p['stock_qty'] <= 0));

$pageTitle  = '売上入力';
$pageScript = 'sales_input.js';
require_once __DIR__ . '/../common/header.php';
renderTabs([
    ['href' => '/sales/sales_input.php',   'label' => '① 売上入力・確認'],
    ['href' => '/sales/sales_confirm.php', 'label' => '② 売上確定'],
]);
?>
<div class="cards">
  <?php
  if (!$day) {
      $state = '<span class="st st-none">未登録</span>';
  } elseif ($unconfirmedCount > 0) {
      $state = statusPill(0) . ($confirmedCount > 0 ? ' <small class="note">一部確定済</small>' : '');
  } else {
      $state = statusPill(1);
  }
  echo statCard('この日の状態', $state);
  echo statCard('登録済みの件数', h($registeredCount) . '件');
  echo statCard('登録済みの金額', h(formatYen($registeredAmount)));
  echo statCard('在庫が0の商品', h($zeroStockCount) . '商品', $zeroStockCount > 0 ? 'warn' : '');
  ?>
</div>

<form method="get" class="filter-form">
  <label for="salesDate">売上日</label>
  <input type="date" id="salesDate" name="sales_date" value="<?= h($salesDate) ?>" onchange="this.form.submit()">
  <noscript><button type="submit" class="btn">表示</button></noscript>
  <span class="hint">登録済みの日は数量が入っています。書き換えると上書きされます。売上金額＝定価×売上数</span>
</form>

<form method="post" data-confirm="<?= h(formatDate($salesDate)) ?> の売上を登録します。よろしいですか？">
  <input type="hidden" name="sales_date" value="<?= h($salesDate) ?>">
  <div class="card tbl-scroll">
  <table class="data-table">
    <thead>
      <tr><th>商品コード</th><th>商品名</th><th>規格</th><th class="num">入数</th><th class="num">定価</th><th class="num">現在庫</th><th>状態</th><th class="num">売上数</th><th class="num">売上金額</th></tr>
    </thead>
    <tbody>
      <?php
      foreach ($products as $p):
          $cur = $day[$p['product_code']] ?? null;
          $registered = $cur ? (int)$cur['confirmed_qty'] + (int)$cur['unconfirmed_qty'] : null;
          $value = $qtyInput[$p['product_code']] ?? ($registered ?? '');
          // 状態：未登録 / 未確定 / 確定済。JS が入力中（登録済みと違う値）に切り替える
          $status = $cur === null ? 'none' : ($cur['unconfirmed_no'] === null ? 'done' : 'pending'); ?>
      <tr class="js-sales-row <?= (int)$p['stock_qty'] <= 0 ? 'stock-warning' : '' ?>" data-price="<?= h($p['list_price']) ?>"
          data-registered="<?= h($registered ?? '') ?>" data-status="<?= h($status) ?>">
        <td><?= h($p['product_code']) ?></td>
        <td><?= h($p['product_name']) ?><?= storageBadge($p['storage_type']) ?></td>
        <td><?= h($p['spec']) ?></td>
        <td class="num"><?= h($p['pack_qty']) ?></td>
        <td class="num"><?= h(formatYen($p['list_price'])) ?></td>
        <?= stockCell($p['stock_qty']) ?>
        <td class="js-sales-state"></td>
        <td class="num"><input type="number" name="sales_qty[<?= h($p['product_code']) ?>]" class="js-sales-qty" value="<?= h($value) ?>" step="1"
            aria-label="<?= h($p['product_name'] . ' ' . $p['spec']) ?>の売上数"></td>
        <td class="num js-sales-amount"></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="note">※ 薄い赤の行は在庫が0の商品です。売ると在庫がマイナスになるため、確定時に警告が出ます。<br>
    確定済みの日の数量を書き換えると、差分が訂正行として登録されます（確定済みの行は変わりません）。</p>

  <div class="action-bar"><div class="bar-in">
    <div class="bar-sum">
      <span><small>売上数合計</small><b id="salesTotalQty">0</b></span>
      <span><small>金額合計</small><b id="salesTotalAmount">¥0</b></span>
    </div>
    <button type="submit" class="btn btn-primary">登録</button>
    <a href="/sales/sales_confirm.php" class="btn">売上確定へ</a>
    <a href="/menu.php" class="btn">メニューへ戻る</a>
  </div></div>
</form>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
