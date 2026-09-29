<?php
// SC-22 未納品一覧表（F-22）担当C　PDF帳票（質問No.6）
// 確定済みで納品が完了していない発注明細を、卸業者・期間（発注日）で絞り込んで表示する
//   ?pdf=1 を付けると同じ条件でPDF出力
// 未納品の計算は納品入力と共通の getUndeliveredList()
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/query.php';
require_once __DIR__ . '/../common/pdf.php';
requireLogin();

$filter = [
    'supplier_code' => is_string($_GET['supplier_code'] ?? null) ? $_GET['supplier_code'] : '',
    'date_from'     => is_string($_GET['date_from'] ?? null) && isValidDate($_GET['date_from']) ? $_GET['date_from'] : '',
    'date_to'       => is_string($_GET['date_to'] ?? null) && isValidDate($_GET['date_to']) ? $_GET['date_to'] : '',
];
$list = getUndeliveredList($filter);

// 卸業者ごとにまとめる
$groups = [];
foreach ($list as $u) {
    $groups[$u['supplier_code']]['name'] = $u['supplier_name'];
    $groups[$u['supplier_code']]['rows'][] = $u;
}

if (isset($_GET['pdf'])) {
    $cond = '発注日：' . ($filter['date_from'] ? formatDate($filter['date_from']) : '指定なし') . ' 〜 '
          . ($filter['date_to'] ? formatDate($filter['date_to']) : '指定なし');
    $html = '<h1 style="text-align:center;font-size:16pt;">未納品一覧表</h1>'
          . '<p>' . h($cond) . '　出力日時：' . h(date('Y/m/d H:i')) . '</p>';
    if (!$groups) {
        $html .= '<p>未納品はありません。</p>';
    }
    foreach ($groups as $code => $g) {
        $html .= '<h3>' . h($code . ' ' . $g['name']) . '</h3>'
               . '<table border="1" cellpadding="3" width="100%"><tr style="background-color:#e6ecf5;">'
               . '<th width="10%">発注No</th><th width="11%">発注日</th><th width="12%">商品コード</th><th width="27%">商品名</th>'
               . '<th width="10%">規格</th><th width="7%">入数</th><th width="7%">発注数</th><th width="8%">納品済</th><th width="8%">残数量</th></tr>';
        foreach ($g['rows'] as $u) {
            $html .= '<tr><td>' . h($u['order_no'] . '-' . $u['line_no']) . '</td>'
                   . '<td>' . h(formatDate($u['order_date'])) . '</td>'
                   . '<td>' . h($u['product_code']) . '</td>'
                   . '<td>' . h($u['product_name']) . '</td>'
                   . '<td>' . h($u['spec']) . '</td>'
                   . '<td align="right">' . h($u['pack_qty']) . '</td>'
                   . '<td align="right">' . h((int)$u['order_qty'] + (int)$u['cancel_qty']) . '</td>'
                   . '<td align="right">' . h($u['delivered_qty']) . '</td>'
                   . '<td align="right">' . h($u['remain_qty']) . '</td></tr>';
        }
        $html .= '</table>';
    }
    outputPdf('未納品一覧表', $html, 'undelivered.pdf', 'I');
}

$suppliers = getSupplierOptions();
$pageTitle = '未納品一覧表';
require_once __DIR__ . '/../common/header.php';
?>
<form method="get" class="filter-form">
  <label>卸業者
    <select name="supplier_code">
      <option value="">すべて</option>
      <?php foreach ($suppliers as $s): ?>
        <option value="<?= h($s['supplier_code']) ?>" <?= $filter['supplier_code'] === $s['supplier_code'] ? 'selected' : '' ?>><?= h($s['supplier_code'] . ' ' . $s['supplier_name']) ?></option>
      <?php endforeach; ?>
    </select></label>
  <label>発注日 <input type="date" name="date_from" value="<?= h($filter['date_from']) ?>">
    〜 <input type="date" name="date_to" value="<?= h($filter['date_to']) ?>"></label>
  <button type="submit" class="btn btn-primary">表示</button>
  <button type="submit" name="pdf" value="1" formtarget="_blank" class="btn">PDF出力</button>
  <a href="/delivery/undelivered_print.php" class="btn">条件クリア</a>
</form>

<?php if (!$groups): ?>
  <p>未納品はありません。</p>
<?php endif; ?>
<?php foreach ($groups as $code => $g): ?>
<h2><?= h($code . ' ' . $g['name']) ?>（<?= count($g['rows']) ?>件）</h2>
<table class="data-table">
  <thead>
    <tr><th>発注No</th><th>発注日</th><th>商品</th><th>入数</th><th>発注数</th><th>取消</th><th>納品済</th><th>残数量</th><th>発注時メモ</th></tr>
  </thead>
  <tbody>
    <?php foreach ($g['rows'] as $u): ?>
    <tr>
      <td><?= h($u['order_no'] . '-' . $u['line_no']) ?></td>
      <td><?= h(formatDate($u['order_date'])) ?></td>
      <td><?= h($u['product_code'] . ' ' . $u['product_name'] . ' ' . $u['spec']) ?><?= storageBadge($u['storage_type']) ?></td>
      <td class="num"><?= h($u['pack_qty']) ?></td>
      <td class="num"><?= h($u['order_qty']) ?></td>
      <td class="num <?= minusClass($u['cancel_qty']) ?>"><?= (int)$u['cancel_qty'] !== 0 ? h($u['cancel_qty']) : '' ?></td>
      <td class="num"><?= h($u['delivered_qty']) ?></td>
      <td class="num"><strong><?= h($u['remain_qty']) ?></strong></td>
      <td><?= h($u['memo']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endforeach; ?>
<div class="btn-area">
  <a href="/delivery/delivery_input.php" class="btn btn-primary">納品入力へ</a>
  <a href="/delivery/delivery_confirm.php" class="btn">納品確定へ戻る</a>
  <a href="/menu.php" class="btn">メニューへ戻る</a>
</div>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
