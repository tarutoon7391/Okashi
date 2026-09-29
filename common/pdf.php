<?php
// PDF出力（05 §1・§8）。lib/ の TCPDF のラッパ。日本語フォントは kozminproregular（質問No.6）
// 使うのは order_print.php / inspection_print.php / undelivered_print.php と sendOrderMail()
//
// ※ lib/ が無い環境（composer install していないローカル）では、
//    $dest='I' は印刷用HTMLをそのまま表示し、$dest='S' は null を返す（メールは添付なしになる）
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
if (is_file(__DIR__ . '/../lib/autoload.php')) {
    require_once __DIR__ . '/../lib/autoload.php';
}

// $html は帳票の本文HTML（TCPDF が読める単純な table 中心のHTML）
// $dest：'I' = ブラウザに表示して終了 / 'S' = PDFのバイナリ文字列を返す（メール添付用）
function outputPdf(string $title, string $html, string $fileName, string $dest = 'I'): ?string
{
    if (!class_exists('TCPDF')) {
        if ($dest === 'S') {
            return null;
        }
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>' . h($title) . '</title>'
           . '<style>body{font-family:sans-serif;margin:24px}table{border-collapse:collapse}'
           . 'td,th{padding:4px 6px}.no-print{margin-bottom:12px;padding:8px;background:#fff1dc}'
           . '@media print{.no-print{display:none}}</style></head><body>'
           . '<div class="no-print">PDFライブラリ（lib/）が無いため印刷用HTMLで表示しています。'
           . '<button onclick="window.print()">印刷</button></div>'
           . $html . '</body></html>';
        exit;
    }

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator(APP_NAME);
    $pdf->SetTitle($title);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->SetFont('kozminproregular', '', 9);
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');

    if ($dest === 'S') {
        return $pdf->Output($fileName, 'S');
    }
    $pdf->Output($fileName, 'I');
    exit;
}
