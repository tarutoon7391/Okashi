// SC-10 発注入力：行追加・商品情報表示（fetchProduct）・金額計算（calcAmount）・取消元プルダウン
// 入力欄の name は product_code[0] のように行番号つき（renumber で振り直す）。取消元が無効の行もずれない
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

    // 行番号の表示と、入力欄の name の [番号] を上から振り直す
    function renumber() {
        tbody.querySelectorAll('.js-order-row').forEach((row, i) => {
            row.querySelector('.js-line-no').textContent = i + 1;
            row.querySelectorAll('[name]').forEach((el) => {
                el.name = el.name.replace(/\[\d*\]$/, '[' + i + ']');
            });
        });
    }

    // 商品が見つからない行を赤くする（06-2）
    function markNotFound(row, on) {
        row.classList.toggle('row-error', on);
    }

    // 金額計算に使う単価。取消（マイナス）で取消元を選んでいれば取消元の契約単価、それ以外は商品マスタの単価
    function applyPrice(row) {
        const qty = Number(row.querySelector('.js-qty').value) || 0;
        const key = row.querySelector('.js-ref').value;
        const cand = qty < 0 && key ? candidates.find((c) => c.key === key) : null;
        row.dataset.price = cand ? cand.contract_price : (row.dataset.productPrice || 0);
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
            applyPrice(row);
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
        applyPrice(row);
    }

    async function loadProduct(row) {
        const code = row.querySelector('.js-code').value.trim();
        const info = row.querySelector('.js-info');
        if (!code) {
            row.dataset.productPrice = 0;
            info.innerHTML = '';
            markNotFound(row, false);
            updateRef(row);
            updateTotal();
            return;
        }
        const p = await fetchProduct(code);
        if (!p) {
            row.dataset.productPrice = 0;
            if (candidates.some((c) => c.product_code === code)) {
                // 削除済みの商品でも、確定済みの発注があればマイナス数量で取消だけできる
                info.innerHTML = '<small>削除済みの商品です（マイナス数量で取消のみできます）</small>';
                markNotFound(row, false);
            } else {
                info.innerHTML = '<span class="error-text">商品が見つかりません</span>';
                markNotFound(row, true);
            }
        } else {
            row.dataset.productPrice = p.contract_price;
            let stock = '';
            // 在庫（API が返すときだけ表示。0以下は赤背景）
            if (p.stock_qty !== undefined) {
                const qty = p.stock_qty === null ? 0 : Number(p.stock_qty);
                stock = '／在庫 ' + (qty <= 0 ? '<span class="stock-warning">' + qty + '</span>' : qty);
            }
            info.innerHTML = escapeHtml(p.product_name) + ' ' + escapeHtml(p.spec || '') + storageBadge(p.storage_type)
                + '<br><small>入数 ' + escapeHtml(p.pack_qty) + '／単価 ' + formatYen(p.contract_price)
                + '／' + escapeHtml(p.supplier_name) + stock + '</small>';
            markNotFound(row, false);
        }
        updateRef(row);
        updateTotal();
    }

    function bindRow(row) {
        row.querySelector('.js-code').addEventListener('change', () => loadProduct(row));
        row.querySelector('.js-qty').addEventListener('input', () => { updateRef(row); updateTotal(); });
        row.querySelector('.js-ref').addEventListener('change', () => { applyPrice(row); updateTotal(); });
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
        row.querySelector('.js-ref').innerHTML = '<option value="">―</option>';   // 1行目の選択を引き継がない
        row.dataset.price = 0;
        row.dataset.productPrice = 0;
        row.classList.remove('qty-minus');
        markNotFound(row, false);
        tbody.appendChild(row);
        bindRow(row);
        updateRef(row);
        renumber();
        row.querySelector('.js-code').focus();
    });

    // 初期表示（エラーで戻ってきたときは入力済みの商品情報と取消元を読み直す）
    renumber();
    tbody.querySelectorAll('.js-order-row').forEach((row) => {
        bindRow(row);
        loadProduct(row);
    });
})();
