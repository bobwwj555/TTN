<?php
// TTN Mail Helper
// Uses PHPMailer with SMTP config from site_settings
// mail_enabled = 0 → all sends silently return false (no errors)
// Swap mail_host/user/pass to point to Mailu CT when ready

require_once '/var/www/html/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function ttn_mail(string $to_email, string $to_name, string $subject, string $body_html, string $body_text = ''): bool {
    // Check mail enabled
    if (s('mail_enabled', '0') !== '1') return false;

    $host      = s('mail_host',      'smtp.gmail.com');
    $port      = (int)s('mail_port', '587');
    $user      = s('mail_user',      '');
    $pass      = s('mail_pass',      '');
    $from      = s('mail_from',      'noreply@ttn.radio');
    $from_name = s('mail_from_name', 'Tennessee Technological Network');

    if (!$user || !$pass) return false;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $port;
        $mail->setFrom($from, $from_name);
        $mail->addAddress($to_email, $to_name);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body_html;
        $mail->AltBody = $body_text ?: strip_tags($body_html);
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('TTN mailer error: ' . $mail->ErrorInfo);
        return false;
    }
}

function ttn_mail_reset(string $to_email, string $to_name, string $callsign, string $reset_url): bool {
    $site_url = s('site_url', 'https://dev.ttn.radio');
    $org      = s('org_name', 'Tennessee Technological Network');

    $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#0d1117;color:#e6edf3;padding:2rem">
<div style="max-width:520px;margin:0 auto;background:#161b22;border:1px solid #30363d;padding:2rem;border-radius:6px">
<h2 style="color:#58a6ff;margin-top:0">' . htmlspecialchars($org) . '</h2>
<p>Hi ' . htmlspecialchars($callsign) . ',</p>
<p>Your portal account has been set up at <strong>' . htmlspecialchars($site_url) . '</strong>.</p>
<p>Click the link below to set your password. This link is valid for <strong>7 days</strong>.</p>
<p style="margin:1.5rem 0">
<a href="' . htmlspecialchars($reset_url) . '" style="background:#238636;color:#fff;padding:0.6rem 1.2rem;text-decoration:none;border-radius:4px;font-weight:bold">Set My Password</a>
</p>
<p style="font-size:0.85rem;color:#8b949e">Or copy this link:<br>
<a href="' . htmlspecialchars($reset_url) . '" style="color:#58a6ff;word-break:break-all">' . htmlspecialchars($reset_url) . '</a></p>
<hr style="border-color:#30363d;margin:1.5rem 0">
<p style="font-size:0.78rem;color:#8b949e">You received this because a portal account was created for your callsign ' . htmlspecialchars($callsign) . '. If this was unexpected, ignore this email.</p>
</div></body></html>';

    return ttn_mail($to_email, $to_name, 'Set your ' . $org . ' portal password', $html);
}
