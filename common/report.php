<?php
// 管理業務（SC-60〜SC-69）の明細表・集計で共通の絞り込み（画面設計_E「共通レイアウト」）
//   期間 from / to（未指定なら当月）・卸業者・商品・状態
require_once __DIR__ . '/query.php';

// GET パラメータから絞り込み条件を作る
//   ・期間：両方未指定なら当月。片方だけ指定なら、もう片方は指定した日の月初／月末で補う
//           （当月で補うと from > to になることがあるため）。from > to なら入れ替えて警告を出す
//   ・状態：未指定は確定済みのみ（06-4 / 06-5「確定済み（is_confirmed=1）のみ」）。
//           '0' = 未確定のみ、'all' = 未確定も含むすべて（確認用のオプション）
function getReportFilter(): array
{
    $from = $_GET['date_from'] ?? '';
    $to   = $_GET['date_to'] ?? '';
    $from = is_string($from) && isValidDate($from) ? $from : '';
    $to   = is_string($to) && isValidDate($to) ? $to : '';
    if ($from === '' && $to === '') {
        $from = date('Y-m-01');
        $to   = date('Y-m-t');
    } elseif ($to === '') {
        $to = date('Y-m-t', strtotime($from));
    } elseif ($from === '') {
        $from = date('Y-m-01', strtotime($to));
    } elseif ($from > $to) {
        [$from, $to] = [$to, $from];
        setFlash('warning', '期間の開始日が終了日より後だったため、入れ替えて表示しています');
    }
    $status = $_GET['status'] ?? '1';
    return [
        'date_from'     => $from,
        'date_to'       => $to,
        'supplier_code' => is_string($_GET['supplier_code'] ?? null) ? $_GET['supplier_code'] : '',
        'product_code'  => is_string($_GET['product_code'] ?? null) ? $_GET['product_code'] : '',
        'status'        => in_array($status, ['0', '1', 'all'], true) ? $status : '1',
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
    // 状態は 'all' のときだけ絞らない（既定は確定済みのみ）
    if (isset($cols['status']) && $filter['status'] !== 'all') {
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
          <?= h($p['product_code'] . ' ' . productLabel($p['product_name'], $p['spec'])) ?></option>
      <?php endforeach; ?>
    </select></label>
  <?php endif; ?>
  <?php if ($options['status']): ?>
  <label>状態
    <select name="status">
      <option value="1" <?= $filter['status'] === '1' ? 'selected' : '' ?>>確定済のみ（既定）</option>
      <option value="all" <?= $filter['status'] === 'all' ? 'selected' : '' ?>>未確定も含めてすべて</option>
      <option value="0" <?= $filter['status'] === '0' ? 'selected' : '' ?>>未確定のみ</option>
    </select></label>
  <?php endif; ?>
  <button type="submit" class="btn btn-primary">表示</button>
  <a href="<?= h($self) ?>" class="btn">条件クリア</a>
</form>
    <?php
}

// ---------------------------------------------------------------
// 明細表の PDF 出力（SC-60/62/64/66/68 の ?pdf=1）
//   画面と同じ絞り込み条件で、同じ行を横向き A4 の PDF にする。
//   使い方：各 report/*_list.php で行を取ったあと
//     if (isReportPdfRequested()) { outputReportPdf($title, $fileBase, $filter, $options, $columns, $groups, $foot); }
// ---------------------------------------------------------------

// ?pdf=1 が付いているか
function isReportPdfRequested(): bool
{
    return isset($_GET['pdf']) && $_GET['pdf'] !== '';
}

// 「PDF出力」ボタン（今の絞り込み条件をそのまま引き継ぐ）。h() 済みのHTMLを返す
function reportPdfButton(): string
{
    $self  = strtok($_SERVER['REQUEST_URI'], '?');
    $query = $_GET;
    unset($query['pdf']);
    $query['pdf'] = '1';
    return '<a href="' . h($self . '?' . http_build_query($query)) . '" target="_blank" class="btn btn-primary">PDF出力</a>';
}

// 絞り込み条件を1行の文（PDF の見出し用）にする。$options は renderReportFilter() と同じ
function reportFilterText(array $filter, array $options = []): string
{
    $options += ['supplier' => true, 'product' => true, 'status' => true];
    $parts = ['期間：' . formatDate($filter['date_from']) . '〜' . formatDate($filter['date_to'])];
    if ($options['supplier']) {
        $name = 'すべて';
        if ($filter['supplier_code'] !== '') {
            $name = $filter['supplier_code'];
            foreach (getSupplierOptions() as $s) {
                if ($s['supplier_code'] === $filter['supplier_code']) {
                    $name .= ' ' . $s['supplier_name'];
                    break;
                }
            }
        }
        $parts[] = '卸業者：' . $name;
    }
    if ($options['product']) {
        $name = 'すべて';
        if ($filter['product_code'] !== '') {
            $name = $filter['product_code'];
            foreach (getProductOptions() as $p) {
                if ($p['product_code'] === $filter['product_code']) {
                    $name .= ' ' . productLabel($p['product_name'], $p['spec']);
                    break;
                }
            }
        }
        $parts[] = '商品：' . $name;
    }
    if ($options['status']) {
        $parts[] = '状態：' . ['1' => '確定済のみ', 'all' => '未確定も含めてすべて', '0' => '未確定のみ'][$filter['status']];
    }
    return implode('　', $parts);
}

// PDF 用：商品名＋保存区分（画面の storageBadge の代わりに【冷蔵】のような文字で出す）
function pdfProductText($name, $spec, $storageType): string
{
    $type = (int)$storageType;
    return productLabel($name, $spec) . ($type > 0 ? '【' . storageTypeName($type) . '】' : '');
}

// 明細表 PDF を作って表示する（ここで exit する）
//   $title    … 帳票名（発注明細表 など）
//   $fileBase … ファイル名の先頭（order_list など。ASCII）
//   $options  … renderReportFilter() と同じ（絞り込み条件の文に使う）
//   $columns  … [['label' => '発注日', 'width' => 9, 'align' => 'right'], ...]  width は % で合計 100
//   $groups   … [['label' => 'W001 株式会社…'（null なら見出し行なし）,
//                  'rows'  => [['cells' => [...], 'minus' => true/false], ...],  cells は列と同じ数の文字列（未エスケープ）
//                  'subtotal' => [...cells...] か null], ...]
//   $foot     … 合計行の cells（null なら出さない）
//   $note     … 表の下の注記（省略時は取消・訂正の説明）
function outputReportPdf(string $title, string $fileBase, array $filter, array $options, array $columns, array $groups, ?array $foot,
                         string $note = '※ 赤字の行はマイナス（取消・訂正）です。金額は税を含みません。'): void
{
    require_once __DIR__ . '/pdf.php';

    $html  = '<h1 style="text-align:center;font-size:15pt;">' . h($title) . '</h1>';
    $html .= '<table cellpadding="2" width="100%"><tr>'
           . '<td width="70%">' . h(reportFilterText($filter, $options)) . '</td>'
           . '<td width="30%" align="right">出力日時：' . h(date('Y/m/d H:i')) . '<br>'
           . h(SHOP_NAME) . '</td></tr></table><br>';

    $colCount = count($columns);
    $html .= '<table border="1" cellpadding="3" width="100%" style="font-size:8pt;"><thead><tr style="background-color:#e6ecf5;">';
    foreach ($columns as $c) {
        $html .= '<th width="' . (int)$c['width'] . '%" align="center">' . h($c['label']) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    $cellsHtml = function (array $cells, string $rowStyle = '') use ($columns): string {
        $out = '<tr' . ($rowStyle !== '' ? ' style="' . $rowStyle . '"' : '') . '>';
        foreach ($cells as $i => $text) {
            // TCPDF は見出し行の幅を本体行に引き継がないので、全セルに幅を付ける
            $align = $columns[$i]['align'] ?? 'left';
            $out .= '<td width="' . (int)$columns[$i]['width'] . '%" align="' . $align . '">' . h((string)$text) . '</td>';
        }
        return $out . '</tr>';
    };

    $hasRow = false;
    foreach ($groups as $g) {
        if (!empty($g['label'])) {
            $html .= '<tr style="background-color:#f3f5f8;"><td colspan="' . $colCount . '"><b>' . h($g['label']) . '</b></td></tr>';
        }
        foreach ($g['rows'] as $r) {
            $hasRow = true;
            $html .= $cellsHtml($r['cells'], !empty($r['minus']) ? 'color:#c53030;' : '');
        }
        if (!empty($g['subtotal'])) {
            $html .= $cellsHtml($g['subtotal'], 'background-color:#fafafa;');
        }
    }
    if (!$hasRow) {
        $html .= '<tr><td colspan="' . $colCount . '" align="center">該当するデータがありません</td></tr>';
    }
    if ($foot !== null && $hasRow) {
        $html .= $cellsHtml($foot, 'background-color:#f0f0f0;font-weight:bold;');
    }
    $html .= '</tbody></table>';
    $html .= '<p style="font-size:8pt;">' . h($note) . '</p>';

    outputPdf($title, $html, $fileBase . '_' . $filter['date_from'] . '_' . $filter['date_to'] . '.pdf', 'I', 'L');
}
