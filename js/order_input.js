// SC-10 発注入力：行追加・商品情報表示（fetchProduct）・金額計算（calcAmount）・取消元プルダウン
(() => {
    const tbody = document.getElementById('orderRows');
    const totalCell = document.getElementById('orderTotal');
    const candidates = JSON.parse(document.getElementById('cancelCandidates').textContent);

    function storageBadge(type) {
        if (type === 1) return '<span class="storage-chilled">冷蔵</span>';
        if (type === 2) return '<span class="storage-frozen">冷凍</span>';
        return '';
    }

    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s == null ? '' : String(s);
        return div.innerHTML;
    }

    function updateTotal() {
        let total = 0;
        tbody.querySelectorAll('.js-order-row').forEach((row) => { total += calcAmount(row); });
        totalCell.textContent = formatYen(total);
    }

    function renumber() {
        tbody.querySelectorAll('.js-line-no').forEach((cell, i) => { cell.textContent = i + 1; });
    }

    // 数量がマイナスのときだけ、同じ商品の取消元候補をプルダウンに出す
    function updateRef(row) {
        const select = row.querySelector('.js-ref');
        const code = row.querySelector('.js-code').value.trim();
        const qty = Number(row.querySelector('.js-qty').value) || 0;
        const selected = select.value || select.dataset.selected || '';
        select.innerHTML = '<option value="">―</option>';
        if (qty >= 0) {
            select.disabled = true;
            return;
        }
        const list = candidates.filter((c) => c.product_code === code);
        list.forEach((c) => {
            const opt = document.createElement('option');
            opt.value = c.key;
            opt.textContent = c.label;
            if (c.key === selected) opt.selected = true;
            select.appendChild(opt);
        });
        if (list.length === 0) {
            select.options[0].textContent = '取消できる発注がありません';
        }
        select.disabled = false;
        select.dataset.selected = '';
    }

    async function loadProduct(row) {
        const code = row.querySelector('.js-code').value.trim();
        const info = row.querySelector('.js-info');
        if (!code) {
            row.dataset.price = 0;
            info.innerHTML = '';
            updateRef(row);
            updateTotal();
            return;
        }
        const p = await fetchProduct(code);
        if (!p) {
            row.dataset.price = 0;
            info.innerHTML = '<span class="error-text">商品が見つかりません</span>';
        } else {
            row.dataset.price = p.contract_price;
            info.innerHTML = escapeHtml(p.product_name) + ' ' + escapeHtml(p.spec || '') + storageBadge(p.storage_type)
                + '<br><small>入数 ' + p.pack_qty + '／単価 ' + formatYen(p.contract_price)
                + '／' + escapeHtml(p.supplier_name) + '</small>';
        }
        updateRef(row);
        updateTotal();
    }

    function bindRow(row) {
        row.querySelector('.js-code').addEventListener('change', () => loadProduct(row));
        row.querySelector('.js-qty').addEventListener('input', () => { updateRef(row); updateTotal(); });
        row.querySelector('.js-remove').addEventListener('click', () => {
            if (tbody.querySelectorAll('.js-order-row').length > 1) {
                row.remove();
            } else {
                row.querySelectorAll('input').forEach((el) => { el.value = ''; });
                loadProduct(row);
            }
            renumber();
            updateTotal();
        });
    }

    document.getElementById('addRowButton').addEventListener('click', () => {
        const first = tbody.querySelector('.js-order-row');
        const row = first.cloneNode(true);
        row.querySelectorAll('input').forEach((el) => { el.value = ''; });
        row.querySelector('.js-info').innerHTML = '';
        row.querySelector('.js-amount').textContent = '';
        row.querySelector('.js-ref').dataset.selected = '';
        row.dataset.price = 0;
        row.classList.remove('qty-minus');
        tbody.appendChild(row);
        bindRow(row);
        updateRef(row);
        renumber();
        row.querySelector('.js-code').focus();
    });

    // 初期表示（エラーで戻ってきたときは入力済みの商品情報を読み直す）
    tbody.querySelectorAll('.js-order-row').forEach((row) => {
        bindRow(row);
        loadProduct(row);
    });
})();
