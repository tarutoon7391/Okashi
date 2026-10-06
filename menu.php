<?php
// SC-01 メニュー（F-01）
// 上：対応が必要なもの（未確定の件数・在庫0）。下：業務ごとのボタン（common/nav.php の定義を使う）
// 発注確定は発注承認可（can_approve_order=1）の操作者にだけ出す（質問No.5）
require_once __DIR__ . '/common/auth.php';
require_once __DIR__ . '/common/db.php';
require_once __DIR__ . '/common/functions.php';
require_once __DIR__ . '/common/nav.php';
requireLogin();
$pdo = getDb();

// 未確定の件数（確定し忘れに気付けるように表示する）
$count = $pdo->query(
    'SELECT
        (SELECT COUNT(*) FROM t_order WHERE is_confirmed = 0)                      AS order_cnt,
        (SELECT COUNT(*) FROM t_delivery WHERE is_confirmed = 0)                   AS delivery_cnt,
        (SELECT COUNT(DISTINCT sales_date) FROM t_sales WHERE is_confirmed = 0)    AS sales_cnt,
        (SELECT COUNT(*) FROM t_stock s JOIN m_product p ON p.product_code = s.product_code
          WHERE s.stock_qty <= 0 AND p.is_deleted = 0)                             AS stock_cnt'
)->fetch();
$isApprover = currentOperator()['can_approve_order'] === 1;

// 対応が必要なもの：[ジャンル, 見出し, 件数, 行き先ラベル, URL]。発注確定は承認可の人だけリンクにする
$todos = [
    ['発注',     '未確定の発注', (int)$count['order_cnt'],    '発注確定へ',       $isApprover ? '/order/order_confirm.php' : ''],
    ['納品・返品', '未確定の伝票', (int)$count['delivery_cnt'], '納品・返品確定へ', '/delivery/delivery_confirm.php'],
    ['売上',     '未確定の売上', (int)$count['sales_cnt'],    '売上確定へ',       '/sales/sales_confirm.php'],
    ['在庫',     '在庫0の商品',  (int)$count['stock_cnt'],    '商品マスタで確認', '/master/product_list.php'],
];
$groups = menuGroups();

$pageTitle = 'メニュー';
require_once __DIR__ . '/common/header.php';
?>
<h2 class="menu-h2">対応が必要なもの</h2>
<div class="todo-tiles">
  <?php foreach ($todos as [$genre, $title, $n, $linkLabel, $url]): ?>
  <div class="card todo-tile <?= $n > 0 ? 'is-active' : '' ?>">
    <small><?= h($genre) ?></small>
    <div class="todo-main"><?= h($title) ?> <b><?= h($n) ?></b> 件</div>
    <?php if ($url !== ''): ?><a href="<?= h($url) ?>"><?= h($linkLabel) ?> →</a><?php else: ?><span class="note">発注承認可の操作者が確定します</span><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<div class="menu-grid">
  <?php foreach ($groups as $key => $g): ?>
  <section class="menu-section menu-section-<?= h($key) ?>" id="<?= h($key) ?>">
    <h2 class="menu-h2"><?= h($g['name']) ?></h2>
    <div class="menu-buttons <?= $g['items'][0]['desc'] === '' ? 'is-compact' : '' ?>">
      <?php foreach ($g['items'] as $it): ?>
      <a href="<?= h($it['url']) ?>" class="menu-button card">
        <span class="menu-label"><?= h($it['label']) ?></span>
        <?php if ($it['desc'] !== ''): ?><span class="menu-desc"><?= h($it['desc']) ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/common/footer.php'; ?>
