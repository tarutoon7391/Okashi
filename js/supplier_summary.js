// SC-69 卸業者別納品金額：純額の割合を canvas の arc() で円グラフにする（ライブラリなし）
// 色は PHP 側で決めて表の凡例と同じにしている
(() => {
    const data = JSON.parse(document.getElementById('chartData').textContent);
    const canvas = document.getElementById('supplierChart');
    const total = data.reduce((sum, d) => sum + d.value, 0);
    if (total <= 0) {
        canvas.hidden = true;
        document.getElementById('chartEmpty').hidden = false;
        return;
    }

    const ctx = canvas.getContext('2d');
    const cx = canvas.width / 2;
    const cy = canvas.height / 2;
    const r = Math.min(cx, cy) - 10;
    let start = -Math.PI / 2;   // 12時の位置から時計回り

    data.forEach((d) => {
        const angle = (d.value / total) * Math.PI * 2;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, start, start + angle);
        ctx.closePath();
        ctx.fillStyle = d.color;
        ctx.fill();
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 2;
        ctx.stroke();

        // 5%以上の扇にだけ割合を書く
        const ratio = d.value / total;
        if (ratio >= 0.05) {
            const mid = start + angle / 2;
            ctx.fillStyle = '#fff';
            ctx.font = 'bold 14px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText((ratio * 100).toFixed(1) + '%', cx + Math.cos(mid) * r * 0.62, cy + Math.sin(mid) * r * 0.62);
        }
        start += angle;
    });
})();
