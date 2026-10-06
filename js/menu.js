// SC-01 メニュー：左のジャンルを選ぶと、右にそのジャンルのボタンだけを出す
// 最初は先頭のジャンル（発注）。URL の #delivery などがあればそのジャンル（ブラウザの「戻る」で元のジャンルに戻れるように）
(() => {
    const layout = document.getElementById('menuLayout');
    if (!layout) {
        return;
    }
    const tabs = Array.from(layout.querySelectorAll('.menu-tab'));
    const panels = Array.from(layout.querySelectorAll('.menu-panel'));

    function select(key, moveFocus) {
        tabs.forEach((tab) => {
            const on = tab.dataset.key === key;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
            if (on && moveFocus) {
                tab.focus();
            }
        });
        panels.forEach((panel) => { panel.hidden = panel.id !== 'menuPanel-' + key; });
    }

    tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => {
            select(tab.dataset.key, false);
            history.replaceState(null, '', '#' + tab.dataset.key);
        });
        // 上下の矢印キーでジャンルを移動
        tab.addEventListener('keydown', (e) => {
            let next = null;
            if (e.key === 'ArrowDown' || e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
            if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') next = tabs[(i - 1 + tabs.length) % tabs.length];
            if (e.key === 'Home') next = tabs[0];
            if (e.key === 'End') next = tabs[tabs.length - 1];
            if (next) {
                e.preventDefault();
                select(next.dataset.key, true);
                history.replaceState(null, '', '#' + next.dataset.key);
            }
        });
    });

    const fromHash = location.hash.slice(1);
    const first = tabs.some((tab) => tab.dataset.key === fromHash) ? fromHash : tabs[0].dataset.key;
    layout.classList.add('is-tabbed');
    select(first, false);
})();
