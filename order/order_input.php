<?php
// SC-10 発注入力（F-10）担当B
// 1回の登録で「卸業者ごとに1伝票」に分けて t_order / t_order_detail を作る（未確定）
// 数量はマイナス可・0不可。マイナス行は取消元の発注明細（ref_order_no / ref_line_no）を選ぶ（質問No.3）
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/query.php';
requireLogin();
$pdo = getDb();
$errors = [];

$orderDate = date('Y-m-d');
$rows = [];

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

    $findProduct = $pdo->prepare(
        'SELECT product_code, product_name, supplier_code, contract_price
           FROM m_product WHERE product_code = :code AND is_deleted = 0'
    );
    $candidates = [];
    foreach (getUndeliveredList() as $u) {
        $candidates[$u['order_no'] . '-' . $u['line_no']] = $u;
    }
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
        $findProduct->execute([':code' => $code]);
        $product = $findProduct->fetch();
        if ($product === false) {
            $errors[] = "{$n}行目：商品コード「{$code}」は登録されていません";
            continue;
        }
        $qty = toIntOrNull($qtyRaw);
        if ($qty === null || $qty === 0) {
            $errors[] = "{$n}行目：数量は0以外の整数で入力してください（マイナス可）";
            continue;
        }
        if (mb_strlen($memo) > 200) {
            $errors[] = "{$n}行目：発注時メモは200文字以内で入力してください";
        }
        $refOrderNo = null;
        $refLineNo  = null;
        if ($qty < 0) {
            $cand = $candidates[$ref] ?? null;
            if ($cand === null || $cand['product_code'] !== $code) {
                $errors[] = "{$n}行目：マイナス数量の行は取消元の発注明細を選んでください";
                continue;
            }
            $cancelUsed[$ref] = ($cancelUsed[$ref] ?? 0) + (-$qty);
            if ($cancelUsed[$ref] > (int)$cand['remain_qty']) {
                $errors[] = "{$n}行目：取消数が取消元の未納品数（{$cand['remain_qty']}）を超えています";
                continue;
            }
            $refOrderNo = (int)$cand['order_no'];
            $refLineNo  = (int)$cand['line_no'];
        }
        $lines[] = [
            'supplier_code'  => $product['supplier_code'],
            'product_code'   => $code,
            'order_qty'      => $qty,
            'contract_price' => (int)$product['contract_price'],
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
            $errors[] = '登録に失敗しました：' . $e->getMessage();
        }
    }
    // エラーは header.php のフラッシュで出す（入力値は残したまま再表示）
    setFlash('error', implode("\n", $errors));
}

// GET時：表示用データ
while (count($rows) < 5) {
    $rows[] = ['product_code' => '', 'order_qty' => '', 'memo' => '', 'ref' => ''];
}
// 取消元の候補（確定済み・未納品の明細）。JS が商品コードで絞り込んでプルダウンに出す
$cancelCandidates = array_map(fn($u) => [
    'key'          => $u['order_no'] . '-' . $u['line_no'],
    'product_code' => $u['product_code'],
    'label'        => 'No.' . $u['order_no'] . '-' . $u['line_no'] . '（' . formatDate($u['order_date']) . ' 残' . $u['remain_qty'] . '）',
], getUndeliveredList());
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
    取消・訂正は数量をマイナスにして、取消元の発注明細を選んでください。</p>

  <table class="data-table order-input-table">
    <thead>
      <tr><th>No</th><th>商品コード</th><th>商品情報</th><th>数量</th><th>取消元（マイナス時）</th><th>発注時メモ</th><th>納品金額</th><th></th></tr>
    </thead>
    <tbody id="orderRows">
      <?php foreach ($rows as $i => $r): ?>
      <tr class="js-order-row" data-price="0">
        <td class="num js-line-no"><?= $i + 1 ?></td>
        <td><input type="text" name="product_code[]" class="js-code" value="<?= h($r['product_code']) ?>" list="productList" maxlength="13" size="10"></td>
        <td class="js-info"></td>
        <td><input type="number" name="order_qty[]" class="js-qty" value="<?= h($r['order_qty']) ?>" step="1"></td>
        <td><select name="ref[]" class="js-ref" data-selected="<?= h($r['ref']) ?>" disabled><option value="">―</option></select></td>
        <td><input type="text" name="memo[]" value="<?= h($r['memo']) ?>" maxlength="200"></td>
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
