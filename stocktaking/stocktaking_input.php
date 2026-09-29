<?php
// SC-40 棚卸入力（F-40）担当D
// 全商品を一覧表示（商品名・コードで検索したときは該当商品のみ：質問No.9）
// 実数を一括入力 → 差異がある商品だけ applyStocktaking() で t_stocktaking に記録し、在庫を実数で上書き
// 差異がある行は原因・理由が必須
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/slip.php';
requireLogin();
$pdo = getDb();
$errors = [];

$keyword = is_string($_GET['keyword'] ?? null) ? trim($_GET['keyword']) : '';
$stocktakingDate = date('Y-m-d');
$actualInput = [];
$reasonInput = [];

function getStockList(PDO $pdo, string $keyword): array
{
    $sql = 'SELECT p.product_code, p.product_name, p.spec, p.storage_type, COALESCE(s.stock_qty, 0) AS stock_qty
              FROM m_product p LEFT JOIN t_stock s ON s.product_code = p.product_code
             WHERE p.is_deleted = 0';
    $params = [];
    if ($keyword !== '') {
        $sql .= ' AND (p.product_code LIKE :kw1 OR p.product_name LIKE :kw2 OR p.product_kana LIKE :kw3)';
        $params = [':kw1' => "%{$keyword}%", ':kw2' => "%{$keyword}%", ':kw3' => "%{$keyword}%"];
    }
    $st = $pdo->prepare($sql . ' ORDER BY p.product_kana, p.product_code');
    $st->execute($params);
    return $st->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. 入力値の取得・バリデーション
    $keyword         = postStr('keyword');
    $stocktakingDate = postStr('stocktaking_date');
    $actualInput     = postArray('actual_qty');
    $reasonInput     = postArray('reason');
    if (!isValidDate($stocktakingDate)) {
        $errors[] = '棚卸日を正しく入力してください';
    }
    $targets = [];
    foreach (getStockList($pdo, $keyword) as $p) {
        $code = $p['product_code'];
        $raw  = $actualInput[$code] ?? '';
        if (!is_string($raw) || trim($raw) === '') {
            continue;
        }
        $actual = toIntOrNull($raw);
        $reason = is_string($reasonInput[$code] ?? null) ? trim($reasonInput[$code]) : '';
        if ($actual === null || $actual < 0) {
            $errors[] = "{$code} {$p['product_name']}：実数は0以上の整数で入力してください";
            continue;
        }
        if ($actual !== (int)$p['stock_qty'] && $reason === '') {
            $errors[] = "{$code} {$p['product_name']}：差異があるので原因・理由を入力してください";
            continue;
        }
        if (mb_strlen($reason) > 200) {
            $errors[] = "{$code} {$p['product_name']}：原因・理由は200文字以内で入力してください";
            continue;
        }
        $targets[$code] = ['actual' => $actual, 'reason' => $reason];
    }
    if (!$errors && !$targets) {
        $errors[] = '実数を1商品以上入力してください';
    }

    // 2. DB更新（在庫は applyStocktaking() 経由。差異0の商品は記録されない）
    if (!$errors) {
        $recorded = 0;
        try {
            foreach ($targets as $code => $t) {
                if (applyStocktaking($code, $t['actual'], $t['reason'], $stocktakingDate)) {
                    $recorded++;
                }
            }
            setFlash('success', "棚卸を登録しました（差異があった商品：{$recorded}件。在庫を実数に更新しました）");
            redirect('/stocktaking/stocktaking_input.php' . ($keyword !== '' ? '?keyword=' . rawurlencode($keyword) : ''));
        } catch (Throwable $e) {
            $errors[] = "途中で失敗しました（{$recorded}件は登録済み）：" . $e->getMessage();
        }
    }
    setFlash('error', implode("\n", $errors));
}

// GET時：表示用データ
$list = getStockList($pdo, $keyword);

$pageTitle  = '棚卸入力';
$pageScript = 'stocktaking_input.js';
require_once __DIR__ . '/../common/header.php';
?>
<form method="get" class="filter-form">
  <label>商品検索 <input type="search" name="keyword" value="<?= h($keyword) ?>" placeholder="商品名・カナ・商品コード"></label>
  <button type="submit" class="btn">検索</button>
  <a href="/stocktaking/stocktaking_input.php" class="btn">全商品を表示</a>
</form>

<form method="post" data-confirm="入力した実数で在庫を更新します。差異がある商品は棚卸伝票に記録されます。よろしいですか？">
  <input type="hidden" name="keyword" value="<?= h($keyword) ?>">
  <div class="form-grid">
    <label for="stocktakingDate">棚卸日</label>
    <input type="date" id="stocktakingDate" name="stocktaking_date" value="<?= h($stocktakingDate) ?>" required>
  </div>
  <p class="note">数えた商品だけ実数を入れてください（空欄は変更しません）。在庫と違う場合は原因・理由が必須です。</p>
  <table class="data-table">
    <thead>
      <tr><th>商品コード</th><th>商品名</th><th>現在の在庫数</th><th>実数</th><th>差異</th><th>差異の原因・理由</th></tr>
    </thead>
    <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="6">該当する商品がありません</td></tr>
      <?php endif; ?>
      <?php foreach ($list as $p): $code = $p['product_code']; ?>
      <tr class="js-stock-row <?= (int)$p['stock_qty'] <= 0 ? 'stock-warning' : '' ?>" data-stock="<?= h($p['stock_qty']) ?>">
        <td><?= h($code) ?></td>
        <td><?= h($p['product_name'] . ' ' . $p['spec']) ?><?= storageBadge($p['storage_type']) ?></td>
        <td class="num"><?= h($p['stock_qty']) ?></td>
        <td><input type="number" name="actual_qty[<?= h($code) ?>]" class="js-actual" value="<?= h($actualInput[$code] ?? '') ?>" min="0" step="1"></td>
        <td class="num js-diff"></td>
        <td><input type="text" name="reason[<?= h($code) ?>]" class="js-reason" value="<?= h($reasonInput[$code] ?? '') ?>" maxlength="200"></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="btn-area">
    <button type="submit" class="btn btn-primary">更新</button>
    <a href="/menu.php" class="btn">メニューへ戻る</a>
  </div>
</form>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
