<?php
// メール送信（05 §1・§8）。質問No.6（発注書PDFの送付）・No.10（ワンタイムコード）
// 送り方は config.php の MAIL_DRIVER で切り替える
//   log    ：送らずにサーバのログへ書くだけ（ローカル開発・デモ用）
//   smtp   ：lib/ の PHPMailer で SMTP 送信（Gmail など）
//   resend ：Resend の HTTP API（Railway の無料/Hobby プランは SMTP が使えないため）
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/pdf.php';

if (is_file(__DIR__ . '/../lib/autoload.php')) {
    require_once __DIR__ . '/../lib/autoload.php';
}

// $attachments = [['name' => 'a.pdf', 'content' => (バイナリ文字列), 'type' => 'application/pdf'], ...]
// 送信できたら true。失敗は false（理由はログへ）
function sendMail(string $to, string $subject, string $body, array $attachments = []): bool
{
    try {
        if (MAIL_DRIVER === 'smtp') {
            return sendMailBySmtp($to, $subject, $body, $attachments);
        }
        if (MAIL_DRIVER === 'resend') {
            return sendMailByResend($to, $subject, $body, $attachments);
        }
        // log
        $names = implode(', ', array_column($attachments, 'name'));
        error_log("[mail:log] to={$to} subject={$subject} attachments={$names}\n{$body}");
        return true;
    } catch (Throwable $e) {
        error_log('[mail] 送信失敗：' . $e->getMessage());
        return false;
    }
}

function sendMailBySmtp(string $to, string $subject, string $body, array $attachments): bool
{
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('[mail] PHPMailer がありません（composer install で lib/ に入れる）');
        return false;
    }
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->Port       = SMTP_PORT;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = SMTP_PORT === 465
        ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet    = 'UTF-8';
    $mail->Encoding   = 'base64';
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->addAddress($to);
    $mail->Subject = $subject;
    $mail->Body    = $body;
    foreach ($attachments as $a) {
        $mail->addStringAttachment($a['content'], $a['name'], 'base64', $a['type'] ?? 'application/octet-stream');
    }
    return $mail->send();
}

function sendMailByResend(string $to, string $subject, string $body, array $attachments): bool
{
    $payload = [
        'from'    => MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
        'to'      => [$to],
        'subject' => $subject,
        'text'    => $body,
    ];
    foreach ($attachments as $a) {
        $payload['attachments'][] = ['filename' => $a['name'], 'content' => base64_encode($a['content'])];
    }
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        error_log("[mail] Resend エラー HTTP {$code}：{$res}");
        return false;
    }
    return true;
}

// ワンタイムコード（質問No.10）
function sendOtpMail(string $to, string $otp): bool
{
    $body = "ログイン用のワンタイムコードです。\n\n"
          . "　{$otp}\n\n"
          . '有効期限は ' . OTP_EXPIRE_MIN . " 分です。\n"
          . "心当たりが無い場合はこのメールを破棄してください。\n\n"
          . APP_NAME;
    return sendMail($to, '【' . APP_NAME . '】ワンタイムコード', $body);
}

// 確定した発注伝票の発注書PDFを卸業者へ送る（質問No.6）
// 成功したら t_order.mailed_at を更新して true。アドレス未登録・送信失敗は false（確定は取り消さない）
function sendOrderMail(int $orderNo): bool
{
    $slip = getOrderSlip($orderNo);
    if ($slip === null || (int)$slip['is_confirmed'] !== 1) {
        return false;
    }
    if ($slip['order_email'] === null || $slip['order_email'] === '') {
        return false;
    }
    $html = buildOrderSheetHtml($slip, 'order');
    $pdf  = outputPdf('発注書', $html, "order_{$orderNo}.pdf", 'S');
    $attachments = [];
    if ($pdf !== null) {
        $attachments[] = ['name' => "発注書_No{$orderNo}.pdf", 'content' => $pdf, 'type' => 'application/pdf'];
    }
    $body = $slip['supplier_name'] . " 御中\n\n"
          . "いつもお世話になっております。" . SHOP_NAME . " です。\n"
          . "下記のとおり発注いたします。詳細は添付の発注書をご確認ください。\n\n"
          . '発注伝票No：' . $orderNo . "\n"
          . '発注日　　：' . formatDate($slip['order_date']) . "\n"
          . '合計金額　：' . formatYen($slip['total_amount']) . "\n\n"
          . SHOP_NAME;
    if (!sendMail($slip['order_email'], '【発注書】' . SHOP_NAME . ' 発注伝票No.' . $orderNo, $body, $attachments)) {
        return false;
    }
    // 確定済み伝票だが、送信日時の記録だけは設計どおり更新する（05 §8）
    $st = getDb()->prepare('UPDATE t_order SET mailed_at = NOW() WHERE order_no = :no');
    $st->execute([':no' => $orderNo]);
    return true;
}
