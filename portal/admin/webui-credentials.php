<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_require_role('operator');

$adm_title = 'Credentials';
$adm_page  = 'webui-credentials';
$my_id     = $_SESSION['operator_id'] ?? 0;
$msg = $err = '';

$is_admin      = ttn_has_role('admin');
$is_site_admin = ttn_has_role('site_admin');

function get_my_site_ids(int $op_id, bool $is_admin): array {
    if ($is_admin) return [];
    $rows = db_rows(
        "SELECT site_id FROM operator_site_access WHERE operator_id=? AND access_level='site_admin'",
        [$op_id]);
    return array_column($rows, 'site_id');
}

function get_allowed_servers(int $op_id, bool $is_admin): array {
    if ($is_admin) {
        return db_rows("SELECT id, hostname, asterisk_ip FROM asl_servers WHERE is_active=1 ORDER BY hostname");
    }
    $site_ids = get_my_site_ids($op_id, false);
    if (empty($site_ids)) return [];
    $placeholders = implode(',', array_fill(0, count($site_ids), '?'));
    return db_rows(
        "SELECT id, hostname, asterisk_ip FROM asl_servers WHERE is_active=1 AND site_id IN ($placeholders) ORDER BY hostname",
        $site_ids);
}

$allowed_servers    = get_allowed_servers($my_id, $is_admin);
$allowed_server_ids = array_column($allowed_servers, 'id');

function get_agent_secret(int $server_id): string {
    // Try asl_servers.agent_secret first, fall back to site_settings
    $srv = db_row("SELECT agent_secret FROM asl_servers WHERE id=?", [$server_id]);
    if ($srv && !empty($srv['agent_secret'])) return $srv['agent_secret'];
    $row = db_row("SELECT setting_val FROM site_settings WHERE setting_key=?", ["agent_secret_{$server_id}"]);
    return $row['setting_val'] ?? '';
}

function call_agent(string $agent_url, string $secret, string $interface,
                    string $username, string $password): array {
    $payload = json_encode(['secret'=>$secret,'interface'=>$interface,'username'=>$username,'password'=>$password,'action'=>'set']);
    $ctx = stream_context_create(['http'=>['method'=>'POST',
        'header'=>"Content-Type: application/json\r\nContent-Length: ".strlen($payload),
        'content'=>$payload,'timeout'=>10]]);
    $raw = @file_get_contents($agent_url, false, $ctx);
    if ($raw === false) return ['ok'=>false,'error'=>'Agent unreachable'];
    return json_decode($raw, true) ?? ['ok'=>false,'error'=>'Invalid agent response'];
}

function do_sync(int $operator_id, int $server_id, string $interface, string $password,
                 string $access, int $my_id, array $allowed_server_ids, bool $is_admin): array {
    if (!$is_admin && !in_array($server_id, $allowed_server_ids))
        return ['ok'=>false,'error'=>'Access denied to this server.'];
    $op     = db_row("SELECT callsign FROM operators WHERE id=?", [$operator_id]);
    $server = db_row("SELECT hostname, asterisk_ip, ip_address, agent_ip FROM asl_servers WHERE id=?", [$server_id]);
    $secret = get_agent_secret($server_id);
    if (!$op || !$server) return ['ok'=>false,'error'=>'Invalid operator or server.'];
    if (!$secret)          return ['ok'=>false,'error'=>'No agent secret configured for this server.'];
    if (strlen($password) < 6) return ['ok'=>false,'error'=>'Password must be at least 6 characters.'];
    $agent_ip  = $server['agent_ip'] ?: $server['asterisk_ip'] ?: $server['ip_address'];
    $agent_url = "http://{$agent_ip}:8765/agent.php";
    $result    = call_agent($agent_url, $secret, $interface, $op['callsign'], $password);
    $status    = $result['ok'] ? 'ok' : 'error';
    $message   = $result['ok'] ? implode(', ', $result['results'] ?? ['ok']) : ($result['error'] ?? 'Unknown error');
    $existing  = db_row("SELECT id FROM webui_credentials WHERE operator_id=? AND server_id=? AND interface=?",
        [$operator_id, $server_id, $interface]);
    if ($existing) {
        db_execute("UPDATE webui_credentials SET access_level=?, last_synced=NOW(), synced_by=?, sync_status=?, sync_message=? WHERE id=?",
            [$access, $my_id, $status, $message, $existing['id']]);
    } else {
        db_insert('webui_credentials', ['operator_id'=>$operator_id,'server_id'=>$server_id,
            'interface'=>$interface,'access_level'=>$access,'last_synced'=>date('Y-m-d H:i:s'),
            'synced_by'=>$my_id,'sync_status'=>$status,'sync_message'=>$message]);
    }
    return ['ok'=>$result['ok'],'interface'=>$interface,'message'=>$message];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ttn_csrf_verify($_POST['csrf_token'] ?? '');
    $pa = $_POST['post_action'] ?? '';

    if ($pa === 'save_secret' && $is_admin) {
        $server_id = (int)$_POST['server_id'];
        $secret    = trim($_POST['agent_secret'] ?? '');
        if (strlen($secret) < 32) { $err = 'Secret must be at least 32 characters.'; }
        else {
            $exists = db_row("SELECT setting_key FROM site_settings WHERE setting_key=?", ["agent_secret_{$server_id}"]);
            if ($exists) db_execute("UPDATE site_settings SET setting_val=? WHERE setting_key=?", [$secret, "agent_secret_{$server_id}"]);
            else db_insert('site_settings', ['setting_key'=>"agent_secret_{$server_id}",'setting_val'=>$secret]);
            $msg = 'Agent secret saved.';
        }
    }
    if ($pa === 'save_server_interfaces' && $is_admin) {
        $server_id = (int)$_POST['server_id'];
        $ifaces    = trim($_POST['supported_interfaces'] ?? '');
        // Sanitize — only allow known interface names
        $allowed   = ['supermon','supermon_ng','allscan','allscanx','allmon3','stream','camera','custom'];
        $parts     = array_filter(array_map('trim', explode(',', $ifaces)));
        $clean     = implode(',', array_filter($parts, fn($p) => in_array($p, $allowed)));
        db_execute("UPDATE asl_servers SET supported_interfaces=? WHERE id=?", [$clean ?: null, $server_id]);
        $msg = 'Server interfaces saved.';
    }

    if ($pa === 'change_own_password') {
        $current  = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password']      ?? '';
        $confirm  = $_POST['confirm_password']  ?? '';
        $me = db_row("SELECT password_hash FROM operators WHERE id=?", [$my_id]);
        if (!password_verify($current, $me['password_hash'])) { $err = 'Current password is incorrect.'; }
        elseif (strlen($new_pass) < 8) { $err = 'New password must be at least 8 characters.'; }
        elseif ($new_pass !== $confirm) { $err = 'Passwords do not match.'; }
        else {
            db_execute("UPDATE operators SET password_hash=? WHERE id=?", [password_hash($new_pass, PASSWORD_BCRYPT), $my_id]);
            $msg = 'Portal password updated.';
        }
    }

    if ($pa === 'reset_portal_password' && $is_admin) {
        $oid = (int)$_POST['op_id']; $new_pass = $_POST['new_password'] ?? '';
        if (strlen($new_pass) < 8) { $err = 'Password must be at least 8 characters.'; }
        else {
            db_execute("UPDATE operators SET password_hash=? WHERE id=?", [password_hash($new_pass, PASSWORD_BCRYPT), $oid]);
            $op = db_row("SELECT callsign FROM operators WHERE id=?", [$oid]);
            $msg = 'Portal password reset for ' . htmlspecialchars($op['callsign']) . '.';
        }
    }

    if ($pa === 'gen_reset_link' && $is_admin) {
        $oid = (int)$_POST['op_id'];
        $op  = db_row("SELECT id, callsign FROM operators WHERE id=?", [$oid]);
        if ($op) {
            db_execute("DELETE FROM password_resets WHERE operator_id=? AND used=0", [$oid]);
            $token = bin2hex(random_bytes(32));
            db_insert('password_resets', ['operator_id'=>$oid,'token'=>$token,'expires_at'=>date('Y-m-d H:i:s', strtotime('+24 hours')),'used'=>0]);
            $site_url = s('site_url', 'https://dev.ttn.radio');
            $msg = 'Reset link for ' . htmlspecialchars($op['callsign']) . ' (valid 24h):<br><a href="'
                . $site_url . '/admin/reset.php?token=' . $token
                . '" style="color:var(--green);word-break:break-all">'
                . $site_url . '/admin/reset.php?token=' . $token . '</a>';
        }
    }

    if ($pa === 'sync_all' && ($is_admin || $is_site_admin)) {
        $operator_id = (int)$_POST['operator_id']; $server_id = (int)$_POST['server_id'];
        $password = $_POST['password'] ?? ''; $access = $_POST['access_level'] ?? 'operator';
        $results = []; $has_error = false;
        $srv_row = db_row("SELECT supported_interfaces FROM asl_servers WHERE id=?", [$server_id]);
        $ifaces = $srv_row['supported_interfaces'] ? explode(',', $srv_row['supported_interfaces']) : ['supermon','supermon_ng','allscan','allmon3'];
        foreach ($ifaces as $iface) {
            $r = do_sync($operator_id, $server_id, $iface, $password, $access, $my_id, $allowed_server_ids, $is_admin);
            if (!$r['ok']) $has_error = true;
            if (isset($r['error']) && empty($results)) { $err = $r['error']; break; }
            $results[] = $iface . ': ' . ($r['ok'] ? 'ok' : 'ERR — ' . $r['message']);
        }
        if (!$err) { $summary = implode(' | ', $results); $has_error ? ($err = 'Errors: '.$summary) : ($msg = 'All synced: '.$summary); }
    }

    if ($pa === 'sync_one' && ($is_admin || $is_site_admin)) {
        $operator_id = (int)$_POST['operator_id']; $server_id = (int)$_POST['server_id'];
        $interface = $_POST['interface'] ?? ''; $password = $_POST['password'] ?? ''; $access = $_POST['access_level'] ?? 'operator';
        $r = do_sync($operator_id, $server_id, $interface, $password, $access, $my_id, $allowed_server_ids, $is_admin);
        if (isset($r['error'])) { $err = $r['error']; }
        elseif ($r['ok']) { $op = db_row("SELECT callsign FROM operators WHERE id=?", [$operator_id]); $msg = htmlspecialchars($op['callsign']) . ' → ' . $interface . ': ' . htmlspecialchars($r['message']); }
        else { $err = 'Agent error: ' . htmlspecialchars($r['message']); }
    }

    if ($pa === 'remove_credential' && $is_admin) {
        db_execute("DELETE FROM webui_credentials WHERE id=?", [(int)$_POST['cred_id']]);
        $msg = 'Credential record removed.';
    }
}

$all_operators = $is_admin ? db_rows("SELECT id, callsign, display_name, role FROM operators WHERE is_active=1 ORDER BY callsign") : [];
$credentials = [];
if ($is_admin) {
    $credentials = db_rows("SELECT wc.*, o.callsign, s.hostname AS server_name FROM webui_credentials wc JOIN operators o ON o.id=wc.operator_id JOIN asl_servers s ON s.id=wc.server_id ORDER BY s.hostname, wc.interface, o.callsign");
} elseif ($is_site_admin && !empty($allowed_server_ids)) {
    $ph = implode(',', array_fill(0, count($allowed_server_ids), '?'));
    $credentials = db_rows("SELECT wc.*, o.callsign, s.hostname AS server_name FROM webui_credentials wc JOIN operators o ON o.id=wc.operator_id JOIN asl_servers s ON s.id=wc.server_id WHERE wc.server_id IN ($ph) ORDER BY s.hostname, wc.interface, o.callsign", $allowed_server_ids);
}
$pending_resets = $is_admin ? db_rows("SELECT pr.*, o.callsign FROM password_resets pr JOIN operators o ON o.id=pr.operator_id WHERE pr.used=0 AND pr.expires_at > NOW() ORDER BY pr.expires_at DESC") : [];
$interfaces = ['supermon','supermon_ng','allscan','allmon3'];
$access_levels = ['admin','operator','viewer'];
$me_op = db_row("SELECT callsign, display_name FROM operators WHERE id=?", [$my_id]);

require_once TTN_INCLUDES . '/admin_head.php';
require_once TTN_INCLUDES . '/admin_nav.php';
?>
<div class="adm-main">
<div class="adm-topbar"><div class="adm-topbar-title">Credentials</div></div>
<div class="adm-body">

<?php if ($msg): ?><div class="msg-ok"><?= $msg ?></div><?php endif; ?>
<?php if ($err): ?><div class="msg-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-hd">My Portal Password — <?= htmlspecialchars($me_op['callsign']) ?></div>
    <div class="panel-body">
    <form method="post" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="change_own_password">
        <div class="field"><label>Current Password</label><input type="password" name="current_password" required style="font-family:var(--mono);width:200px"></div>
        <div class="field"><label>New Password <span style="color:var(--t3);font-size:0.7rem">(min 8)</span></label><input type="password" name="new_password" required minlength="8" style="font-family:var(--mono);width:200px"></div>
        <div class="field"><label>Confirm</label><input type="password" name="confirm_password" required style="font-family:var(--mono);width:200px"></div>
        <div class="field" style="padding-top:1.4rem"><button type="submit" class="btn btn-primary">Update →</button></div>
    </form>
    </div>
</div>

<?php if ($is_admin): ?>
<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-hd">Operator Portal Passwords</div>
    <table class="adm-tbl">
        <thead><tr><th>Callsign</th><th>Role</th><th>Reset Password</th><th>Reset Link</th></tr></thead>
        <tbody>
        <?php foreach ($all_operators as $op): if ($op['id'] == $my_id) continue; ?>
        <tr>
            <td class="mono"><?= htmlspecialchars($op['callsign']) ?></td>
            <td><span style="font-family:var(--mono);font-size:0.58rem;text-transform:uppercase;color:var(--amber)"><?= $op['role'] ?></span></td>
            <td>
                <form method="post" style="display:flex;gap:0.4rem;align-items:center">
                    <?= ttn_csrf_field() ?>
                    <input type="hidden" name="post_action" value="reset_portal_password">
                    <input type="hidden" name="op_id" value="<?= $op['id'] ?>">
                    <input type="password" name="new_password" placeholder="New password" minlength="8" style="font-family:var(--mono);font-size:0.72rem;width:160px;background:var(--bg2);border:1px solid var(--border2);color:var(--t1);padding:0.3rem 0.5rem">
                    <button type="submit" class="btn btn-secondary btn-sm">Set</button>
                </form>
            </td>
            <td>
                <form method="post" style="display:inline">
                    <?= ttn_csrf_field() ?>
                    <input type="hidden" name="post_action" value="gen_reset_link">
                    <input type="hidden" name="op_id" value="<?= $op['id'] ?>">
                    <button type="submit" class="btn btn-secondary btn-sm">Gen Link</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (!empty($pending_resets)): ?>
<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-hd" style="color:var(--amber)">⚠ Active Reset Tokens (<?= count($pending_resets) ?>)</div>
    <table class="adm-tbl">
        <thead><tr><th>Operator</th><th>Expires</th><th>Link</th></tr></thead>
        <tbody>
        <?php foreach ($pending_resets as $pr): ?>
        <tr>
            <td class="mono"><?= htmlspecialchars($pr['callsign']) ?></td>
            <td class="mono muted"><?= htmlspecialchars($pr['expires_at']) ?></td>
            <td style="font-size:0.65rem;word-break:break-all"><?= s('site_url','https://dev.ttn.radio') ?>/admin/reset.php?token=<?= htmlspecialchars(substr($pr['token'],0,16)) ?>...</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-hd">Agent Secrets — per server</div>
    <div class="panel-body">
    <p style="color:var(--t2);font-size:0.78rem;margin-bottom:1rem">Secret must match <code>/usr/local/lib/ttn-agent/config.php</code> on each server.</p>
    <?php foreach ($allowed_servers as $srv): ?>
    <form method="post" style="display:flex;gap:0.5rem;align-items:flex-end;margin-bottom:0.6rem;flex-wrap:wrap">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="save_secret">
        <input type="hidden" name="server_id" value="<?= $srv['id'] ?>">
        <div class="field">
            <label style="font-family:var(--mono);font-size:0.7rem"><?= htmlspecialchars($srv['hostname']) ?></label>
            <input type="password" name="agent_secret" placeholder="<?= get_agent_secret($srv['id']) ? '••••••••••••••••' : 'Not set' ?>" style="font-family:var(--mono);font-size:0.72rem;width:340px;background:var(--bg2);border:1px solid var(--border2);color:var(--t1);padding:0.35rem 0.5rem">
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">Save</button>
        <?php if (get_agent_secret($srv['id'])): ?><span style="color:var(--green);font-size:0.72rem">✓ set</span><?php endif; ?>
    </form>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php if ($is_admin): ?>
<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-hd">Node Server — Supported Interfaces</div>
    <div class="panel-body">
    <p style="color:var(--t2);font-size:0.78rem;margin-bottom:1rem">Comma-separated interfaces the agent supports per server. Sync All only attempts these.</p>
    <?php
    $all_srv_detail = db_rows("SELECT id, hostname, supported_interfaces, agent_ip, public_hostname FROM asl_servers WHERE is_active=1 ORDER BY hostname");
    foreach ($all_srv_detail as $srv): ?>
    <form method="post" style="display:flex;gap:0.5rem;align-items:flex-end;margin-bottom:0.6rem;flex-wrap:wrap">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="save_server_interfaces">
        <input type="hidden" name="server_id" value="<?= $srv['id'] ?>">
        <div class="field">
            <label style="font-family:var(--mono);font-size:0.7rem"><?= htmlspecialchars($srv['hostname']) ?><?= $srv['public_hostname'] ? ' · '.htmlspecialchars($srv['public_hostname']) : '' ?><?= $srv['agent_ip'] ? ' · '.htmlspecialchars($srv['agent_ip']) : '' ?></label>
            <input type="text" name="supported_interfaces"
                value="<?= htmlspecialchars($srv['supported_interfaces'] ?? '') ?>"
                placeholder="supermon,supermon_ng,allscan,allmon3"
                style="font-family:var(--mono);font-size:0.72rem;width:380px;background:var(--bg2);border:1px solid var(--border2);color:var(--t1);padding:0.35rem 0.5rem">
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">Save</button>
    </form>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (($is_admin || $is_site_admin) && !empty($allowed_servers)): ?>
<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-hd">Sync Web UI Password</div>
    <div class="panel-body">
    <form method="post">
        <?= ttn_csrf_field() ?>
        <div class="field-row3">
            <div class="field"><label>Operator</label>
                <select name="operator_id">
                    <?php $sync_ops = $is_admin ? $all_operators : db_rows("SELECT id, callsign FROM operators WHERE is_active=1 ORDER BY callsign");
                    foreach ($sync_ops as $op): ?>
                    <option value="<?= $op['id'] ?>"><?= htmlspecialchars($op['callsign']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Server</label>
                <select name="server_id">
                    <?php foreach ($allowed_servers as $srv): ?>
                    <option value="<?= $srv['id'] ?>"><?= htmlspecialchars($srv['hostname']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Interface <span style="color:var(--t3);font-size:0.7rem">(ignored for Sync All)</span></label>
                <select name="interface">
                    <?php foreach ($interfaces as $iface): ?>
                    <option value="<?= $iface ?>"><?= $iface ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field-row3">
            <div class="field"><label>Password</label><input type="password" name="password" autocomplete="new-password" style="font-family:var(--mono)" minlength="6" required></div>
            <div class="field"><label>Access Level</label>
                <select name="access_level">
                    <?php foreach ($access_levels as $al): ?>
                    <option value="<?= $al ?>"><?= $al ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="justify-content:flex-end;padding-top:1.4rem;display:flex;gap:0.5rem">
                <button type="submit" name="post_action" value="sync_all" class="btn btn-primary">Sync All →</button>
                <button type="submit" name="post_action" value="sync_one" class="btn btn-secondary">Sync One →</button>
            </div>
        </div>
    </form>
    </div>
</div>

<div class="panel">
    <div class="panel-hd">Web UI Credentials (<?= count($credentials) ?>)</div>
    <?php if (empty($credentials)): ?>
    <div class="panel-body" style="color:var(--t3)">No credentials synced yet.</div>
    <?php else: ?>
    <table class="adm-tbl">
        <thead><tr><th>Server</th><th>Interface</th><th>Operator</th><th>Access</th><th>Status</th><th>Last Synced</th><?php if($is_admin): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($credentials as $cred): ?>
        <tr>
            <td class="mono"><?= htmlspecialchars($cred['server_name']) ?></td>
            <td class="mono" style="font-size:0.72rem"><?= htmlspecialchars($cred['interface']) ?></td>
            <td class="mono"><?= htmlspecialchars($cred['callsign']) ?></td>
            <td><span style="font-family:var(--mono);font-size:0.58rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--amber)"><?= $cred['access_level'] ?></span></td>
            <td>
                <?php if ($cred['sync_status']==='ok'): ?><span style="color:var(--green)">✓ ok</span>
                <?php elseif ($cred['sync_status']==='error'): ?><span style="color:var(--red)" title="<?= htmlspecialchars($cred['sync_message']??'') ?>">✗ error</span>
                <?php else: ?><span style="color:var(--t3)">pending</span><?php endif; ?>
            </td>
            <td class="mono muted" style="font-size:0.68rem"><?= $cred['last_synced'] ? htmlspecialchars($cred['last_synced']) : '—' ?></td>
            <?php if ($is_admin): ?>
            <td>
                <form method="post" style="display:inline" onsubmit="return confirm('Remove <?= htmlspecialchars($cred['callsign']) ?> on <?= htmlspecialchars($cred['interface']) ?>?')">
                    <?= ttn_csrf_field() ?>
                    <input type="hidden" name="post_action" value="remove_credential">
                    <input type="hidden" name="cred_id" value="<?= $cred['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

</div></div></body></html>
