<?php
// SC-01 メニュー（F-01）
// 発注確定リンクは発注承認可（can_approve_order=1）の操作者にだけ出す
require_once __DIR__ . '/common/auth.php';
require_once __DIR__ . '/common/db.php';
require_once __DIR__ . '/common/functions.php';
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

function badge(int $n, string $label): string
{
    return $n > 0 ? ' <span class="badge">' . h($label . ' ' . $n) . '</span>' : '';
}

$op = currentOperator();
$isApprover = $op['can_approve_order'] === 1;

// ジャンル（左のボタン）と、その中の行き先（右のボタン）
//   items：[表示名, URL, バッジの件数, バッジのラベル]。件数が 0 ならバッジは出さない
$groups = [
    'order' => ['name' => '発注', 'items' => array_merge(
        [['発注入力', '/order/order_input.php', 0, '']],
        // 発注確定は発注承認可（can_approve_order=1）の操作者にだけ出す
        $isApprover ? [['発注確定', '/order/order_confirm.php', (int)$count['order_cnt'], '未確定']] : [],
        [['未納品一覧表', '/delivery/undelivered_print.php', 0, '']]
    )],
    'delivery' => ['name' => '納品・返品', 'items' => [
        ['納品入力', '/delivery/delivery_input.php', 0, ''],
        ['納品確定', '/delivery/delivery_confirm.php', (int)$count['delivery_cnt'], '未確定'],
        ['返品伝票', '/return_goods/return_goods_input.php', 0, ''],
    ]],
    'sales' => ['name' => '売上・棚卸', 'items' => [
        ['売上入力', '/sales/sales_input.php', 0, ''],
        ['売上確定', '/sales/sales_confirm.php', (int)$count['sales_cnt'], '未確定'],
        ['棚卸', '/stocktaking/stocktaking_input.php', (int)$count['stock_cnt'], '在庫0以下'],
    ]],
    'report' => ['name' => '管理業務', 'items' => [
        ['発注明細表', '/report/order_list.php', 0, ''],
        ['発注集計', '/report/order_summary.php', 0, ''],
        ['納品明細表', '/report/delivery_list.php', 0, ''],
        ['納品集計', '/report/delivery_summary.php', 0, ''],
        ['返品明細表', '/report/return_goods_list.php', 0, ''],
        ['返品集計', '/report/return_goods_summary.php', 0, ''],
        ['売上明細表', '/report/sales_list.php', 0, ''],
        ['売上集計', '/report/sales_summary.php', 0, ''],
        ['棚卸調整一覧表', '/report/stocktaking_list.php', 0, ''],
        ['卸業者別納品金額', '/report/supplier_summary.php', 0, ''],
    ]],
    'master' => ['name' => 'マスタ管理', 'items' => [
        ['商品マスタ', '/master/product_list.php', 0, ''],
        ['卸業者マスタ', '/master/supplier_list.php', 0, ''],
        ['操作者マスタ', '/master/operator_list.php', 0, ''],
    ]],
];
$firstKey = array_key_first($groups);   // 最初に選ばれているジャンル（発注）

$pageTitle  = 'メニュー';
$pageScript = 'menu.js';
require_once __DIR__ . '/common/header.php';
?>
<?php // JS が無いときは全ジャンルを縦に並べて表示する。JS（menu.js）が動くと、左で選んだジャンルだけを右に出す ?>
<div class="menu-layout" id="menuLayout">
  <div class="menu-tabs" role="tablist" aria-orientation="vertical" aria-label="業務のジャンル">
    <?php foreach ($groups as $key => $g):
        $total = array_sum(array_column($g['items'], 2)); ?>
    <button type="button" class="menu-tab" role="tab" id="menuTab-<?= h($key) ?>" data-key="<?= h($key) ?>"
            aria-controls="menuPanel-<?= h($key) ?>" aria-selected="<?= $key === $firstKey ? 'true' : 'false' ?>">
      <span><?= h($g['name']) ?></span>
      <?php if ($total > 0): ?><span class="badge" title="確認が必要な件数"><?= h($total) ?></span><?php endif; ?>
    </button>
    <?php endforeach; ?>
  </div>
  <div class="menu-panels">
    <?php foreach ($groups as $key => $g): ?>
    <section class="menu-panel" role="tabpanel" id="menuPanel-<?= h($key) ?>" aria-labelledby="menuTab-<?= h($key) ?>">
      <h2><?= h($g['name']) ?></h2>
      <div class="menu-buttons">
        <?php foreach ($g['items'] as [$label, $url, $n, $badgeLabel]): ?>
        <a href="<?= h($url) ?>" class="menu-button"><?= h($label) ?><?= badge($n, $badgeLabel) ?></a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>
</div>
<?php require_once __DIR__ . '/common/footer.php'; ?>
