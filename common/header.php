<?php
// 共通ヘッダ（05 §6.1）
// 各画面は $pageTitle を定義してから require する
// ログイン前の画面（index.php / otp_input.php）は $isPublic = true にしてナビを出さない
// ログイン後は左にサイドナビ（common/nav.php）を出し、本文は .app-body の右側に入る
$flash    = getFlash();
$op       = currentOperator();
$isPublic = $isPublic ?? false;
if (!$isPublic) {
    require_once __DIR__ . '/nav.php';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?> | <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="/css/style.css?v=8">
</head>
<body>
<header class="app-header">
  <a href="<?= $isPublic ? '/index.php' : '/menu.php' ?>" class="app-title"><?= h(APP_NAME) ?></a>
  <?php if (!$isPublic): ?>
  <nav class="app-nav">
    <span class="operator"><?= h($op['operator_name']) ?> さん<?= $op['can_approve_order'] === 1 ? '（発注承認可）' : '' ?></span>
    <a href="/logout.php" class="btn btn-small btn-ghost">ログアウト</a>
  </nav>
  <?php endif; ?>
</header>
<?php if ($isPublic && function_exists('isInsecureMailInProduction') && isInsecureMailInProduction()): ?>
  <div class="flash flash-error prod-warning">メール未設定のためワンタイムコードを画面表示しています（本番では Resend を設定してください）</div>
<?php endif; ?>
<div class="app-body<?= $isPublic ? ' is-public' : '' ?>">
<?php if (!$isPublic) { renderSideNav(); } ?>
<main class="container">
<?php if ($flash): ?>
  <div class="flash flash-<?= h($flash['type']) ?>"><?= nl2br(h($flash['msg'])) ?></div>
<?php endif; ?>
<h1><?= h($pageTitle) ?></h1>
