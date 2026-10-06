<?php
// 業務メニューの定義（SC-01 メニューと、全画面左のサイドナビで共通）
// 発注確定は発注承認可（can_approve_order=1）の操作者にだけ出す（質問No.5）
//   items：['label' => 表示名, 'url' => URL, 'desc' => ひとこと説明]
function menuGroups(): array
{
    $isApprover = currentOperator()['can_approve_order'] === 1;
    $groups = [
        'order' => ['name' => '発注', 'items' => array_values(array_filter([
            ['label' => '発注入力',     'url' => '/order/order_input.php',         'desc' => '新しい発注を作る'],
            $isApprover ? ['label' => '発注確定', 'url' => '/order/order_confirm.php', 'desc' => '卸業者へ送る'] : null,
            ['label' => '未納品一覧表', 'url' => '/delivery/undelivered_print.php', 'desc' => '届いていない商品'],
        ]))],
        'delivery' => ['name' => '納品・返品', 'items' => [
            ['label' => '納品入力',       'url' => '/delivery/delivery_input.php',         'desc' => '届いた数を入れる'],
            ['label' => '返品入力',       'url' => '/return_goods/return_goods_input.php', 'desc' => '卸業者へ返す'],
            ['label' => '納品・返品確定', 'url' => '/delivery/delivery_confirm.php',       'desc' => '在庫に反映'],
        ]],
        'sales' => ['name' => '売上・棚卸', 'items' => [
            ['label' => '売上入力', 'url' => '/sales/sales_input.php',             'desc' => '売れた数を入れる'],
            ['label' => '売上確定', 'url' => '/sales/sales_confirm.php',           'desc' => '在庫から引く'],
            ['label' => '棚卸入力', 'url' => '/stocktaking/stocktaking_input.php', 'desc' => '実際の在庫を数える'],
        ]],
        'report' => ['name' => '管理業務', 'items' => [
            ['label' => '発注明細表',   'url' => '/report/order_list.php',          'desc' => ''],
            ['label' => '発注集計',     'url' => '/report/order_summary.php',       'desc' => ''],
            ['label' => '納品明細表',   'url' => '/report/delivery_list.php',       'desc' => ''],
            ['label' => '納品集計',     'url' => '/report/delivery_summary.php',    'desc' => ''],
            ['label' => '返品明細表',   'url' => '/report/return_goods_list.php',   'desc' => ''],
            ['label' => '返品集計',     'url' => '/report/return_goods_summary.php', 'desc' => ''],
            ['label' => '売上明細表',   'url' => '/report/sales_list.php',          'desc' => ''],
            ['label' => '売上集計',     'url' => '/report/sales_summary.php',       'desc' => ''],
            ['label' => '棚卸調整一覧', 'url' => '/report/stocktaking_list.php',    'desc' => ''],
            ['label' => '卸業者別金額', 'url' => '/report/supplier_summary.php',    'desc' => ''],
        ]],
        'master' => ['name' => 'マスタ管理', 'items' => [
            ['label' => '商品マスタ',   'url' => '/master/product_list.php',  'desc' => ''],
            ['label' => '卸業者マスタ', 'url' => '/master/supplier_list.php', 'desc' => ''],
            ['label' => '操作者マスタ', 'url' => '/master/operator_list.php', 'desc' => ''],
        ]],
    ];
    return $groups;
}

// 左のサイドナビ（全画面共通）。管理業務は項目が多いので「明細表・集計」1本でメニューの該当箇所へ飛ばす
// 今の画面（REQUEST_URI のパス）に aria-current を付ける。編集画面などは同じフォルダの一覧を今の画面とみなす
function renderSideNav(): void
{
    $self = strtok($_SERVER['REQUEST_URI'], '?');
    $dir  = dirname($self);
    echo '<aside class="side-nav" aria-label="業務メニュー">';
    echo '<a href="/menu.php" class="side-link side-home"' . ($self === '/menu.php' ? ' aria-current="page"' : '') . '>メニュー</a>';
    foreach (menuGroups() as $key => $g) {
        echo '<div class="side-group"><div class="side-group-name">' . h($g['name']) . '</div>';
        $items = $key === 'report'
            ? [['label' => '明細表・集計', 'url' => '/menu.php#report', 'desc' => '']]
            : $g['items'];
        foreach ($items as $it) {
            $path = strtok($it['url'], '#');
            if ($key === 'report') {
                $isCurrent = $dir === '/report';                       // 明細表・集計はどの帳票でも「今の画面」
            } elseif ($key === 'master') {
                $isCurrent = $dir === '/master' && strpos(basename($self), strtok(basename($it['url']), '_') . '_') === 0;   // product_edit も商品マスタ
            } else {
                $isCurrent = $path === $self;
            }
            echo '<a href="' . h($it['url']) . '" class="side-link"' . ($isCurrent ? ' aria-current="page"' : '') . '>' . h($it['label']) . '</a>';
        }
        echo '</div>';
    }
    echo '</aside>';
}
