<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_session_start();

$token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
$msg = $err = '';
$valid = false;
$op    = null;

if (!$token) {
    $err = 'Invalid or missing reset token.';
} else {
    $reset = db_row("
        SELECT pr.*, o.callsign, o.display_name
        FROM password_resets pr
        JOIN operators o ON o.id = pr.operator_id
        WHERE pr.token=? AND pr.used=0 AND pr.expires_at > NOW()
    ", [$token]);
    if (!$reset) {
        $err = 'This reset link is invalid or has expired. Please request a new one.';
    } else {
        $valid = true;
        $op    = $reset;
    }
}

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw1 = $_POST['password']  ?? '';
    $pw2 = $_POST['password2'] ?? '';
    if (strlen($pw1) < 8) {
        $err = 'Password must be at least 8 characters.';
    } elseif ($pw1 !== $pw2) {
        $err = 'Passwords do not match.';
    } else {
        db_execute("UPDATE operators SET password_hash=? WHERE id=?",
            [password_hash($pw1, PASSWORD_BCRYPT), $op['operator_id']]);
        db_execute("UPDATE password_resets SET used=1 WHERE token=?", [$token]);
        $valid = false;
        $msg   = 'Password updated. You can now log in.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password · TTN</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Oxanium:wght@400;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0a0a0a;color:#e0e0e0;font-family:'Share Tech Mono',monospace;min-height:100vh;display:flex;align-items:center;justify-content:center}
.wrap{width:100%;max-width:400px;padding:2rem}
.brand{font-family:'Oxanium',sans-serif;font-weight:800;font-size:1.8rem;letter-spacing:0.1em;color:#00ff88;margin-bottom:0.2rem}
.sub{font-size:0.6rem;color:#4b5563;letter-spacing:0.15em;text-transform:uppercase;margin-bottom:2.5rem}
.callsign{font-family:'Oxanium',sans-serif;font-size:1.1rem;color:#00ff88;margin-bottom:1.2rem}
.field{margin-bottom:1.2rem}
.field label{display:block;font-size:0.58rem;color:#4b5563;letter-spacing:0.12em;text-transform:uppercase;margin-bottom:0.4rem}
.field input{width:100%;background:#111318;border:1px solid #1f2330;color:#e8eaf0;font-family:'Share Tech Mono',monospace;font-size:0.88rem;padding:0.7rem 0.9rem;outline:none;transition:border-color 0.12s}
.field input:focus{border-color:#00ff88}
.field .hint{font-size:0.58rem;color:#4b5563;margin-top:0.3rem}
.btn{width:100%;background:#00ff88;color:#000;font-family:'Share Tech Mono',monospace;font-size:0.75rem;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;border:none;padding:0.85rem;cursor:pointer;margin-top:0.5rem;text-decoration:none;display:block;text-align:center}
.btn:hover{background:#00cc6a}
.btn-sec{width:100%;background:transparent;color:#4b5563;font-family:'Share Tech Mono',monospace;font-size:0.68rem;letter-spacing:0.1em;text-transform:uppercase;border:1px solid #1f2330;padding:0.65rem;cursor:pointer;margin-top:0.5rem;text-decoration:none;display:block;text-align:center}
.btn-sec:hover{border-color:#00ff88;color:#00ff88}
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
    <div class="sub">Set New Password</div>

    <?php if ($msg): ?>
    <div class="msg-ok"><?= htmlspecialchars($msg) ?></div>
    <a href="login.php" class="btn">Go to Login</a>
    <?php elseif ($err): ?>
    <div class="msg-err"><?= htmlspecialchars($err) ?></div>
    <a href="forgot.php" class="btn-sec">Request New Link</a>
    <?php endif; ?>

    <?php if ($valid): ?>
    <div class="callsign"><?= htmlspecialchars($op['callsign']) ?></div>
    <form method="post">
        <div class="field">
            <label>New Password</label>
            <input type="password" name="password" required autocomplete="new-password" minlength="8" autofocus>
            <div class="hint">Minimum 8 characters</div>
        </div>
        <div class="field">
            <label>Confirm Password</label>
            <input type="password" name="password2" required autocomplete="new-password" minlength="8">
        </div>
        <button type="submit" class="btn">Set Password</button>
    </form>
    <?php endif; ?>

    <div class="footer-links">
        <a href="login.php">← Back to login</a>
    </div>
</div>
</body>
</html>
