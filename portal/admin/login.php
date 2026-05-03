<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_session_start();

// Already logged in — redirect to dashboard
if (ttn_is_logged_in()) {
    header('Location: ' . s('site_url','https://dev.ttn.radio') . '/admin/dashboard.php');
    exit;
}

$error    = '';
$site_url = s('site_url', 'https://dev.ttn.radio');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $callsign = strtoupper(trim($_POST['callsign'] ?? ''));
    $password = $_POST['password'] ?? '';
    $result   = ttn_login($callsign, $password);
    if ($result['success']) {
        $redirect = $_SESSION['redirect_after_login'] ?? $site_url . '/admin/dashboard.php';
        unset($_SESSION['redirect_after_login']);
        header('Location: ' . $redirect);
        exit;
    }
    $error = $result['error'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · TTN Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Oxanium:wght@400;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0a0a0a;color:#e0e0e0;font-family:'Share Tech Mono',monospace;min-height:100vh;display:flex;align-items:center;justify-content:center}
.login-wrap{width:100%;max-width:400px;padding:2rem}
.login-brand{font-family:'Oxanium',sans-serif;font-weight:800;font-size:1.8rem;letter-spacing:0.1em;color:#00ff88;margin-bottom:0.2rem}
.login-sub{font-size:0.6rem;color:#4b5563;letter-spacing:0.15em;text-transform:uppercase;margin-bottom:2.5rem}
.field{margin-bottom:1.2rem}
.field label{display:block;font-size:0.58rem;color:#4b5563;letter-spacing:0.12em;text-transform:uppercase;margin-bottom:0.4rem}
.field input{width:100%;background:#111318;border:1px solid #1f2330;color:#e8eaf0;font-family:'Share Tech Mono',monospace;font-size:0.88rem;padding:0.7rem 0.9rem;outline:none;transition:border-color 0.12s}
.field input:focus{border-color:#00ff88}
.btn-login{width:100%;background:#00ff88;color:#000;font-family:'Share Tech Mono',monospace;font-size:0.75rem;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;border:none;padding:0.85rem;cursor:pointer;margin-top:0.5rem;transition:background 0.12s}
.btn-login:hover{background:#00cc6a}
.err{background:rgba(248,113,113,0.08);border:1px solid #f87171;color:#f87171;font-size:0.72rem;padding:0.65rem 0.9rem;margin-bottom:1.2rem}
.login-footer{display:flex;justify-content:space-between;margin-top:1.5rem}
.login-footer a{font-size:0.62rem;color:#4b5563;text-decoration:none;letter-spacing:0.06em}
.login-footer a:hover{color:#00ff88}
</style>
</head>
<body>
<div class="login-wrap">
    <div class="login-brand">TTN</div>
    <div class="login-sub">Tennessee Technological Community · Admin</div>

    <?php if ($error): ?>
    <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="field">
            <label>Callsign</label>
            <input type="text" name="callsign" required autocomplete="username" autofocus
                   value="<?= htmlspecialchars($_POST['callsign'] ?? '') ?>"
                   style="text-transform:uppercase" placeholder="W4BWW">
        </div>
        <div class="field">
            <label>Password</label>
            <input type="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn-login">Sign In →</button>
    </form>

    <div class="login-footer">
        <a href="forgot.php">Forgot password?</a>
        <a href="<?= $site_url ?>/">← Back to TTN</a>
    </div>
</div>
</body>
</html>
