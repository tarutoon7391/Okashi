<?php
// SC-72 卸業者一覧（F-71）担当A
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
requireLogin();
$pdo = getDb();

$keyword = is_string($_GET['keyword'] ?? null) ? trim($_GET['keyword']) : '';
$sql = 'SELECT s.*, (SELECT COUNT(*) FROM m_product p WHERE p.supplier_code = s.supplier_code AND p.is_deleted = 0) AS product_cnt
          FROM m_supplier s WHERE s.is_deleted = 0';
$params = [];
if ($keyword !== '') {
    $sql .= ' AND s.supplier_name LIKE :kw';
    $params[':kw'] = "%{$keyword}%";
}
$st = $pdo->prepare($sql . ' ORDER BY s.supplier_code');
$st->execute($params);
$suppliers = $st->fetchAll();

$pageTitle = '卸業者一覧';
require_once __DIR__ . '/../common/header.php';
?>
<form method="get" class="filter-form">
  <label>業者名 <input type="search" name="keyword" value="<?= h($keyword) ?>"></label>
  <button type="submit" class="btn">検索</button>
  <a href="/master/supplier_list.php" class="btn">条件クリア</a>
</form>
<div class="btn-area">
  <a href="/master/supplier_edit.php" class="btn btn-primary">新規登録</a>
</div>
<table class="data-table">
  <thead>
    <tr><th>卸業者コード</th><th>卸業者名</th><th>営業所住所</th><th>発注先メール</th><th>担当者</th><th>取扱商品数</th><th>補足メモ</th><th></th></tr>
  </thead>
  <tbody>
    <?php if (!$suppliers): ?><tr><td colspan="8">該当する卸業者がありません</td></tr><?php endif; ?>
    <?php foreach ($suppliers as $s): ?>
    <tr>
      <td><?= h($s['supplier_code']) ?></td>
      <td><?= h($s['supplier_name']) ?></td>
      <td><?= h($s['office_address']) ?></td>
      <td><?= $s['order_email'] ? h($s['order_email']) : '<span class="error-text">未登録</span>' ?></td>
      <td><?= h($s['contact_name']) ?></td>
      <td class="num"><?= h($s['product_cnt']) ?></td>
      <td><?= h($s['memo']) ?></td>
      <td class="nowrap">
        <a href="/master/supplier_edit.php?code=<?= h(rawurlencode($s['supplier_code'])) ?>" class="btn btn-small">編集</a>
        <form method="post" action="/master/supplier_delete.php" class="inline-form" data-confirm="<?= h($s['supplier_name']) ?> を削除します。よろしいですか？">
          <input type="hidden" name="supplier_code" value="<?= h($s['supplier_code']) ?>">
          <button type="submit" class="btn btn-small btn-danger">削除</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<div class="btn-area"><a href="/menu.php" class="btn">メニューへ戻る</a></div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
