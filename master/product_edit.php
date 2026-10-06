<?php
// SC-71 商品登録・編集（F-70）担当A
// ?product_code=XXX があれば編集モード、無ければ新規（旧 ?code=XXX も互換のため受け付ける）。新規登録と同時に在庫（t_stock）を stock_qty=0 で作る
// 入数違いは別の商品コードで登録する（質問No.1）。保存区分・賞味期限日数（質問No.12）
require_once __DIR__ . '/../common/auth.php';
require_once __DIR__ . '/../common/db.php';
require_once __DIR__ . '/../common/functions.php';
require_once __DIR__ . '/../common/query.php';
require_once __DIR__ . '/../common/slip.php';
requireLogin();
$pdo = getDb();
$errors = [];

$fields = ['product_code', 'product_name', 'product_kana', 'spec', 'pack_qty', 'unit', 'jan_code', 'list_price',
           'maker_name', 'supplier_code', 'contract_price', 'memo', 'storage_type', 'shelf_life_days'];
$editCode = $_GET['product_code'] ?? ($_GET['code'] ?? null);
$editCode = is_string($editCode) ? $editCode : '';
$isEdit = $editCode !== '';

// JANコード（13桁）のチェックデジットが正しいか。奇数桁×1＋偶数桁×3 の合計から求める
function isValidJanCheckDigit(string $jan): bool
{
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int)$jan[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    return (10 - $sum % 10) % 10 === (int)$jan[12];
}

// 写真のキャッシュ対策：ファイルの更新時刻を ?v= に付ける（差し替え後に古い画像が出ないように）
function photoVersion(string $photoPath): int
{
    $file = PRODUCT_IMG_DIR . '/' . $photoPath;
    return is_file($file) ? (int)filemtime($file) : 0;
}
$product = array_fill_keys($fields, '') + ['photo_path' => null];
$product['pack_qty'] = 1;
$product['unit'] = '個';
$product['storage_type'] = 0;

if ($isEdit) {
    $st = $pdo->prepare('SELECT * FROM m_product WHERE product_code = :code AND is_deleted = 0');
    $st->execute([':code' => $editCode]);
    $found = $st->fetch();
    if ($found === false) {
        setFlash('error', '商品が見つかりません');
        redirect('/master/product_list.php');
    }
    $product = $found;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. 入力値の取得・バリデーション
    $oldJan   = (string)($product['jan_code'] ?? '');     // 登録済みのJAN（変更していなければチェックデジットは見ない）
    $oldPhoto = $product['photo_path'] ?? null;           // 差し替え・削除時に古いファイルを消すため
    foreach ($fields as $f) {
        $product[$f] = postStr($f);
    }
    if ($isEdit) {
        $product['product_code'] = $editCode;   // コードは変更しない
    }
    $p = $product;
    if (!preg_match('/\A[A-Za-z0-9]{1,13}\z/', $p['product_code'])) {
        $errors[] = '商品コードは英数字13文字以内で入力してください';
    } elseif (!$isEdit) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM m_product WHERE product_code = :code');   // 削除済みも含めて重複チェック
        $st->execute([':code' => $p['product_code']]);
        if ((int)$st->fetchColumn() > 0) {
            $errors[] = 'その商品コードは既に使われています（削除済みの商品を含む）';
        }
    }
    if ($p['product_name'] === '' || mb_strlen($p['product_name']) > 100) {
        $errors[] = '商品名を100文字以内で入力してください';
    }
    // 全角カタカナのみ（ァ〜ヶ：ヴ・ヵ・ヶを含む、長音ー、中黒・、全角スペース）。数字・英字・ひらがなは不可
    if (!preg_match('/\A[ァ-ヶー・　]{1,100}\z/u', $p['product_kana']) || trim($p['product_kana'], '　') === '') {
        $errors[] = '商品名カナは全角カタカナのみで入力してください（数字・英字・ひらがなは使えません）';
    }
    if (mb_strlen($p['spec']) > 50) {
        $errors[] = '規格は50文字以内で入力してください';
    }
    if (toIntOrNull($p['pack_qty']) === null || (int)$p['pack_qty'] < 1) {
        $errors[] = '入数は1以上の整数で入力してください';
    }
    if ($p['unit'] === '' || mb_strlen($p['unit']) > 10) {
        $errors[] = '単位を10文字以内で入力してください';
    }
    if ($p['jan_code'] !== '') {
        if (!preg_match('/\A\d{13}\z/', $p['jan_code'])) {
            $errors[] = 'JANコードは13桁の数字で入力してください（未入力も可）';
        } elseif ($p['jan_code'] !== $oldJan && !isValidJanCheckDigit($p['jan_code'])) {
            // 初期データ（sql/02）のJANはダミー値なので、変更していないときは通す
            $errors[] = 'JANコードのチェックデジット（13桁目）が正しくありません。バーコードの数字を確認してください';
        }
    }
    if (toIntOrNull($p['list_price']) === null || (int)$p['list_price'] < 0) {
        $errors[] = '定価は0以上の整数（円）で入力してください';
    }
    if (toIntOrNull($p['contract_price']) === null || (int)$p['contract_price'] < 0) {
        $errors[] = '契約単価は0以上の整数（円）で入力してください';
    }
    if (mb_strlen($p['maker_name']) > 100) {
        $errors[] = 'メーカー名は100文字以内で入力してください';
    }
    if (!in_array($p['supplier_code'], array_column(getSupplierOptions(), 'supplier_code'), true)) {
        $errors[] = '契約卸を選んでください';
    }
    if (!in_array($p['storage_type'], ['0', '1', '2'], true)) {
        $errors[] = '保存区分を選んでください';
    }
    if ($p['shelf_life_days'] !== '' && (toIntOrNull($p['shelf_life_days']) === null || (int)$p['shelf_life_days'] < 0)) {
        $errors[] = '賞味期限日数は0以上の整数で入力してください';
    }

    // 写真（任意）：img/product/ に「商品コード.拡張子」で保存し、ファイル名だけDBに入れる
    $photoPath = $oldPhoto;
    $uploaded = false;
    $upload = $_FILES['photo'] ?? null;
    if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $mime = $upload['error'] === UPLOAD_ERR_OK ? (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']) : '';
        if ($upload['error'] !== UPLOAD_ERR_OK || !isset($ext[$mime]) || $upload['size'] > 2 * 1024 * 1024) {
            $errors[] = '写真は2MB以下の JPEG / PNG / GIF / WebP にしてください';
        } elseif (!$errors) {
            $photoPath = $p['product_code'] . '.' . $ext[$mime];
            if (!is_dir(PRODUCT_IMG_DIR)) {
                mkdir(PRODUCT_IMG_DIR, 0775, true);
            }
            if (move_uploaded_file($upload['tmp_name'], PRODUCT_IMG_DIR . '/' . $photoPath)) {
                $uploaded = true;
            } else {
                $photoPath = $oldPhoto;
                $errors[] = '写真を保存できませんでした';
            }
        }
    }
    // 「写真を外す」は新しい写真を選ばなかったときだけ効かせる（選んだときは差し替え）
    if (postStr('delete_photo') === '1' && !$uploaded) {
        $photoPath = null;
    }

    // 2. DB更新
    if (!$errors) {
        $by = currentOperator()['operator_code'];
        $values = [
            ':code'     => $p['product_code'],
            ':name'     => $p['product_name'],
            ':kana'     => $p['product_kana'],
            ':spec'     => $p['spec'] === '' ? null : $p['spec'],
            ':pack'     => (int)$p['pack_qty'],
            ':unit'     => $p['unit'],
            ':jan'      => $p['jan_code'] === '' ? null : $p['jan_code'],
            ':list'     => (int)$p['list_price'],
            ':maker'    => $p['maker_name'] === '' ? null : $p['maker_name'],
            ':supplier' => $p['supplier_code'],
            ':contract' => (int)$p['contract_price'],
            ':photo'    => $photoPath,
            ':memo'     => $p['memo'] === '' ? null : $p['memo'],
            ':storage'  => (int)$p['storage_type'],
            ':shelf'    => $p['shelf_life_days'] === '' ? null : (int)$p['shelf_life_days'],
            ':by'       => $by,
        ];
        try {
            $pdo->beginTransaction();
            if ($isEdit) {
                $st = $pdo->prepare(
                    'UPDATE m_product SET product_name = :name, product_kana = :kana, spec = :spec, pack_qty = :pack, unit = :unit,
                            jan_code = :jan, list_price = :list, maker_name = :maker, supplier_code = :supplier,
                            contract_price = :contract, photo_path = :photo, memo = :memo, storage_type = :storage,
                            shelf_life_days = :shelf, updated_by = :by
                      WHERE product_code = :code AND is_deleted = 0'
                );
                $st->execute($values);
            } else {
                $values[':by2'] = $by;
                $st = $pdo->prepare(
                    'INSERT INTO m_product (product_code, product_name, product_kana, spec, pack_qty, unit, jan_code, list_price,
                            maker_name, supplier_code, contract_price, photo_path, memo, storage_type, shelf_life_days, created_by, updated_by)
                     VALUES (:code, :name, :kana, :spec, :pack, :unit, :jan, :list, :maker, :supplier, :contract, :photo, :memo,
                            :storage, :shelf, :by, :by2)'
                );
                $st->execute($values);
                addStock($p['product_code'], 0, $by);   // 在庫行を stock_qty=0 で作る（無いと納品確定で困る）
            }
            $pdo->commit();
            // 写真を外した・拡張子違いで差し替えたときは、古いファイルを消す（同じファイル名なら上書き済み）
            if ($oldPhoto !== null && $oldPhoto !== '' && $oldPhoto !== $photoPath) {
                $oldFile = PRODUCT_IMG_DIR . '/' . basename($oldPhoto);
                if (is_file($oldFile) && !@unlink($oldFile)) {
                    error_log('product_edit: 古い写真を削除できませんでした ' . $oldFile);
                }
            }
            setFlash('success', '商品「' . $p['product_name'] . '」を' . ($isEdit ? '更新' : '登録') . 'しました');
            redirect('/master/product_list.php');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('product_edit: ' . $e->getMessage());
            $errors[] = '保存に失敗しました。時間をおいてもう一度お試しください';
        }
    }
    setFlash('error', implode("\n", $errors));
}

$suppliers = getSupplierOptions();
$pageTitle = $isEdit ? '商品編集' : '商品登録';
require_once __DIR__ . '/../common/header.php';
?>
<form method="post" enctype="multipart/form-data">
  <div class="card form-card">
  <div class="form-grid">
    <label for="productCode">商品コード <span class="req">必須</span></label>
    <?php if ($isEdit): ?>
      <div><strong><?= h($product['product_code']) ?></strong>（変更できません）</div>
    <?php else: ?>
      <input type="text" id="productCode" name="product_code" value="<?= h($product['product_code']) ?>" maxlength="13" pattern="[A-Za-z0-9]+" title="英数字13文字以内" required>
    <?php endif; ?>
    <label for="productName">商品名 <span class="req">必須</span></label>
    <input type="text" id="productName" name="product_name" value="<?= h($product['product_name']) ?>" maxlength="100" required>
    <label for="productKana">商品名カナ <span class="req">必須</span></label>
    <input type="text" id="productKana" name="product_kana" value="<?= h($product['product_kana']) ?>" maxlength="100" placeholder="全角カタカナ（一覧の並び順）" required>
    <label for="spec">規格</label>
    <input type="text" id="spec" name="spec" value="<?= h($product['spec']) ?>" maxlength="50" placeholder="例：12枚入">
    <label for="packQty">入数 <span class="req">必須</span></label>
    <input type="number" id="packQty" name="pack_qty" value="<?= h($product['pack_qty']) ?>" min="1" required>
    <label for="unit">単位 <span class="req">必須</span></label>
    <input type="text" id="unit" name="unit" value="<?= h($product['unit']) ?>" maxlength="10" required>
    <label for="janCode">JANコード</label>
    <input type="text" id="janCode" name="jan_code" value="<?= h($product['jan_code']) ?>" maxlength="13" inputmode="numeric" pattern="\d{13}" title="13桁の数字" placeholder="13桁の数字（任意）">
    <label for="listPrice">定価（円） <span class="req">必須</span></label>
    <input type="number" id="listPrice" name="list_price" value="<?= h($product['list_price']) ?>" min="0" required>
    <label for="makerName">メーカー名</label>
    <input type="text" id="makerName" name="maker_name" value="<?= h($product['maker_name']) ?>" maxlength="100">
    <label for="supplierCode">契約卸 <span class="req">必須</span></label>
    <select id="supplierCode" name="supplier_code" required>
      <option value="">選択してください</option>
      <?php foreach ($suppliers as $s): ?>
        <option value="<?= h($s['supplier_code']) ?>" <?= $product['supplier_code'] === $s['supplier_code'] ? 'selected' : '' ?>><?= h($s['supplier_code'] . ' ' . $s['supplier_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="contractPrice">契約単価（円） <span class="req">必須</span></label>
    <input type="number" id="contractPrice" name="contract_price" value="<?= h($product['contract_price']) ?>" min="0" required>
    <label>保存区分 <span class="req">必須</span></label>
    <div>
      <?php foreach ([0 => '常温', 1 => '冷蔵', 2 => '冷凍'] as $v => $label): ?>
        <label class="radio"><input type="radio" name="storage_type" value="<?= $v ?>" <?= (string)$product['storage_type'] === (string)$v ? 'checked' : '' ?>> <?= h($label) ?></label>
      <?php endforeach; ?>
    </div>
    <label for="shelfLifeDays">賞味期限日数</label>
    <input type="number" id="shelfLifeDays" name="shelf_life_days" value="<?= h($product['shelf_life_days']) ?>" min="0">
    <label for="photo">写真</label>
    <div>
      <?php if (!empty($product['photo_path'])): ?>
        <img src="/img/product/<?= h(rawurlencode($product['photo_path'])) ?>?v=<?= h(photoVersion($product['photo_path'])) ?>" alt="" class="thumb-large"><br>
        <label class="radio"><input type="checkbox" name="delete_photo" value="1"> 写真を外す</label><br>
      <?php endif; ?>
      <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/gif,image/webp">
    </div>
    <label for="memo">補足メモ</label>
    <textarea id="memo" name="memo" rows="3"><?= h($product['memo']) ?></textarea>
  </div>
  </div>
  <div class="action-bar"><div class="bar-in">
  <span class="bar-spacer"></span>
    <button type="submit" class="btn btn-primary">保存</button>
    <a href="/master/product_list.php" class="btn">一覧へ戻る</a>
</div></div>
</form>
<?php require_once __DIR__ . '/../common/footer.php'; ?>
