<?php
// 汎用関数（05 §4）

// 画面出力は必ずこれを通す
function h($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

// パスはルートからの相対（例：'/menu.php'）
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

// type は 'success' | 'error' | 'warning'
function setFlash(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

// header.php が呼ぶ。取り出したらセッションから消す
function getFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

// '2026-09-28' → '2026/09/28'
function formatDate(?string $ymd): string
{
    if ($ymd === null || $ymd === '') {
        return '';
    }
    return date('Y/m/d', strtotime($ymd));
}

// 12345 → '¥12,345'（マイナスは '-¥500'）
function formatYen($n): string
{
    $n = (int)$n;
    return ($n < 0 ? '-' : '') . '¥' . number_format(abs($n));
}

// $_POST[$key] を int にして返す。数値でなければ $default
function postInt(string $key, int $default = 0): int
{
    $v = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
    return $v === false ? $default : $v;
}

// $_POST[$key] を trim して返す。無ければ ''
function postStr(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

// $_POST[$key] の配列を返す（明細行の product_code[] など）。無ければ []
function postArray(string $key): array
{
    $v = $_POST[$key] ?? [];
    return is_array($v) ? $v : [];
}

// 'YYYY-MM-DD' として正しい日付か
function isValidDate(string $ymd): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $ymd);
    return $d !== false && $d->format('Y-m-d') === $ymd;
}

// 整数として読めれば int、読めなければ null（空欄と 0 を区別したいとき用）
function toIntOrNull($v): ?int
{
    if (!is_string($v) && !is_int($v)) {
        return null;
    }
    $v = filter_var(trim((string)$v), FILTER_VALIDATE_INT);
    return $v === false ? null : $v;
}

// 保存区分 → 表示名
function storageTypeName(int $type): string
{
    return [0 => '常温', 1 => '冷蔵', 2 => '冷凍'][$type] ?? '';
}

// 保存区分バッジ（冷蔵・冷凍のみ。常温は何も出さない）。h() 済みのHTMLを返す
function storageBadge($type): string
{
    $type = (int)$type;
    if ($type === 1) {
        return '<span class="storage-chilled">冷蔵</span>';
    }
    if ($type === 2) {
        return '<span class="storage-frozen">冷凍</span>';
    }
    return '';
}

// マイナス数量の行に付けるクラス
function minusClass($qty): string
{
    return (int)$qty < 0 ? 'qty-minus' : '';
}

// 確定状態のセル（未確定は黄色、確定済は緑）。h() 済みのHTMLを返す
function statusCell($isConfirmed): string
{
    return (int)$isConfirmed === 1
        ? '<td class="status-confirmed">確定済</td>'
        : '<td class="status-unconfirmed">未確定</td>';
}
