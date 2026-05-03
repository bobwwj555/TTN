<?php
require_once '/home/obdswlpx/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_session_start();

if (ttn_is_logged_in()) {
    header('Location: ' . s('site_url','https://dev.ttn.radio') . '/admin/dashboard.php');
    exit;
}

$msg = $err = '';
$site_url = s('site_url', 'https://dev.ttn.radio');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $callsign = strtoupper(trim($_POST['callsign'] ?? ''));
    $email    = strtolower(trim($_POST['email']    ?? ''));

    if (!$callsign || !$email) {
        $err = 'Callsign and email are required.';
    } else {
        $op = db_row("SELECT id, email, display_name FROM operators WHERE callsign=? AND is_active=1", [$callsign]);
        // Always show same message — don't reveal if account exists
        if ($op && strtolower($op['email'] ?? '') === $email) {
            db_execute("DELETE FROM password_resets WHERE operator_id=? AND used=0", [$op['id']]);
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+2 hours'));
            db_insert('password_resets', [
                'operator_id' => $op['id'],
                'token'       => $token,
                'expires_at'  => $expires,
                'used'        => 0,
            ]);
            $reset_url = $site_url . '/admin/reset.php?token=' . $token;
            $name      = $op['display_name'] ?: $callsign;
            $subject   = 'TTN Portal — Password Reset';
            $body_txt  = "Hi $name ($callsign),\n\n"
                       . "A password reset was requested for your TTN portal account.\n\n"
                       . "Click the link below to set a new password. This link expires in 2 hours.\n\n"
                       . $reset_url . "\n\n"
                       . "If you did not request this, ignore this email — your password has not changed.\n\n"
                       . "73, TTN · ttn.radio";
            $headers   = "From: noreply@ttn.radio\r\nReply-To: noreply@ttn.radio\r\nX-Mailer: TTN-Portal\r\n";
            mail($email, $subject, $body_txt, $headers);
        }
        $msg = 'If that callsign and email match an active account, a reset link has been sent.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password · TTN</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Oxanium:wght@400;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0a0a0a;color:#e0e0e0;font-family:'Share Tech Mono',monospace;min-height:100vh;display:flex;align-items:center;justify-content:center}
.wrap{width:100%;max-width:400px;padding:2rem}
.brand{font-family:'Oxanium',sans-serif;font-weight:800;font-size:1.8rem;letter-spacing:0.1em;color:#00ff88;margin-bottom:0.2rem}
.sub{font-size:0.6rem;color:#4b5563;letter-spacing:0.15em;text-transform:uppercase;margin-bottom:2.5rem}
.field{margin-bottom:1.2rem}
.field label{display:block;font-size:0.58rem;color:#4b5563;letter-spacing:0.12em;text-transform:uppercase;margin-bottom:0.4rem}
.field input{width:100%;background:#111318;border:1px solid #1f2330;color:#e8eaf0;font-family:'Share Tech Mono',monospace;font-size:0.88rem;padding:0.7rem 0.9rem;outline:none;transition:border-color 0.12s}
.field input:focus{border-color:#00ff88}
.btn{width:100%;background:#00ff88;color:#000;font-family:'Share Tech Mono',monospace;font-size:0.75rem;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;border:none;padding:0.85rem;cursor:pointer;margin-top:0.5rem}
.btn:hover{background:#00cc6a}
.msg-ok{background:rgba(0,255,136,0.08);border:1px solid #00ff88;color:#00ff88;font-size:0.72rem;padding:0.65rem 0.9rem;margin-bottom:1.2rem}
.msg-err{background:rgba(248,113,113,0.08);border:1px solid #f87171;color:#f87171;font-size:0.72rem;padding:0.65rem 0.9rem;margin-bottom:1.2rem}
.footer-links{display:flex;justify-content:flex-end;margin-top:1.5rem}
.footer-links a{font-size:0.62rem;color:#4b5563;text-decoration:none}
.footer-links a:hover{color:#00ff88}
</style>
</head>
<body>
<div class="wrap">
    <div class="brand">TTN</div>
    <div class="sub">Password Reset</div>

    <?php if ($msg): ?><div class="msg-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <?php if (!$msg): ?>
    <form method="post">
        <div class="field">
            <label>Callsign</label>
            <input type="text" name="callsign" required autofocus style="text-transform:uppercase"
                   value="<?= htmlspecialchars($_POST['callsign'] ?? '') ?>" placeholder="W4BWW">
        </div>
        <div class="field">
            <label>Email on file</label>
            <input type="email" name="email" required autocomplete="email" placeholder="you@example.com">
        </div>
        <button type="submit" class="btn">Send Reset Link</button>
    </form>
    <?php endif; ?>

    <div class="footer-links">
        <a href="login.php">← Back to login</a>
    </div>
</div>
</body>
</html>
