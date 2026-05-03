<?php
require_once '/home/obdswlpx/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_require_role('admin');

$adm_title = 'Network';
$adm_page  = 'network';
$msg = $err = '';
$action  = $_GET['action'] ?? 'servers';
$edit_id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ttn_csrf_verify($_POST['csrf_token'] ?? '');
    $pa = $_POST['post_action'] ?? '';

    // ── SAVE SERVER ───────────────────────────────────────────
    if ($pa === 'save_server') {
        $sid  = (int)($_POST['server_id'] ?? 0);
        $data = [
            'site_id'                => (int)$_POST['site_id'] ?: null,
            'hostname'               => trim($_POST['hostname']    ?? ''),
            'ip_address'             => trim($_POST['ip_address']  ?? '') ?: null,
            'asl_version'            => trim($_POST['asl_version'] ?? '') ?: null,
            'os'                     => trim($_POST['os']          ?? '') ?: null,
            'asterisk_ip'            => trim($_POST['asterisk_ip']  ?? '127.0.0.1'),
            'ami_port'               => (int)($_POST['ami_port']   ?? 5040),
            'asl_port'               => (int)($_POST['asl_port']   ?? 4569),
            'ami_user'               => trim($_POST['ami_user']    ?? 'admin'),
            'ami_pass'               => trim($_POST['ami_pass']    ?? ''),
            'ami_timeout'            => (int)($_POST['ami_timeout'] ?? 5),
            'has_isp'                => isset($_POST['has_isp'])                ? 1 : 0,
            'ttn_logger_installed'   => isset($_POST['ttn_logger_installed'])   ? 1 : 0,
            'ttn_status_installed'   => isset($_POST['ttn_status_installed'])   ? 1 : 0,
            'is_active'              => isset($_POST['is_active'])              ? 1 : 0,
            'notes'                  => trim($_POST['notes'] ?? ''),
        ];
        if (!$data['hostname']) { $err = 'Hostname required.'; }
        else {
            if ($sid) {
                $sets = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
                db_execute("UPDATE asl_servers SET $sets WHERE id=?", [...array_values($data), $sid]);
                $msg = 'Server updated.';
            } else {
                db_insert('asl_servers', $data);
                $msg = 'Server added.';
            }
            $action = 'servers';
        }
    }

    // ── TEST AMI (ASL3) ───────────────────────────────────────
    if ($pa === 'test_ami') {
        $sid    = (int)$_POST['server_id'];
        $server = db_row("SELECT * FROM asl_servers WHERE id=?", [$sid]);
        if ($server) {
            $timeout = (int)($server['ami_timeout'] ?: 5);
            $sock = @fsockopen($server['asterisk_ip'], (int)$server['ami_port'], $errno, $errstr, $timeout);
            if (!$sock) {
                $err = "AMI connection failed: $errstr ($errno) — {$server['asterisk_ip']}:{$server['ami_port']}";
            } else {
                stream_set_timeout($sock, $timeout);
                fgets($sock, 256); // banner
                // ASL3 uppercase field names
                fwrite($sock, "ACTION: Login\r\nUSERNAME: {$server['ami_user']}\r\nSECRET: {$server['ami_pass']}\r\n\r\n");
                $resp = ''; $start = time(); $logged_in = false;
                stream_set_timeout($sock, 1);
                while (!feof($sock)) {
                    $line = fgets($sock, 256);
                    if ($line === false) break;
                    $resp .= $line;
                    if (strpos($line, 'Authentication accepted') !== false) $logged_in = true;
                }
                fclose($sock);
                if ($logged_in) {
                    $msg = "✓ AMI connection successful — {$server['asterisk_ip']}:{$server['ami_port']}";
                    // Update last_seen
                    db_execute("UPDATE asl_servers SET last_seen=NOW() WHERE id=?", [$sid]);
                } else {
                    $err = "AMI connected but login failed — check ami_user / ami_pass";
                }
            }
        }
        $action = 'servers';
    }

    // ── DELETE SERVER ─────────────────────────────────────────
    if ($pa === 'delete_server') {
        db_execute("DELETE FROM asl_servers WHERE id=?", [(int)$_POST['server_id']]);
        $msg = 'Server deleted.'; $action = 'servers';
    }

    // ── DOWNLOAD CONFIG ───────────────────────────────────────
    if ($pa === 'download_config') {
        $sid    = (int)$_POST['server_id'];
        $server = db_row("SELECT * FROM asl_servers WHERE id=?", [$sid]);
        $secret = s('telemetry_secret', '');
        $portal = s('site_url', 'https://dev.ttn.radio');
        if ($server && $secret) {
            $filename = 'ttn-logger-' . preg_replace('/[^a-z0-9\-]/', '', strtolower($server['hostname'])) . '.conf';
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo "; TTN Logger Config\n";
            echo "; LOCATION: /etc/ttn-logger.conf  (on " . $server['hostname'] . ")\n";
            echo "; Generated: " . date('Y-m-d H:i:s') . "\n";
            echo "; Permissions: chmod 600 /etc/ttn-logger.conf\n\n";
            echo "portal_url = " . $portal . "/api/telemetry-receive.php\n";
            echo "portal_secret = " . $secret . "\n";
            echo "log_local = /var/log/ttn-telemetry.log\n";
            exit;
        }
        $err = 'Could not generate config — check telemetry_secret in site settings.';
        $action = 'servers';
    }

    // ── SAVE NODE ─────────────────────────────────────────────
    if ($pa === 'save_node') {
        $nid  = (int)($_POST['node_id'] ?? 0);
        $data = [
            'system_id'          => (int)$_POST['system_id'] ?: null,
            'server_id'          => (int)$_POST['server_id'] ?: null,
            'asl_number'         => trim($_POST['asl_number'] ?? ''),
            'callsign'           => trim($_POST['callsign']   ?? '') ?: null,
            'node_type'          => $_POST['node_type']       ?? 'backbone',
            'visibility'         => $_POST['visibility']      ?? 'public',
            'owner_operator_id'  => (int)$_POST['owner_operator_id'] ?: null,
            'is_hub'             => isset($_POST['is_hub'])   ? 1 : 0,
            'freq_tx'            => trim($_POST['freq_tx']    ?? '') ?: null,
            'access_code'        => trim($_POST['access_code']?? '') ?: null,
            'location_note'      => trim($_POST['location_note'] ?? '') ?: null,
            'label'              => trim($_POST['label']      ?? '') ?: null,
            'notes'              => trim($_POST['notes']      ?? '') ?: null,
            'is_active'          => isset($_POST['is_active']) ? 1 : 0,
        ];
        if (!$data['asl_number']) { $err = 'ASL node number required.'; $action = $nid ? 'edit_node' : 'new_node'; }
        else {
            if ($nid) {
                $sets = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
                db_execute("UPDATE sys_asl SET $sets WHERE id=?", [...array_values($data), $nid]);
                $msg = 'Node updated.';
            } else {
                db_insert('sys_asl', $data);
                $msg = 'Node added.';
            }
            $action = 'nodes';
        }
    }

    // ── DELETE NODE ───────────────────────────────────────────
    if ($pa === 'delete_node') {
        db_execute("DELETE FROM sys_asl WHERE id=?", [(int)$_POST['node_id']]);
        $msg = 'Node deleted.'; $action = 'nodes';
    }
}

// ── DATA ──────────────────────────────────────────────────────
$all_sites   = db_rows("SELECT id, name FROM sites ORDER BY name");
$all_systems = db_rows("SELECT s.id, s.callsign, s.label, si.name AS site_name FROM systems s JOIN sites si ON si.id=s.site_id ORDER BY si.name, s.callsign");
$all_ops     = db_rows("SELECT id, callsign, display_name FROM operators WHERE is_active=1 ORDER BY callsign");
$asl_servers = db_rows("SELECT s.*, si.name AS site_name FROM asl_servers s LEFT JOIN sites si ON si.id=s.site_id ORDER BY s.hostname");
$asl_nodes   = db_rows("
    SELECT n.*, s.callsign AS sys_callsign, si.name AS site_name, srv.hostname AS server_host,
           op.callsign AS owner_call
    FROM sys_asl n
    LEFT JOIN systems s   ON s.id   = n.system_id
    LEFT JOIN sites si    ON si.id  = s.site_id
    LEFT JOIN asl_servers srv ON srv.id = n.server_id
    LEFT JOIN operators op ON op.id = n.owner_operator_id
    ORDER BY n.is_active DESC, n.asl_number
");
$subnets = db_rows("SELECT n.*, si.name AS site_name FROM network_subnets n LEFT JOIN sites si ON si.id=n.site_id ORDER BY si.name, n.subnet");
$devices = db_rows("SELECT d.*, si.name AS site_name FROM network_devices d LEFT JOIN sites si ON si.id=d.site_id ORDER BY si.name, d.hostname");

$edit_server = null;
if ($action === 'edit_server' && $edit_id) { $edit_server = db_row("SELECT * FROM asl_servers WHERE id=?", [$edit_id]); if(!$edit_server) $action='servers'; }
if ($action === 'new_server') $edit_server = [];

$edit_node = null;
if ($action === 'edit_node' && $edit_id) { $edit_node = db_row("SELECT * FROM sys_asl WHERE id=?", [$edit_id]); if(!$edit_node) $action='nodes'; }
if ($action === 'new_node') $edit_node = [];

require_once TTN_INCLUDES . '/admin_head.php';
require_once TTN_INCLUDES . '/admin_nav.php';
?>
<div class="adm-main">
<div class="adm-topbar">
    <div class="adm-topbar-title">Network</div>
    <div style="display:flex;gap:0.5rem">
        <a href="?action=servers" class="btn btn-sm <?= in_array($action,['servers','edit_server','new_server'])?'btn-primary':'btn-secondary' ?>">ASL Servers</a>
        <a href="?action=nodes"   class="btn btn-sm <?= in_array($action,['nodes','edit_node','new_node'])?'btn-primary':'btn-secondary' ?>">Node Registry</a>
        <a href="?action=subnets" class="btn btn-sm <?= $action==='subnets'?'btn-primary':'btn-secondary' ?>">Subnets</a>
        <a href="?action=devices" class="btn btn-sm <?= $action==='devices'?'btn-primary':'btn-secondary' ?>">Devices</a>
    </div>
</div>
<div class="adm-body">
<?php if($msg): ?><div class="msg-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="msg-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php // ══════════════════════════════════════════════════════
      // ASL SERVERS LIST
      if (in_array($action, ['servers','list'])): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <span style="font-family:var(--mono);font-size:0.65rem;color:var(--t3)">Node servers — health based on last telemetry POST from ttn-logger.php</span>
    <a href="?action=new_server" class="btn btn-primary btn-sm">+ Add Server</a>
</div>
<div class="panel">
    <div class="panel-hd">ASL Servers (<?= count($asl_servers) ?>)</div>
    <table class="adm-tbl">
        <thead><tr><th>Public Hostname</th><th>Site</th><th>ASL Version</th><th>ISP</th><th>Logger</th><th>Status.php</th><th>Last Check-in</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($asl_servers as $srv):
            $health_color = 'var(--t3)';
            $health_label = '— No data yet';
            if ($srv['last_seen']) {
                $age = time() - strtotime($srv['last_seen']);
                if ($age < 300)      { $health_color = 'var(--green)'; $health_label = '● ' . floor($age/60) . 'm ago'; }
                elseif ($age < 3600) { $health_color = 'var(--amber)'; $health_label = '◐ ' . floor($age/60) . 'm ago'; }
                else                 { $health_color = 'var(--red)';   $health_label = '✕ ' . floor($age/3600) . 'h ago — check cron'; }
            }
        ?>
        <tr style="<?= !$srv['is_active'] ? 'opacity:0.5' : '' ?>">
            <td class="mono"><?= htmlspecialchars($srv['hostname']) ?></td>
            <td class="muted"><?= htmlspecialchars($srv['site_name'] ?? '—') ?></td>
            <td class="muted" style="font-size:0.65rem"><?= htmlspecialchars($srv['asl_version'] ?? '—') ?></td>
            <td><?= $srv['has_isp'] ? '<span style="color:var(--green)" title="Has public ISP">✓</span>' : '<span style="color:var(--t3)" title="No public ISP yet">○</span>' ?></td>
            <td><?= $srv['ttn_logger_installed'] ? '<span style="color:var(--green)" title="Logger + cron running">✓</span>' : '<span style="color:var(--amber)" title="Not installed">!</span>' ?></td>
            <td><?= $srv['ttn_status_installed'] ? '<span style="color:var(--green)" title="ttn-status.php installed">✓</span>' : '<span style="color:var(--t3)">—</span>' ?></td>
            <td><span style="font-family:var(--mono);font-size:0.62rem;color:<?= $health_color ?>"><?= $health_label ?></span></td>
            <td><?= $srv['is_active'] ? '<span style="color:var(--green)">✓</span>' : '<span style="color:var(--red)">✗</span>' ?></td>
            <td>
                <div class="actions">
                    <a href="?action=edit_server&id=<?= $srv['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                    <form method="post" style="display:inline">
                        <?= ttn_csrf_field() ?>
                        <input type="hidden" name="post_action" value="download_config">
                        <input type="hidden" name="server_id" value="<?= $srv['id'] ?>">
                        <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--green)">↓ Config</button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete server?')">
                        <?= ttn_csrf_field() ?>
                        <input type="hidden" name="post_action" value="delete_server">
                        <input type="hidden" name="server_id" value="<?= $srv['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Del</button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($asl_servers)): ?><tr><td colspan="9" class="muted">No servers configured.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php // ══════════════════════════════════════════════════════
      // SERVER EDIT / NEW
      elseif (in_array($action, ['edit_server','new_server'])): ?>
<div class="panel">
    <div class="panel-hd"><?= $edit_id ? 'Edit ASL Server' : 'New ASL Server' ?> <a href="?action=servers" class="btn btn-secondary btn-sm">← Back</a></div>
    <div class="panel-body">
    <form method="post">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="save_server">
        <input type="hidden" name="server_id" value="<?= $edit_id ?>">

        <div style="font-family:var(--mono);font-size:0.58rem;color:var(--green);letter-spacing:0.12em;text-transform:uppercase;margin-bottom:0.8rem">Public Identity</div>
        <div class="field-row">
            <div class="field">
                <label>Public Hostname *</label>
                <input type="text" name="hostname" value="<?= htmlspecialchars($edit_server['hostname'] ?? '') ?>" required placeholder="tn.w4bww.net">
                <div style="font-size:0.58rem;color:var(--t3);margin-top:0.3rem">DNS name the world uses — e.g. tn.w4bww.net resolves to public IP</div>
            </div>
            <div class="field"><label>TTN Site</label>
                <select name="site_id"><option value="">— Not assigned to a site —</option>
                <?php foreach($all_sites as $s): ?><option value="<?=$s['id']?>" <?=($edit_server['site_id']??0)==$s['id']?'selected':''?>><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field-row3">
            <div class="field">
                <label>Public IP Address</label>
                <input type="text" name="ip_address" value="<?= htmlspecialchars($edit_server['ip_address'] ?? '') ?>" placeholder="162.191.61.104">
                <div style="font-size:0.58rem;color:var(--t3);margin-top:0.3rem">External/WAN IP — what hostname resolves to</div>
            </div>
            <div class="field">
                <label>ASL Version</label>
                <input type="text" name="asl_version" value="<?= htmlspecialchars($edit_server['asl_version'] ?? '') ?>" placeholder="AllStarLink3">
            </div>
            <div class="field">
                <label>OS</label>
                <input type="text" name="os" value="<?= htmlspecialchars($edit_server['os'] ?? '') ?>" placeholder="Debian 12">
            </div>
        </div>

        <div style="font-family:var(--mono);font-size:0.58rem;color:var(--green);letter-spacing:0.12em;text-transform:uppercase;margin:1.2rem 0 0.8rem;padding-top:1rem;border-top:1px solid var(--border2)">AMI / Asterisk Manager Interface</div>
        <div style="font-size:0.72rem;color:var(--t3);margin-bottom:0.8rem">Used by ttn-logger.php running locally on this server — not accessed from the portal directly.</div>
        <div class="field-row3">
            <div class="field">
                <label>Asterisk Internal IP</label>
                <input type="text" name="asterisk_ip" value="<?= htmlspecialchars($edit_server['asterisk_ip'] ?? '127.0.0.1') ?>" placeholder="172.20.5.7">
                <div style="font-size:0.58rem;color:var(--t3);margin-top:0.3rem">LAN IP where Asterisk runs — may differ from public IP</div>
            </div>
            <div class="field">
                <label>AMI Port (TCP)</label>
                <input type="number" name="ami_port" value="<?= htmlspecialchars($edit_server['ami_port'] ?? '5040') ?>" min="1" max="65535">
                <div style="font-size:0.58rem;color:var(--t3);margin-top:0.3rem">Manager interface — ASL3 default 5040, in manager.conf</div>
            </div>
            <div class="field">
                <label>ASL Port (UDP)</label>
                <input type="number" name="asl_port" value="<?= htmlspecialchars($edit_server['asl_port'] ?? '4569') ?>" min="1" max="65535">
                <div style="font-size:0.58rem;color:var(--t3);margin-top:0.3rem">Peer connections — default 4569, port-forwarded on router</div>
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label>AMI Username</label>
                <input type="text" name="ami_user" value="<?= htmlspecialchars($edit_server['ami_user'] ?? 'admin') ?>">
                <div style="font-size:0.58rem;color:var(--t3);margin-top:0.3rem">[username] block in /etc/asterisk/manager.conf</div>
            </div>
            <div class="field">
                <label>AMI Password</label>
                <input type="password" name="ami_pass" value="<?= htmlspecialchars($edit_server['ami_pass'] ?? '') ?>" autocomplete="new-password">
                <div style="font-size:0.58rem;color:var(--t3);margin-top:0.3rem">secret = value in manager.conf</div>
            </div>
            <div class="field">
                <label>AMI Timeout (sec)</label>
                <input type="number" name="ami_timeout" value="<?= htmlspecialchars($edit_server['ami_timeout'] ?? '5') ?>" min="1" max="30">
            </div>
        </div>

        <div style="font-family:var(--mono);font-size:0.58rem;color:var(--green);letter-spacing:0.12em;text-transform:uppercase;margin:1.2rem 0 0.8rem;padding-top:1rem;border-top:1px solid var(--border2)">Deployment Status</div>
        <div style="display:flex;gap:2rem;flex-wrap:wrap;margin-bottom:1rem">
            <div class="check-row"><input type="checkbox" name="has_isp" id="has_isp" <?= ($edit_server['has_isp']??1)?'checked':'' ?>><label for="has_isp">Has public ISP / static IP</label></div>
            <div class="check-row"><input type="checkbox" name="ttn_logger_installed" id="ttn_li" <?= ($edit_server['ttn_logger_installed']??0)?'checked':'' ?>><label for="ttn_li">ttn-logger.php + cron installed</label></div>
            <div class="check-row"><input type="checkbox" name="ttn_status_installed" id="ttn_si" <?= ($edit_server['ttn_status_installed']??0)?'checked':'' ?>><label for="ttn_si">ttn-status.php installed</label></div>
            <div class="check-row"><input type="checkbox" name="is_active" id="ia" <?= ($edit_server['is_active']??1)?'checked':'' ?>><label for="ia">Server active</label></div>
        </div>
        <div class="field"><label>Notes</label><textarea name="notes" rows="2"><?= htmlspecialchars($edit_server['notes'] ?? '') ?></textarea></div>
        <div style="display:flex;gap:0.7rem;margin-top:1rem">
            <button type="submit" class="btn btn-primary">Save Server</button>
            <a href="?action=servers" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
    </div>
</div>

<?php // ══════════════════════════════════════════════════════
      // NODE REGISTRY LIST
      elseif ($action === 'nodes'): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <span style="font-family:var(--mono);font-size:0.65rem;color:var(--t3)">TTN node registry — sovereign record of all TTN nodes, public and private</span>
    <a href="?action=new_node" class="btn btn-primary btn-sm">+ Add Node</a>
</div>
<div class="panel">
    <div class="panel-hd">Node Registry (<?= count($asl_nodes) ?>)</div>
    <table class="adm-tbl">
        <thead><tr><th>ASL #</th><th>Callsign</th><th>Type</th><th>Visibility</th><th>System</th><th>Server</th><th>Owner</th><th>Hub</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($asl_nodes as $n): ?>
        <tr style="<?= !$n['is_active'] ? 'opacity:0.5' : '' ?>">
            <td class="mono" style="color:var(--amber)"><?= htmlspecialchars($n['asl_number']) ?></td>
            <td class="mono"><?= htmlspecialchars($n['callsign'] ?? '—') ?></td>
            <td style="font-family:var(--mono);font-size:0.6rem;text-transform:uppercase;color:var(--t2)"><?= str_replace('_',' ',$n['node_type']) ?></td>
            <td>
                <?php $vc = ['public'=>'var(--green)','ttn_private'=>'var(--amber)','operator_private'=>'var(--t3)']; ?>
                <span style="font-family:var(--mono);font-size:0.6rem;color:<?= $vc[$n['visibility']] ?? 'var(--t2)' ?>">
                    <?= str_replace('_',' ',$n['visibility']) ?>
                </span>
            </td>
            <td class="muted"><?= htmlspecialchars($n['sys_callsign'] ?? '—') ?><?= $n['site_name'] ? '<br><small style="font-size:0.58rem">'.$n['site_name'].'</small>' : '' ?></td>
            <td class="mono muted" style="font-size:0.65rem"><?= htmlspecialchars($n['server_host'] ?? '—') ?></td>
            <td class="mono muted"><?= htmlspecialchars($n['owner_call'] ?? 'TTN') ?></td>
            <td><?= $n['is_hub'] ? '<span style="color:var(--green)">★</span>' : '—' ?></td>
            <td><?= $n['is_active'] ? '<span style="color:var(--green)">✓</span>' : '<span style="color:var(--red)">✗</span>' ?></td>
            <td>
                <div class="actions">
                    <a href="?action=edit_node&id=<?= $n['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete node <?= htmlspecialchars($n['asl_number']) ?>?')">
                        <?= ttn_csrf_field() ?>
                        <input type="hidden" name="post_action" value="delete_node">
                        <input type="hidden" name="node_id" value="<?= $n['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Del</button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($asl_nodes)): ?><tr><td colspan="10" class="muted">No nodes in registry.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php // ══════════════════════════════════════════════════════
      // NODE EDIT / NEW
      elseif (in_array($action, ['edit_node','new_node'])): ?>
<div class="panel">
    <div class="panel-hd"><?= $edit_id ? 'Edit Node' : 'Add Node to Registry' ?> <a href="?action=nodes" class="btn btn-secondary btn-sm">← Back</a></div>
    <div class="panel-body">
    <form method="post">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="save_node">
        <input type="hidden" name="node_id" value="<?= $edit_id ?>">
        <div class="field-row3">
            <div class="field">
                <label>ASL Node Number *</label>
                <input type="text" name="asl_number" value="<?= htmlspecialchars($edit_node['asl_number'] ?? '') ?>" required placeholder="450330">
            </div>
            <div class="field">
                <label>Callsign</label>
                <input type="text" name="callsign" value="<?= htmlspecialchars($edit_node['callsign'] ?? '') ?>" placeholder="W4BWW">
                <div style="font-size:0.58rem;color:var(--t3);margin-top:0.3rem">TTN registry — overrides lsnodes lookup</div>
            </div>
            <div class="field">
                <label>Label</label>
                <input type="text" name="label" value="<?= htmlspecialchars($edit_node['label'] ?? '') ?>" placeholder="6m Hub">
            </div>
        </div>
        <div class="field-row">
            <div class="field"><label>Node Type</label>
                <select name="node_type">
                    <?php foreach(['backbone','hub','remote_base','private','link','unknown'] as $t): ?>
                    <option value="<?=$t?>" <?=($edit_node['node_type']??'backbone')===$t?'selected':''?>><?=ucfirst(str_replace('_',' ',$t))?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Visibility</label>
                <select name="visibility">
                    <option value="public"            <?=($edit_node['visibility']??'public')==='public'?'selected':''?>>Public — shown on network pages</option>
                    <option value="ttn_private"       <?=($edit_node['visibility']??'')==='ttn_private'?'selected':''?>>TTN Private — admin only</option>
                    <option value="operator_private"  <?=($edit_node['visibility']??'')==='operator_private'?'selected':''?>>Operator Private — owner only</option>
                </select>
            </div>
        </div>
        <div class="field-row">
            <div class="field"><label>System</label>
                <select name="system_id"><option value="">— Not linked to a system —</option>
                <?php foreach($all_systems as $s): ?>
                <option value="<?=$s['id']?>" <?=($edit_node['system_id']??0)==$s['id']?'selected':''?>><?=htmlspecialchars($s['callsign'])?><?=$s['label']?' · '.$s['label']:''?> — <?=htmlspecialchars($s['site_name'])?></option>
                <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Server</label>
                <select name="server_id"><option value="">— Not assigned to a server —</option>
                <?php foreach($asl_servers as $s): ?>
                <option value="<?=$s['id']?>" <?=($edit_node['server_id']??0)==$s['id']?'selected':''?>><?=htmlspecialchars($s['hostname'])?></option>
                <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field-row">
            <div class="field"><label>Owner Operator</label>
                <select name="owner_operator_id"><option value="">— TTN Managed —</option>
                <?php foreach($all_ops as $o): ?>
                <option value="<?=$o['id']?>" <?=($edit_node['owner_operator_id']??0)==$o['id']?'selected':''?>><?=htmlspecialchars($o['callsign'])?><?=$o['display_name']?' · '.$o['display_name']:''?></option>
                <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Location Note</label>
                <input type="text" name="location_note" value="<?= htmlspecialchars($edit_node['location_note'] ?? '') ?>" placeholder="New Market TN">
            </div>
        </div>
        <div class="field-row">
            <div class="field"><label>Freq TX</label><input type="text" name="freq_tx" value="<?= htmlspecialchars($edit_node['freq_tx'] ?? '') ?>" placeholder="53.870"></div>
            <div class="field"><label>Access Code</label><input type="text" name="access_code" value="<?= htmlspecialchars($edit_node['access_code'] ?? '') ?>" placeholder="118.8"></div>
        </div>
        <div class="field"><label>Notes (internal)</label><textarea name="notes" rows="2"><?= htmlspecialchars($edit_node['notes'] ?? '') ?></textarea></div>
        <div style="display:flex;gap:2rem;flex-wrap:wrap;margin-bottom:1rem">
            <div class="check-row"><input type="checkbox" name="is_hub" id="ih" <?= ($edit_node['is_hub']??0)?'checked':'' ?>><label for="ih">Hub node</label></div>
            <div class="check-row"><input type="checkbox" name="is_active" id="ia2" <?= ($edit_node['is_active']??1)?'checked':'' ?>><label for="ia2">Active</label></div>
        </div>
        <div style="display:flex;gap:0.7rem;margin-top:1rem">
            <button type="submit" class="btn btn-primary">Save Node</button>
            <a href="?action=nodes" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
    </div>
</div>

<?php // ══════════════════════════════════════════════════════
      // SUBNETS
      elseif ($action === 'subnets'): ?>
<div class="panel"><div class="panel-hd">Subnets (<?= count($subnets) ?>)</div>
<table class="adm-tbl"><thead><tr><th>Site</th><th>Subnet</th><th>Type</th><th>Label</th><th>Gateway</th><th>Managed</th></tr></thead><tbody>
<?php foreach($subnets as $sn): ?><tr>
<td class="mono muted"><?=htmlspecialchars($sn['site_name']??'—')?></td>
<td class="mono" style="color:var(--amber)"><?=htmlspecialchars($sn['subnet'])?>/<?=$sn['cidr']?></td>
<td class="muted" style="font-size:0.7rem"><?=$sn['network_type']?></td>
<td><?=htmlspecialchars($sn['label'])?></td>
<td class="mono muted"><?=htmlspecialchars($sn['gateway']??'—')?></td>
<td><?=$sn['is_ttn_managed']?'<span style="color:var(--green)">✓</span>':'—'?></td>
</tr><?php endforeach; ?>
<?php if(empty($subnets)): ?><tr><td colspan="6" class="muted">No subnets.</td></tr><?php endif; ?>
</tbody></table></div>

<?php // ══════════════════════════════════════════════════════
      // DEVICES
      elseif ($action === 'devices'): ?>
<div class="panel"><div class="panel-hd">Devices (<?= count($devices) ?>)</div>
<table class="adm-tbl"><thead><tr><th>Site</th><th>Hostname</th><th>Type</th><th>IP</th><th>ASL Nodes</th><th>ASL Port</th><th>Active</th></tr></thead><tbody>
<?php foreach($devices as $dev): ?><tr style="<?=!$dev['is_active']?'opacity:0.5':''?>">
<td class="mono muted"><?=htmlspecialchars($dev['site_name']??'—')?></td>
<td class="mono"><?=htmlspecialchars($dev['hostname'])?></td>
<td style="font-family:var(--mono);font-size:0.6rem;text-transform:uppercase;color:var(--amber)"><?=str_replace('_',' ',$dev['device_type'])?></td>
<td class="mono muted"><?=htmlspecialchars($dev['ip_address']??'—')?></td>
<td class="mono" style="font-size:0.65rem;color:var(--green)"><?=htmlspecialchars($dev['asl_nodes_json']??'—')?></td>
<td class="mono muted"><?=$dev['asl_port']??'—'?></td>
<td><?=$dev['is_active']?'<span style="color:var(--green)">✓</span>':'<span style="color:var(--red)">✗</span>'?></td>
</tr><?php endforeach; ?>
<?php if(empty($devices)): ?><tr><td colspan="7" class="muted">No devices.</td></tr><?php endif; ?>
</tbody></table></div>

<?php endif; ?>
</div></div>

<?php require_once TTN_INCLUDES . '/admin_head.php'; // footer scripts ?>
</body></html>
