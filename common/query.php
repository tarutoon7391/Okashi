<?php
// 複数の画面で使う取得SQL（SELECT のみ。更新はしない）
//   ・発注伝票1枚の取得と発注書/検品一覧表のHTML …… order_print / inspection_print / sendOrderMail
//   ・未納品一覧 ……………………………………………… order_input（取消元の候補）/ delivery_input / undelivered_print
//   ・返品できる納品明細 ………………………………… return_goods_input
//   ・プルダウン用のマスタ一覧 …………………………… 各画面
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// ---------------------------------------------------------------
// マスタの選択肢（削除済みは出さない）
// ---------------------------------------------------------------
function getSupplierOptions(): array
{
    return getDb()->query(
        'SELECT supplier_code, supplier_name, order_email FROM m_supplier
          WHERE is_deleted = 0 ORDER BY supplier_code'
    )->fetchAll();
}

function getProductOptions(): array
{
    return getDb()->query(
        'SELECT product_code, product_name, spec, storage_type FROM m_product
          WHERE is_deleted = 0 ORDER BY product_kana, product_code'
    )->fetchAll();
}

// ---------------------------------------------------------------
// 発注伝票1枚（ヘッダ＋卸業者＋明細）。無ければ null
// 発注書（SC-12）と検品一覧表（SC-13）で共通（02 画面一覧 F-13 備考）
// ---------------------------------------------------------------
function getOrderSlip(int $orderNo): ?array
{
    $pdo = getDb();
    $st  = $pdo->prepare(
        'SELECT o.*, s.supplier_name, s.office_address, s.order_email, s.contact_name
           FROM t_order o JOIN m_supplier s ON s.supplier_code = o.supplier_code
          WHERE o.order_no = :no'
    );
    $st->execute([':no' => $orderNo]);
    $slip = $st->fetch();
    if ($slip === false) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT d.*, p.product_name, p.spec, p.pack_qty, p.unit, p.storage_type
           FROM t_order_detail d JOIN m_product p ON p.product_code = d.product_code
          WHERE d.order_no = :no ORDER BY d.line_no'
    );
    $st->execute([':no' => $orderNo]);
    $slip['details']      = $st->fetchAll();
    $slip['total_qty']    = array_sum(array_column($slip['details'], 'order_qty'));
    $slip['total_amount'] = array_sum(array_column($slip['details'], 'amount'));
    return $slip;
}

// 発注書・検品一覧表のHTML（TCPDF 用に table と属性だけで組む）
// $kind：'order' = 発注書 / 'inspection' = 卸業者別検品一覧表（検品チェック欄つき）
function buildOrderSheetHtml(array $slip, string $kind): string
{
    $isOrder = $kind === 'order';
    $title   = $isOrder ? '発 注 書' : '卸業者別 検品一覧表';
    $html  = '<h1 style="text-align:center;font-size:16pt;">' . $title . '</h1>';
    $html .= '<table cellpadding="3" width="100%"><tr>'
           . '<td width="60%">';
    if ($isOrder) {
        $html .= '<span style="font-size:13pt;">' . h($slip['supplier_name']) . ' 御中</span><br>'
               . h($slip['office_address']) . '<br>';
    }
    $html .= '卸業者コード：' . h($slip['supplier_code'])
           . ($isOrder ? '' : '　卸業者名：' . h($slip['supplier_name']))
           . '</td><td width="40%" align="right">'
           . '発注日：' . h(formatDate($slip['order_date'])) . '<br>'
           . '発注伝票No：' . h($slip['order_no']) . '<br>'
           . ($isOrder ? '発注元：' . h(SHOP_NAME) : '')
           . '</td></tr></table><br>';

    $html .= '<table border="1" cellpadding="3" width="100%">'
           . '<tr style="background-color:#e6ecf5;">'
           . '<th width="11%">商品コード</th><th width="' . ($isOrder ? 25 : 22) . '%">商品名</th>'
           . '<th width="10%">規格</th><th width="6%">入数</th><th width="7%">数量</th>'
           . '<th width="10%">契約単価</th><th width="11%">納品金額</th>'
           . '<th width="' . ($isOrder ? 20 : 17) . '%">発注時メモ</th>'
           . ($isOrder ? '' : '<th width="6%">検品</th>')
           . '</tr>';
    foreach ($slip['details'] as $d) {
        $color = (int)$d['order_qty'] < 0 ? ' style="color:#c53030;"' : '';
        $storage = storageTypeName((int)$d['storage_type']);
        $html .= '<tr' . $color . '>'
               . '<td>' . h($d['product_code']) . '</td>'
               . '<td>' . h($d['product_name']) . ((int)$d['storage_type'] > 0 ? '【' . h($storage) . '】' : '') . '</td>'
               . '<td>' . h($d['spec']) . '</td>'
               . '<td align="right">' . h($d['pack_qty']) . '</td>'
               . '<td align="right">' . h($d['order_qty']) . '</td>'
               . '<td align="right">' . h(formatYen($d['contract_price'])) . '</td>'
               . '<td align="right">' . h(formatYen($d['amount'])) . '</td>'
               . '<td>' . h($d['memo']) . '</td>'
               . ($isOrder ? '' : '<td align="center">□</td>')
               . '</tr>';
    }
    $html .= '<tr style="background-color:#f0f0f0;"><td colspan="4" align="right">合計</td>'
           . '<td align="right">' . h($slip['total_qty']) . '</td><td></td>'
           . '<td align="right">' . h(formatYen($slip['total_amount'])) . '</td>'
           . '<td colspan="' . ($isOrder ? 1 : 2) . '"></td></tr>';
    $html .= '</table>';
    if ($isOrder) {
        $html .= '<p>※ マイナス数量の行は取消（訂正）です。金額は税を含みません。</p>';
    }
    return $html;
}

// ---------------------------------------------------------------
// 未納品一覧（F-20 / F-22）
// 確定済みのプラスの発注明細ごとに
//   残数 = 発注数 ＋ 取消数合計（確定済みのマイナス明細で ref が一致するもの）
//        − 納品数合計（is_return=0 の納品伝票。未確定も含める＝二重に納品入力しないため）
// を計算し、残数 > 0 の行だけ返す。取消明細自体と返品伝票は含めない（04 t_order_detail ※）
// $filter：supplier_code / product_code / date_from / date_to（発注日） いずれも省略可
// ---------------------------------------------------------------
function getUndeliveredList(array $filter = []): array
{
    $where  = ['o.is_confirmed = 1', 'd.order_qty > 0', 'd.ref_order_no IS NULL'];
    $params = [];
    if (!empty($filter['supplier_code'])) {
        $where[] = 'o.supplier_code = :supplier_code';
        $params[':supplier_code'] = $filter['supplier_code'];
    }
    if (!empty($filter['product_code'])) {
        $where[] = 'd.product_code = :product_code';
        $params[':product_code'] = $filter['product_code'];
    }
    if (!empty($filter['date_from'])) {
        $where[] = 'o.order_date >= :date_from';
        $params[':date_from'] = $filter['date_from'];
    }
    if (!empty($filter['date_to'])) {
        $where[] = 'o.order_date <= :date_to';
        $params[':date_to'] = $filter['date_to'];
    }
    $sql = 'SELECT d.order_no, d.line_no, o.order_date, o.supplier_code, s.supplier_name,
                   d.product_code, p.product_name, p.spec, p.pack_qty, p.unit, p.storage_type,
                   d.order_qty, d.contract_price, d.memo,
                   COALESCE(c.cancel_qty, 0)    AS cancel_qty,
                   COALESCE(v.delivered_qty, 0) AS delivered_qty,
                   d.order_qty + COALESCE(c.cancel_qty, 0) - COALESCE(v.delivered_qty, 0) AS remain_qty
              FROM t_order_detail d
              JOIN t_order o    ON o.order_no = d.order_no
              JOIN m_supplier s ON s.supplier_code = o.supplier_code
              JOIN m_product p  ON p.product_code = d.product_code
              LEFT JOIN (
                    SELECT x.ref_order_no, x.ref_line_no, SUM(x.order_qty) AS cancel_qty
                      FROM t_order_detail x
                      JOIN t_order xo ON xo.order_no = x.order_no AND xo.is_confirmed = 1
                     WHERE x.ref_order_no IS NOT NULL
                     GROUP BY x.ref_order_no, x.ref_line_no
                   ) c ON c.ref_order_no = d.order_no AND c.ref_line_no = d.line_no
              LEFT JOIN (
                    SELECT dd.order_no, dd.order_line_no, SUM(dd.delivery_qty) AS delivered_qty
                      FROM t_delivery_detail dd
                      JOIN t_delivery dh ON dh.delivery_no = dd.delivery_no AND dh.is_return = 0
                     GROUP BY dd.order_no, dd.order_line_no
                   ) v ON v.order_no = d.order_no AND v.order_line_no = d.line_no
             WHERE ' . implode(' AND ', $where) . '
            HAVING remain_qty > 0
             ORDER BY o.supplier_code, o.order_date, d.order_no, d.line_no';
    $st = getDb()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

// 未納品明細1行（order_no, line_no）。無い・残数0なら null
function findUndeliveredLine(int $orderNo, int $lineNo, array $filter = []): ?array
{
    foreach (getUndeliveredList($filter) as $row) {
        if ((int)$row['order_no'] === $orderNo && (int)$row['line_no'] === $lineNo) {
            return $row;
        }
    }
    return null;
}

// ---------------------------------------------------------------
// 返品できる納品明細（F-50）
// 発注明細ごとに 返品可能数 = 確定済み納品数（is_return=0）＋ 返品数合計（is_return=1、マイナス。未確定も含む）
// 返品可能数 > 0 の行を返す
// ---------------------------------------------------------------
function getReturnableList(string $supplierCode): array
{
    $st = getDb()->prepare(
        'SELECT dd.order_no, dd.order_line_no, o.order_date, dd.product_code,
                p.product_name, p.spec, p.pack_qty, p.storage_type, od.contract_price,
                SUM(CASE WHEN dh.is_return = 0 AND dh.is_confirmed = 1 THEN dd.delivery_qty ELSE 0 END) AS delivered_qty,
                MAX(CASE WHEN dh.is_return = 0 AND dh.is_confirmed = 1 THEN dh.delivery_date END)     AS last_delivery_date,
                SUM(CASE WHEN dh.is_return = 1 THEN dd.delivery_qty ELSE 0 END)                         AS returned_qty
           FROM t_delivery_detail dd
           JOIN t_delivery dh     ON dh.delivery_no = dd.delivery_no
           JOIN t_order o         ON o.order_no = dd.order_no
           JOIN t_order_detail od ON od.order_no = dd.order_no AND od.line_no = dd.order_line_no
           JOIN m_product p       ON p.product_code = dd.product_code
          WHERE dh.supplier_code = :supplier_code
          GROUP BY dd.order_no, dd.order_line_no, o.order_date, dd.product_code,
                   p.product_name, p.spec, p.pack_qty, p.storage_type, od.contract_price
         HAVING delivered_qty + returned_qty > 0
          ORDER BY last_delivery_date DESC, dd.order_no, dd.order_line_no'
    );
    $st->execute([':supplier_code' => $supplierCode]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['returnable_qty'] = (int)$r['delivered_qty'] + (int)$r['returned_qty'];
    }
    unset($r);
    return $rows;
}
