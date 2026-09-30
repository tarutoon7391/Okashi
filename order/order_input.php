<?php
// SC-10 発注入力（F-10）担当B
// 1回の登録で「卸業者ごとに1伝票」に分けて t_order / t_order_detail を作る（未確定）
// 数量はマイナス可・0不可。マイナス行は取消元の発注明細（ref_order_no / ref_line_no）を選ぶ（質問No.3）
// マイナス行の単価・卸業者は取消元の発注明細からコピーする（04 t_order_detail：発注時点の契約単価）
// 明細の入力欄は product_code[0] のように行番号つきの名前にする（取消元プルダウンが無効でも行がずれないように）
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/query.php';
requireLogin();
$pdo = getDb();
$errors = [];

$orderDate = date('Y-m-d');
$rows = [];

// 取消元の候補（確定済み・未納品の明細）
// 未確定の発注伝票に入っている取消（マイナス行）も残数から引く（まだ確定していない取消との二重取消を防ぐ）
$pendingCancel = [];
$st = $pdo->query(
    'SELECT x.ref_order_no, x.ref_line_no, SUM(x.order_qty) AS cancel_qty
       FROM t_order_detail x
       JOIN t_order xo ON xo.order_no = x.order_no AND xo.is_confirmed = 0
      WHERE x.ref_order_no IS NOT NULL
      GROUP BY x.ref_order_no, x.ref_line_no'
);
foreach ($st->fetchAll() as $r) {
    $pendingCancel[$r['ref_order_no'] . '-' . $r['ref_line_no']] = (int)$r['cancel_qty'];   // マイナスの値
}
$candidates = [];
foreach (getUndeliveredList() as $u) {
    $key = $u['order_no'] . '-' . $u['line_no'];
    $u['pending_qty']    = $pendingCancel[$key] ?? 0;
    $u['cancelable_qty'] = (int)$u['remain_qty'] + $u['pending_qty'];   // 取消できる数 = 残数 − 未確定の取消
    $candidates[$key] = $u;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. 入力値の取得・バリデーション
    $orderDate = postStr('order_date');
    if (!isValidDate($orderDate)) {
        $errors[] = '発注日を正しく入力してください';
    }
    $codes = postArray('product_code');
    $qtys  = postArray('order_qty');
    $memos = postArray('memo');
    $refs  = postArray('ref');

    // プラス行は削除済みの商品を使えない。マイナス行（取消）は削除済みの商品でも取消できる
    $findProduct = $pdo->prepare(
        'SELECT product_code, product_name, supplier_code, contract_price
           FROM m_product WHERE product_code = :code AND is_deleted = 0'
    );
    $findAnyProduct = $pdo->prepare(
        'SELECT product_code, product_name, supplier_code, contract_price
           FROM m_product WHERE product_code = :code'
    );
    $cancelUsed = [];   // 同じ取消元を複数行で使ったときの合計
    $lines = [];        // 登録する明細（卸業者ごとにまとめる前）

    foreach ($codes as $i => $code) {
        $code = is_string($code) ? trim($code) : '';
        $qtyRaw = is_string($qtys[$i] ?? null) ? trim($qtys[$i]) : '';
        $memo = is_string($memos[$i] ?? null) ? trim($memos[$i]) : '';
        $ref  = is_string($refs[$i] ?? null) ? $refs[$i] : '';
        $rows[] = ['product_code' => $code, 'order_qty' => $qtyRaw, 'memo' => $memo, 'ref' => $ref];
        if ($code === '' && $qtyRaw === '') {
            continue;   // 空行
        }
        $n = count($rows);
        $qty = toIntOrNull($qtyRaw);
        if ($qty === null || $qty === 0) {
            $errors[] = "{$n}行目：数量は0以外の整数で入力してください（マイナス可）";
            continue;
        }
        $finder = $qty < 0 ? $findAnyProduct : $findProduct;
        $finder->execute([':code' => $code]);
        $product = $finder->fetch();
        if ($product === false) {
            $errors[] = "{$n}行目：商品コード「{$code}」は登録されていません";
            continue;
        }
        if (mb_strlen($memo) > 200) {
            $errors[] = "{$n}行目：発注時メモは200文字以内で入力してください";
        }
        $supplierCode  = $product['supplier_code'];
        $contractPrice = (int)$product['contract_price'];
        $refOrderNo = null;
        $refLineNo  = null;
        if ($qty < 0) {
            // 取消元はマイナス行のときだけ見る（プラス行で送られてきても無視）
            $cand = $candidates[$ref] ?? null;
            if ($cand === null || $cand['product_code'] !== $code) {
                $errors[] = "{$n}行目：マイナス数量の行は取消元の発注明細を選んでください";
                continue;
            }
            $cancelUsed[$ref] = ($cancelUsed[$ref] ?? 0) + (-$qty);
            if ($cancelUsed[$ref] > $cand['cancelable_qty']) {
                $msg = "{$n}行目：取消数が取消元の取消できる数（{$cand['cancelable_qty']}）を超えています";
                if ($cand['pending_qty'] < 0) {
                    $msg .= '（未確定の取消 ' . (-$cand['pending_qty']) . ' を引いた数）';
                }
                $errors[] = $msg;
                continue;
            }
            $refOrderNo = (int)$cand['order_no'];
            $refLineNo  = (int)$cand['line_no'];
            // 取消は元の発注と同じ卸業者・同じ契約単価で作る（マスタの現在値は使わない）
            $supplierCode  = $cand['supplier_code'];
            $contractPrice = (int)$cand['contract_price'];
        }
        $lines[] = [
            'supplier_code'  => $supplierCode,
            'product_code'   => $code,
            'order_qty'      => $qty,
            'contract_price' => $contractPrice,
            'memo'           => $memo === '' ? null : $memo,
            'ref_order_no'   => $refOrderNo,
            'ref_line_no'    => $refLineNo,
        ];
    }
    if (!$errors && !$lines) {
        $errors[] = '明細を1行以上入力してください';
    }

    // 2. DB更新（卸業者ごとに伝票を分ける）
    if (!$errors) {
        $bySupplier = [];
        foreach ($lines as $line) {
            $bySupplier[$line['supplier_code']][] = $line;
        }
        $by = currentOperator()['operator_code'];
        try {
            $pdo->beginTransaction();
            $insOrder = $pdo->prepare(
                'INSERT INTO t_order (order_date, supplier_code, created_by, updated_by)
                 VALUES (:date, :supplier, :by, :by2)'
            );
            $insDetail = $pdo->prepare(
                'INSERT INTO t_order_detail
                     (order_no, line_no, product_code, order_qty, contract_price, amount, memo,
                      ref_order_no, ref_line_no, created_by, updated_by)
                 VALUES (:no, :line, :code, :qty, :price, :amount, :memo, :ref_no, :ref_line, :by, :by2)'
            );
            $orderNos = [];
            foreach ($bySupplier as $supplierCode => $supplierLines) {
                $insOrder->execute([':date' => $orderDate, ':supplier' => $supplierCode, ':by' => $by, ':by2' => $by]);
                $orderNo = (int)$pdo->lastInsertId();
                $orderNos[] = $orderNo;
                foreach ($supplierLines as $i => $line) {
                    $insDetail->execute([
                        ':no'       => $orderNo,
                        ':line'     => $i + 1,
                        ':code'     => $line['product_code'],
                        ':qty'      => $line['order_qty'],
                        ':price'    => $line['contract_price'],
                        ':amount'   => $line['contract_price'] * $line['order_qty'],
                        ':memo'     => $line['memo'],
                        ':ref_no'   => $line['ref_order_no'],
                        ':ref_line' => $line['ref_line_no'],
                        ':by'       => $by,
                        ':by2'      => $by,
                    ]);
                }
            }
            $pdo->commit();
            // 3. 完了メッセージ → 同じ画面へ（連続入力）
            setFlash('success', '発注伝票を登録しました（伝票No：' . implode(', ', $orderNos) . '）。発注確定で確定してください');
            redirect('/order/order_input.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            // 詳しい内容はログにだけ出す（画面にSQLの内容などを出さない）
            error_log('[order_input] ' . $e->getMessage());
            $errors[] = '登録に失敗しました。時間をおいてもう一度やり直してください';
        }
    }
    // エラーは header.php のフラッシュで出す（入力値は残したまま再表示）
    setFlash('error', implode("\n", $errors));
}

// GET時：表示用データ
while (count($rows) < 5) {
    $rows[] = ['product_code' => '', 'order_qty' => '', 'memo' => '', 'ref' => ''];
}
// 取消元の候補。JS が商品コードで絞り込んでプルダウンに出す（金額は取消元の契約単価で計算する）
$cancelCandidates = array_map(fn($u) => [
    'key'            => $u['order_no'] . '-' . $u['line_no'],
    'product_code'   => $u['product_code'],
    'contract_price' => (int)$u['contract_price'],
    'label'          => 'No.' . $u['order_no'] . '-' . $u['line_no'] . '（' . formatDate($u['order_date'])
                        . ' 残' . $u['cancelable_qty']
                        . ($u['pending_qty'] < 0 ? '・未確定の取消' . (-$u['pending_qty']) . 'あり' : '') . '）',
], array_values(array_filter($candidates, fn($u) => $u['cancelable_qty'] > 0)));   // 取消できる数が0の明細は出さない
$products = getProductOptions();

$pageTitle  = '発注入力';
$pageScript = 'order_input.js';
require_once __DIR__ . '/../common/header.php';
?>
<form method="post" id="orderForm">
  <div class="form-grid">
    <label for="orderDate">発注日</label>
    <input type="date" id="orderDate" name="order_date" value="<?= h($orderDate) ?>" required>
  </div>
  <p class="note">商品コードを入れると商品情報が出ます。卸業者が違う商品は自動で別の伝票になります。
    取消・訂正は数量をマイナスにして、取消元の発注明細を選んでください（単価は取消元の発注時の単価になります）。</p>

  <table class="data-table order-input-table">
    <thead>
      <tr><th>No</th><th>商品コード</th><th>商品情報</th><th>数量</th><th>取消元（マイナス時）</th><th>発注時メモ</th><th>納品金額</th><th></th></tr>
    </thead>
    <tbody id="orderRows">
      <?php foreach ($rows as $i => $r): ?>
      <tr class="js-order-row" data-price="0">
        <td class="num js-line-no"><?= $i + 1 ?></td>
        <td><input type="text" name="product_code[<?= $i ?>]" class="js-code" value="<?= h($r['product_code']) ?>" list="productList" maxlength="13" size="10"></td>
        <td class="js-info"></td>
        <td><input type="number" name="order_qty[<?= $i ?>]" class="js-qty" value="<?= h($r['order_qty']) ?>" step="1"></td>
        <td><select name="ref[<?= $i ?>]" class="js-ref" data-selected="<?= h($r['ref']) ?>"><option value="">―</option></select></td>
        <td><input type="text" name="memo[<?= $i ?>]" value="<?= h($r['memo']) ?>" maxlength="200"></td>
        <td class="num js-amount"></td>
        <td><button type="button" class="btn btn-small js-remove">削除</button></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="6" class="num">合計</td><td class="num" id="orderTotal"></td><td></td></tr>
    </tfoot>
  </table>
  <datalist id="productList">
    <?php foreach ($products as $p): ?>
      <option value="<?= h($p['product_code']) ?>"><?= h($p['product_name'] . ' ' . $p['spec']) ?></option>
    <?php endforeach; ?>
  </datalist>
  <script id="cancelCandidates" type="application/json"><?= json_encode($cancelCandidates, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

  <div class="btn-area">
    <button type="button" class="btn" id="addRowButton">＋行追加</button>
    <button type="submit" class="btn btn-primary">登録</button>
    <a href="/menu.php" class="btn">メニューへ戻る</a>
  </div>
</form>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
