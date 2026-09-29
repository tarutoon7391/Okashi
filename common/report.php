<?php
// 管理業務（SC-60〜SC-69）の明細表・集計で共通の絞り込み（画面設計_E「共通レイアウト」）
//   期間 from / to（未指定なら当月）・卸業者・商品・状態
require_once __DIR__ . '/query.php';

// GET パラメータから絞り込み条件を作る
function getReportFilter(): array
{
    $from = $_GET['date_from'] ?? '';
    $to   = $_GET['date_to'] ?? '';
    return [
        'date_from'     => is_string($from) && isValidDate($from) ? $from : date('Y-m-01'),
        'date_to'       => is_string($to) && isValidDate($to) ? $to : date('Y-m-t'),
        'supplier_code' => is_string($_GET['supplier_code'] ?? null) ? $_GET['supplier_code'] : '',
        'product_code'  => is_string($_GET['product_code'] ?? null) ? $_GET['product_code'] : '',
        'status'        => in_array($_GET['status'] ?? '', ['0', '1'], true) ? $_GET['status'] : '',
    ];
}

// 絞り込み条件を WHERE 句にする
// $cols：['date' => 'o.order_date', 'supplier' => 'o.supplier_code', 'product' => 'd.product_code', 'status' => 'o.is_confirmed']
// 使わない条件はキーを省略する
function buildReportWhere(array $filter, array $cols, array &$params): string
{
    $where = [];
    if (isset($cols['date'])) {
        $where[] = $cols['date'] . ' BETWEEN :date_from AND :date_to';
        $params[':date_from'] = $filter['date_from'];
        $params[':date_to']   = $filter['date_to'];
    }
    if (isset($cols['supplier']) && $filter['supplier_code'] !== '') {
        $where[] = $cols['supplier'] . ' = :supplier_code';
        $params[':supplier_code'] = $filter['supplier_code'];
    }
    if (isset($cols['product']) && $filter['product_code'] !== '') {
        $where[] = $cols['product'] . ' = :product_code';
        $params[':product_code'] = $filter['product_code'];
    }
    if (isset($cols['status']) && $filter['status'] !== '') {
        $where[] = $cols['status'] . ' = :status';
        $params[':status'] = (int)$filter['status'];
    }
    return $where ? implode(' AND ', $where) : '1 = 1';
}

// 絞り込みフォームを出力する
// $options：['supplier' => bool（売上・棚卸系は false）, 'product' => bool, 'status' => bool]
function renderReportFilter(array $filter, array $options = []): void
{
    $options += ['supplier' => true, 'product' => true, 'status' => true];
    $self = strtok($_SERVER['REQUEST_URI'], '?');
    ?>
<form method="get" class="filter-form">
  <label>期間 <input type="date" name="date_from" value="<?= h($filter['date_from']) ?>">
    〜 <input type="date" name="date_to" value="<?= h($filter['date_to']) ?>"></label>
  <?php if ($options['supplier']): ?>
  <label>卸業者
    <select name="supplier_code">
      <option value="">すべて</option>
      <?php foreach (getSupplierOptions() as $s): ?>
        <option value="<?= h($s['supplier_code']) ?>" <?= $filter['supplier_code'] === $s['supplier_code'] ? 'selected' : '' ?>>
          <?= h($s['supplier_code'] . ' ' . $s['supplier_name']) ?></option>
      <?php endforeach; ?>
    </select></label>
  <?php endif; ?>
  <?php if ($options['product']): ?>
  <label>商品
    <select name="product_code">
      <option value="">すべて</option>
      <?php foreach (getProductOptions() as $p): ?>
        <option value="<?= h($p['product_code']) ?>" <?= $filter['product_code'] === $p['product_code'] ? 'selected' : '' ?>>
          <?= h($p['product_code'] . ' ' . $p['product_name'] . ' ' . $p['spec']) ?></option>
      <?php endforeach; ?>
    </select></label>
  <?php endif; ?>
  <?php if ($options['status']): ?>
  <label>状態
    <select name="status">
      <option value="">すべて</option>
      <option value="1" <?= $filter['status'] === '1' ? 'selected' : '' ?>>確定済のみ</option>
      <option value="0" <?= $filter['status'] === '0' ? 'selected' : '' ?>>未確定のみ</option>
    </select></label>
  <?php endif; ?>
  <button type="submit" class="btn btn-primary">表示</button>
  <a href="<?= h($self) ?>" class="btn">条件クリア</a>
</form>
    <?php
}
