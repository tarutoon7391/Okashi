// SC-40 棚卸入力：実数を入れると差異（実数 − 在庫数）を表示し、差異がある行は理由を必須にする
// あわせて、入力した商品数・差異のある商品数を集計タイル（#diffCount）と固定バー（#actualCount / #diffCountBar）に出す
(() => {
    const rows = document.querySelectorAll('.js-stock-row');
    const actualCountEl = document.getElementById('actualCount');
    const diffCountEl   = document.getElementById('diffCount');
    const diffBarEl     = document.getElementById('diffCountBar');

    function updateSummary() {
        let entered = 0;
        let differed = 0;
        rows.forEach((row) => {
            const actual = row.querySelector('.js-actual');
            if (actual.value === '') {
                return;
            }
            entered++;
            if (Math.trunc(Number(actual.value)) - Number(row.dataset.stock) !== 0) {
                differed++;
            }
        });
        if (actualCountEl) { actualCountEl.textContent = String(entered); }
        if (diffBarEl)     { diffBarEl.textContent = String(differed); }
        if (diffCountEl) {
            diffCountEl.textContent = differed + '商品';
            diffCountEl.closest('.stat').classList.toggle('warn', differed > 0);
        }
    }

    rows.forEach((row) => {
        const actual = row.querySelector('.js-actual');
        const diffCell = row.querySelector('.js-diff');
        const reason = row.querySelector('.js-reason');
        const stock = Number(row.dataset.stock);

        function update() {
            if (actual.value === '') {
                diffCell.textContent = '';
                reason.required = false;
                row.classList.remove('qty-minus');
                diffCell.classList.remove('qty-minus');
                updateSummary();
                return;
            }
            const diff = Math.trunc(Number(actual.value)) - stock;
            diffCell.textContent = diff > 0 ? '+' + diff : String(diff);
            reason.required = diff !== 0;
            row.classList.toggle('qty-minus', diff < 0);
            diffCell.classList.toggle('qty-minus', diff !== 0);   // 差異があれば赤（06-4 プロンプト3）
            updateSummary();
        }

        actual.addEventListener('input', update);
        update();
    });
})();
