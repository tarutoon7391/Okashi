<?php
// SC-70 商品一覧（F-70）担当A
// 削除済を除き、商品名カナの昇順（質問No.1）。商品名・卸業者で検索
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/query.php';
requireLogin();
$pdo = getDb();

$keyword      = is_string($_GET['keyword'] ?? null) ? trim($_GET['keyword']) : '';
$supplierCode = is_string($_GET['supplier_code'] ?? null) ? $_GET['supplier_code'] : '';

$sql = 'SELECT p.*, s.supplier_name, COALESCE(t.stock_qty, 0) AS stock_qty
          FROM m_product p
          JOIN m_supplier s ON s.supplier_code = p.supplier_code
          LEFT JOIN t_stock t ON t.product_code = p.product_code
         WHERE p.is_deleted = 0';
$params = [];
if ($keyword !== '') {
    $sql .= ' AND (p.product_name LIKE :kw1 OR p.product_kana LIKE :kw2 OR p.product_code LIKE :kw3)';
    $params += [':kw1' => "%{$keyword}%", ':kw2' => "%{$keyword}%", ':kw3' => "%{$keyword}%"];
}
if ($supplierCode !== '') {
    $sql .= ' AND p.supplier_code = :supplier_code';
    $params[':supplier_code'] = $supplierCode;
}
$st = $pdo->prepare($sql . ' ORDER BY p.product_kana, p.product_code');
$st->execute($params);
$products = $st->fetchAll();

$pageTitle = '商品一覧';
require_once __DIR__ . '/../common/header.php';
?>
<form method="get" class="filter-form">
  <label>商品名 <input type="search" name="keyword" value="<?= h($keyword) ?>" placeholder="商品名・カナ・コード"></label>
  <label>卸業者
    <select name="supplier_code">
      <option value="">すべて</option>
      <?php foreach (getSupplierOptions() as $s): ?>
        <option value="<?= h($s['supplier_code']) ?>" <?= $supplierCode === $s['supplier_code'] ? 'selected' : '' ?>><?= h($s['supplier_code'] . ' ' . $s['supplier_name']) ?></option>
      <?php endforeach; ?>
    </select></label>
  <button type="submit" class="btn">検索</button>
  <a href="/master/product_list.php" class="btn">条件クリア</a>
</form>
<div class="btn-area">
  <a href="/master/product_edit.php" class="btn btn-primary">新規登録</a>
</div>
<table class="data-table">
  <thead>
    <tr><th>写真</th><th>商品コード</th><th>商品名（カナ）</th><th>規格</th><th>入数</th><th>定価</th><th>契約単価</th><th>契約卸</th><th>保存区分</th><th>在庫</th><th></th></tr>
  </thead>
  <tbody>
    <?php if (!$products): ?><tr><td colspan="11">該当する商品がありません</td></tr><?php endif; ?>
    <?php foreach ($products as $p): ?>
    <tr class="<?= (int)$p['stock_qty'] <= 0 ? 'stock-warning' : '' ?>">
      <td><?php if ($p['photo_path']): ?><img src="/img/product/<?= h(rawurlencode($p['photo_path'])) ?>" alt="" class="thumb"><?php endif; ?></td>
      <td><?= h($p['product_code']) ?></td>
      <td><?= h($p['product_name']) ?><br><small><?= h($p['product_kana']) ?></small></td>
      <td><?= h($p['spec']) ?></td>
      <td class="num"><?= h($p['pack_qty'] . $p['unit']) ?></td>
      <td class="num"><?= h(formatYen($p['list_price'])) ?></td>
      <td class="num"><?= h(formatYen($p['contract_price'])) ?></td>
      <td><?= h($p['supplier_name']) ?></td>
      <td><?= h(storageTypeName((int)$p['storage_type'])) ?><?= storageBadge($p['storage_type']) ?></td>
      <td class="num"><?= h($p['stock_qty']) ?></td>
      <td class="nowrap">
        <a href="/master/product_edit.php?code=<?= h(rawurlencode($p['product_code'])) ?>" class="btn btn-small">編集</a>
        <form method="post" action="/master/product_delete.php" class="inline-form" data-confirm="<?= h($p['product_name'] . ' ' . $p['spec']) ?> を削除します。よろしいですか？">
          <input type="hidden" name="product_code" value="<?= h($p['product_code']) ?>">
          <button type="submit" class="btn btn-small btn-danger">削除</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<div class="btn-area"><a href="/menu.php" class="btn">メニューへ戻る</a></div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
