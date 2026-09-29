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
$pageTitle = 'メニュー';
require_once __DIR__ . '/common/header.php';
?>
<div class="menu-grid">
  <section class="menu-group">
    <h2>発注</h2>
    <ul>
      <li><a href="/order/order_input.php">発注入力</a></li>
      <?php if ($op['can_approve_order'] === 1): ?>
      <li><a href="/order/order_confirm.php">発注確定</a><?= badge((int)$count['order_cnt'], '未確定') ?></li>
      <?php endif; ?>
      <li><a href="/delivery/undelivered_print.php">未納品一覧表</a></li>
    </ul>
  </section>
  <section class="menu-group">
    <h2>納品・返品</h2>
    <ul>
      <li><a href="/delivery/delivery_input.php">納品入力</a></li>
      <li><a href="/delivery/delivery_confirm.php">納品確定</a><?= badge((int)$count['delivery_cnt'], '未確定') ?></li>
      <li><a href="/return_goods/return_goods_input.php">返品伝票</a></li>
    </ul>
  </section>
  <section class="menu-group">
    <h2>売上・棚卸</h2>
    <ul>
      <li><a href="/sales/sales_input.php">売上入力</a></li>
      <li><a href="/sales/sales_confirm.php">売上確定</a><?= badge((int)$count['sales_cnt'], '未確定') ?></li>
      <li><a href="/stocktaking/stocktaking_input.php">棚卸</a><?= badge((int)$count['stock_cnt'], '在庫0以下') ?></li>
    </ul>
  </section>
  <section class="menu-group">
    <h2>管理業務</h2>
    <ul>
      <li><a href="/report/order_list.php">発注明細表</a> ／ <a href="/report/order_summary.php">発注集計</a></li>
      <li><a href="/report/delivery_list.php">納品明細表</a> ／ <a href="/report/delivery_summary.php">納品集計</a></li>
      <li><a href="/report/return_goods_list.php">返品明細表</a> ／ <a href="/report/return_goods_summary.php">返品集計</a></li>
      <li><a href="/report/sales_list.php">売上明細表</a> ／ <a href="/report/sales_summary.php">売上集計</a></li>
      <li><a href="/report/stocktaking_list.php">棚卸調整一覧表</a></li>
      <li><a href="/report/supplier_summary.php">卸業者別納品金額</a></li>
    </ul>
  </section>
  <section class="menu-group">
    <h2>マスタ管理</h2>
    <ul>
      <li><a href="/master/product_list.php">商品マスタ</a></li>
      <li><a href="/master/supplier_list.php">卸業者マスタ</a></li>
      <li><a href="/master/operator_list.php">操作者マスタ</a></li>
    </ul>
  </section>
</div>
<?php require_once __DIR__ . '/common/footer.php'; ?>
