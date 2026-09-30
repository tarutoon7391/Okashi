// SC-20 納品入力：チェックした行だけ数量・メモを入力可にし、金額（calcAmount）と合計を出す
// 金額は全行に出す（チェックしていない行は参考表示として .note で薄く）。合計はチェックした行だけ
(() => {
    const rows = document.querySelectorAll('.js-delivery-row');
    const totalCell = document.getElementById('deliveryTotal');
    if (!totalCell) {
        return;
    }

    function update() {
        let total = 0;
        rows.forEach((row) => {
            const checked = row.querySelector('.js-check').checked;
            row.querySelectorAll('input[type="number"], input[type="text"]').forEach((el) => { el.disabled = !checked; });
            const amount = calcAmount(row);
            row.querySelector('.js-amount').classList.toggle('note', !checked);
            if (checked) {
                total += amount;
            }
        });
        totalCell.textContent = formatYen(total);
    }

    rows.forEach((row) => {
        row.querySelector('.js-check').addEventListener('change', update);
        row.querySelector('.js-qty').addEventListener('input', update);
    });
    document.addEventListener('check-all', update);
    update();
})();
