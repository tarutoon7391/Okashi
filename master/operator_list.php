<?php
// SC-74 操作者一覧（F-72）担当A
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
requireLogin();
$pdo = getDb();

$operators = $pdo->query(
    'SELECT operator_code, operator_name, email, can_approve_order, updated_at
       FROM m_operator WHERE is_deleted = 0 ORDER BY operator_code'
)->fetchAll();
$me = currentOperator();

$pageTitle = '操作者一覧';
require_once __DIR__ . '/../common/header.php';
?>
<?php if ($me['can_approve_order'] === 1): ?>
<div class="btn-area">
  <a href="/master/operator_edit.php" class="btn btn-primary">新規登録</a>
</div>
<?php else: ?>
<p class="note">操作者の新規登録とパスワード・権限の設定は、発注承認可の操作者だけが行えます（質問No.5）。</p>
<?php endif; ?>
<table class="data-table">
  <thead>
    <tr><th>操作者コード</th><th>操作者名</th><th>メールアドレス</th><th>発注承認</th><th>更新日時</th><th></th></tr>
  </thead>
  <tbody>
    <?php foreach ($operators as $o): ?>
    <tr>
      <td><?= h($o['operator_code']) ?></td>
      <td><?= h($o['operator_name']) ?><?= $o['operator_code'] === $me['operator_code'] ? ' <small>（自分）</small>' : '' ?></td>
      <td><?= h($o['email']) ?></td>
      <td><?= (int)$o['can_approve_order'] === 1 ? '<span class="status-confirmed">可</span>' : '不可' ?></td>
      <td><?= h($o['updated_at']) ?></td>
      <td class="nowrap">
        <a href="/master/operator_edit.php?code=<?= h(rawurlencode($o['operator_code'])) ?>" class="btn btn-small">編集</a>
        <?php if ($o['operator_code'] !== $me['operator_code']): ?>
        <form method="post" action="/master/operator_delete.php" class="inline-form" data-confirm="<?= h($o['operator_name']) ?> を削除します。よろしいですか？">
          <input type="hidden" name="operator_code" value="<?= h($o['operator_code']) ?>">
          <button type="submit" class="btn btn-small btn-danger">削除</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<div class="btn-area"><a href="/menu.php" class="btn">メニューへ戻る</a></div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
