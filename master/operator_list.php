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
// 承認不可の操作者は自分の行の「編集」だけ出す（他人の編集・削除ボタンは出さない。サーバ側でも弾く）
$isApprover = $me['can_approve_order'] === 1;

$pageTitle = '操作者一覧';
require_once __DIR__ . '/../common/header.php';
?>
<?php if ($isApprover): ?>
<div class="btn-area">
  <a href="/master/operator_edit.php" class="btn btn-primary">新規登録</a>
</div>
<?php else: ?>
<p class="note">操作者の新規登録・削除、他の操作者の編集、パスワード・権限の設定は、発注承認可の操作者だけが行えます（質問No.5）。自分の操作者名・メールアドレスは変更できます。</p>
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
        <?php if ($isApprover || $o['operator_code'] === $me['operator_code']): ?>
        <a href="/master/operator_edit.php?operator_code=<?= h(rawurlencode($o['operator_code'])) ?>" class="btn btn-small">編集</a>
        <?php endif; ?>
        <?php if ($isApprover && $o['operator_code'] !== $me['operator_code']): ?>
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
