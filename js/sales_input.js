// SC-30 売上入力 担当D
// 売上数を入れると 売上金額（定価 × 売上数）・状態・合計をその場で計算する（登録時の金額はサーバ側で計算し直す）
// 行（tr.js-sales-row）に data-price・data-registered（登録済みの数量）・data-status（none/pending/done）を付けておく
(() => {
    const pills = {
        none:    '<span class="st st-none">未登録</span>',
        pending: '<span class="st status-unconfirmed">未確定</span>',
        done:    '<span class="st status-confirmed">確定済</span>',
        editing: '<span class="st st-editing">入力中</span>',
    };
    const rows = document.querySelectorAll('.js-sales-row');
    const totalQtyEl    = document.getElementById('salesTotalQty');
    const totalAmountEl = document.getElementById('salesTotalAmount');

    function update() {
        let totalQty = 0;
        let totalAmount = 0;
        rows.forEach((row) => {
            const input = row.querySelector('.js-sales-qty');
            const cell  = row.querySelector('.js-sales-amount');
            const state = row.querySelector('.js-sales-state');
            const v = input.value.trim();
            const registered = row.dataset.registered;
            if (v === '' || !/^-?\d+$/.test(v)) {
                cell.textContent = '';
                cell.classList.remove('neg');
                input.classList.remove('qty-minus');
                // 登録済みの数量を消した＝入力中（登録すると差分の訂正行になる）
                state.innerHTML = pills[registered === '' ? 'none' : 'editing'];
                return;
            }
            const qty = parseInt(v, 10);
            const amount = qty * Number(row.dataset.price);
            cell.textContent = formatYen(amount);
            cell.classList.toggle('neg', amount < 0);
            input.classList.toggle('qty-minus', qty < 0);
            state.innerHTML = pills[String(qty) === registered ? row.dataset.status : 'editing'];
            totalQty += qty;
            totalAmount += amount;
        });
        totalQtyEl.textContent = String(totalQty);
        totalQtyEl.classList.toggle('neg', totalQty < 0);
        totalAmountEl.textContent = formatYen(totalAmount);
        totalAmountEl.classList.toggle('neg', totalAmount < 0);
    }
    rows.forEach((row) => row.querySelector('.js-sales-qty').addEventListener('input', update));
    update();
})();
