// 共通JS（05 §8）。ライブラリは使わない。グローバルに出すのは下の関数だけ

// 確定・削除ボタンの onsubmit / onclick で使う。キャンセルなら false を返して送信を止める
function confirmSubmit(msg) {
    return window.confirm(msg);
}

// 12345 → '¥12,345'（PHP の formatYen と同じ）
function formatYen(n) {
    n = Math.trunc(Number(n) || 0);
    return (n < 0 ? '-' : '') + '¥' + Math.abs(n).toLocaleString('ja-JP');
}

// 数量×単価を計算して金額セルに表示する。発注入力・納品入力で共通
// 行（tr）に data-price、数量入力に .js-qty、金額セルに .js-amount を付けておく
function calcAmount(rowEl) {
    const price = Number(rowEl.dataset.price || 0);
    const qtyEl = rowEl.querySelector('.js-qty');
    const qty = qtyEl ? Math.trunc(Number(qtyEl.value) || 0) : 0;
    const amount = price * qty;
    const cell = rowEl.querySelector('.js-amount');
    if (cell) {
        cell.textContent = price && qty ? formatYen(amount) : '';
    }
    rowEl.classList.toggle('qty-minus', qty < 0);
    return amount;
}

// 商品コードから商品情報を取得（master/product_api.php）。見つからなければ null
async function fetchProduct(code) {
    if (!code) {
        return null;
    }
    try {
        const res = await fetch('/master/product_api.php?code=' + encodeURIComponent(code));
        if (!res.ok) {
            return null;
        }
        const data = await res.json();
        return data && data.product_code ? data : null;
    } catch (e) {
        return null;
    }
}

// 画面共通：一覧の「すべて選択」チェック（.js-check-all）で同じフォームの .js-check を切り替える
document.addEventListener('change', (e) => {
    if (!e.target.classList.contains('js-check-all')) {
        return;
    }
    const form = e.target.closest('form') || document;
    form.querySelectorAll('.js-check').forEach((el) => { el.checked = e.target.checked; });
    document.dispatchEvent(new Event('check-all'));
});

// 画面共通：data-confirm 属性を付けたフォームは送信前に確認する
document.addEventListener('submit', (e) => {
    const msg = e.target.dataset.confirm;
    if (msg && !confirmSubmit(msg)) {
        e.preventDefault();
    }
});
