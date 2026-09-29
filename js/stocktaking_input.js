// SC-40 棚卸入力：実数を入れると差異（実数 − 在庫数）を表示し、差異がある行は理由を必須にする
(() => {
    document.querySelectorAll('.js-stock-row').forEach((row) => {
        const actual = row.querySelector('.js-actual');
        const diffCell = row.querySelector('.js-diff');
        const reason = row.querySelector('.js-reason');
        const stock = Number(row.dataset.stock);

        function update() {
            if (actual.value === '') {
                diffCell.textContent = '';
                reason.required = false;
                row.classList.remove('qty-minus');
                return;
            }
            const diff = Math.trunc(Number(actual.value)) - stock;
            diffCell.textContent = diff > 0 ? '+' + diff : String(diff);
            reason.required = diff !== 0;
            row.classList.toggle('qty-minus', diff < 0);
        }

        actual.addEventListener('input', update);
        update();
    });
})();
