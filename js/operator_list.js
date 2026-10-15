// SC-74 操作者一覧（U001 のときだけ読み込む）：「ログイン中」の列を数秒ごとに更新する
(function () {
    const cells = document.querySelectorAll('.js-online');
    if (!cells.length) {
        return;
    }
    async function refresh() {
        try {
            const res = await fetch('/master/operator_online.php', { cache: 'no-store' });
            if (!res.ok) {
                return;
            }
            const online = await res.json();
            cells.forEach((td) => {
                const t = online[td.dataset.code];
                td.innerHTML = '';
                if (t) {
                    const pill = document.createElement('span');
                    pill.className = 'st st-editing';
                    pill.textContent = 'ログイン中';
                    const note = document.createElement('small');
                    note.className = 'note';
                    note.textContent = t + ' 最終通信';
                    td.append(pill, ' ', note);
                }
            });
        } catch (e) {
            // 通信エラーは次の回に任せる
        }
    }
    setInterval(refresh, 5000);
})();
