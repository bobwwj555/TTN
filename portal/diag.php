<?php
require_once '/home/obdswlpx/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';

$key = $_GET['k'] ?? '';
if ($key !== 'ttndiag2026') { http_response_code(403); die('403'); }

header('Content-Type: text/plain; charset=utf-8');

$line = str_repeat('═', 60);
$thin = str_repeat('─', 60);

function ago(?string $dt): string {
    if (!$dt) return '—';
    $diff = time() - strtotime($dt);
    $future = $diff < 0;
    $diff = abs($diff);
    $str = '';
    if ($diff < 60)    $str = $diff . 's';
    elseif ($diff < 3600)  $str = floor($diff/60) . 'm';
    elseif ($diff < 86400) $str = floor($diff/3600) . 'h';
    else $str = floor($diff/86400) . 'd';
    return $future ? "in $str" : "$str ago";
}

echo "TTN PORTAL DIAGNOSTICS\n";
echo date('Y-m-d H:i:s T') . "\n";
echo $line . "\n\n";

// ── PHP / SERVER ──────────────────────────────────────────────
echo "=== PHP / SERVER ===\n";
echo "PHP:        " . PHP_VERSION . "\n";
echo "Server:     " . ($_SERVER['SERVER_NAME'] ?? '—') . "\n";
echo "Remote IP:  " . ($_SERVER['REMOTE_ADDR'] ?? '—') . "\n";
echo "Extensions: pdo=" . (extension_loaded('pdo')?'✓':'✗') . 
     " pdo_mysql=" . (extension_loaded('pdo_mysql')?'✓':'✗') .
     " json=" . (extension_loaded('json')?'✓':'✗') . "\n\n";

// ── KEY SETTINGS ──────────────────────────────────────────────
echo "=== KEY SETTINGS ===\n";
$important = ['site_url','hub_node','hub_url','ami_proxy_url','telemetry_secret','org_name','org_callsign'];
$settings = db_rows("SELECT setting_key, setting_val FROM site_settings ORDER BY setting_key");
$smap = array_column($settings, 'setting_val', 'setting_key');
foreach ($important as $k) {
    $v = $smap[$k] ?? '(not set)';
    if ($k === 'telemetry_secret') $v = substr($v,0,6) . '...';
    echo str_pad($k, 22) . $v . "\n";
}
echo "\n";

// ── ASL SERVERS ───────────────────────────────────────────────
echo "=== ASL SERVERS ===\n";
$servers = db_rows("SELECT s.*, si.name AS site_name, CONVERT_TZ(s.last_seen, @@session.time_zone, '+00:00') AS last_seen_utc FROM asl_servers s LEFT JOIN sites si ON si.id=s.site_id ORDER BY s.hostname");
foreach ($servers as $srv) {
    $age = $srv['last_seen_utc'] ? ago($srv['last_seen_utc']) : 'NEVER';
    $health = $srv['last_seen_utc'] ? (time()-strtotime($srv['last_seen_utc']) < 300 ? '● LIVE' : '✕ STALE') : '○ NO DATA';
    echo "[$health] {$srv['hostname']}\n";
    echo "  Site:       " . ($srv['site_name'] ?? '—') . "\n";
    echo "  Public IP:  " . ($srv['ip_address'] ?? '—') . "\n";
    echo "  Asterisk:   " . ($srv['asterisk_ip'] ?? '—') . " AMI:{$srv['ami_port']} ASL:{$srv['asl_port']}\n";
    echo "  ASL Ver:    " . ($srv['asl_version'] ?? '—') . " / " . ($srv['os'] ?? '—') . "\n";
    echo "  ISP:        " . ($srv['has_isp'] ? '✓' : '✗') . 
         "  Logger: " . ($srv['ttn_logger_installed'] ? '✓' : '✗') .
         "  Status.php: " . ($srv['ttn_status_installed'] ? '✓' : '✗') . "\n";
    echo "  Last seen:  $age\n";
    echo "  Active:     " . ($srv['is_active'] ? '✓' : '✗') . "\n\n";
}
if (empty($servers)) echo "(none)\n\n";

// ── NODE REGISTRY ─────────────────────────────────────────────
echo "=== NODE REGISTRY (sys_asl) ===\n";
$nodes = db_rows("
    SELECT n.*, s.callsign AS sys_call, s.freq_tx, si.name AS site_name, 
           srv.hostname AS server_host, op.callsign AS owner_call
    FROM sys_asl n
    LEFT JOIN systems s   ON s.id   = n.system_id
    LEFT JOIN sites si    ON si.id  = s.site_id
    LEFT JOIN asl_servers srv ON srv.id = n.server_id
    LEFT JOIN operators op ON op.id = n.owner_operator_id
    ORDER BY n.is_active DESC, n.asl_number
");
foreach ($nodes as $n) {
    $vis = ['public'=>'PUB','ttn_private'=>'TTN','operator_private'=>'OP'][$n['visibility']] ?? '?';
    echo "[{$vis}] ASL {$n['asl_number']}";
    echo " · " . ($n['callsign'] ?? '—');
    echo " · " . str_replace('_',' ',$n['node_type']);
    echo " · " . ($n['sys_call'] ?? 'unlinked');
    echo " · " . ($n['site_name'] ?? '—');
    echo " · " . ($n['server_host'] ?? 'no server');
    echo " · owner:" . ($n['owner_call'] ?? 'TTN');
    echo $n['is_hub'] ? ' ★HUB' : '';
    echo $n['is_active'] ? '' : ' [INACTIVE]';
    echo "\n";
}
if (empty($nodes)) echo "(none)\n";
echo "\n";

// ── TELEMETRY STATUS ──────────────────────────────────────────
echo "=== TELEMETRY (latest per system) ===\n";
try {
    $telem = db_rows("
        SELECT st.*, s.callsign, s.label, s.system_type, si.name AS site_name
        FROM sys_telemetry st
        JOIN systems s ON s.id = st.system_id
        JOIN sites si  ON si.id = s.site_id
        WHERE st.id IN (
            SELECT MAX(id) FROM sys_telemetry GROUP BY system_id
        )
        ORDER BY st.recorded_at DESC
    ");
    foreach ($telem as $t) {
        $age = ago($t['recorded_at']);
        echo "[{$t['callsign']}] {$t['site_name']} · " . ($t['label'] ? $t['label'] : $t['system_type']) . "\n";
        echo "  Recorded:  $age\n";
        echo "  Online:    " . ($t['is_online'] ? '✓' : '✗') . "\n";
        echo "  Connected: " . ($t['connected_nodes'] ?? 0) . " nodes\n";
        echo "  Last keyed:" . ($t['last_keyed_at'] ? ago($t['last_keyed_at']) : '—') . "\n\n";
    }
    if (empty($telem)) echo "(no telemetry yet)\n\n";
} catch (Exception $e) { echo "ERROR: " . $e->getMessage() . "\n\n"; }

// ── CONN LOG ──────────────────────────────────────────────────
echo "=== CONN LOG (last 10 events) ===\n";
try {
    $conns = db_rows("
        SELECT cl.*, s.callsign AS sys_call
        FROM conn_log cl
        JOIN systems s ON s.id = cl.system_id
        ORDER BY cl.connected_at DESC
        LIMIT 10
    ");
    foreach ($conns as $c) {
        echo "[{$c['sys_call']}] ASL {$c['connected_node']}";
        echo " " . ($c['callsign'] ?: '—');
        echo " " . ($c['direction'] ?: '');
        echo " connected:" . ago($c['connected_at']);
        echo $c['disconnected_at'] ? " disconnected:".ago($c['disconnected_at']) : " (active)";
        echo "\n";
    }
    if (empty($conns)) echo "(no connection events yet)\n";
} catch (Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }
echo "\n";

// ── SERA RECORDS ──────────────────────────────────────────────
echo "=== SERA COORDINATION ===\n";
try {
    $seras = db_rows("
        SELECT sr.*, s.callsign, si.name AS site_name
        FROM sera_records sr
        JOIN systems s ON s.id = sr.system_id
        JOIN sites si  ON si.id = s.site_id
        ORDER BY sr.coordinated_at DESC
    ");
    foreach ($seras as $sr) {
        $exp = $sr['expires_at'] ? (strtotime($sr['expires_at']) < time() ? '⚠ EXPIRED' : 'expires '.ago($sr['expires_at'])) : '—';
        echo "[{$sr['status']}] SERA {$sr['sera_id']} · {$sr['callsign']} · {$sr['site_name']}\n";
        echo "  Coordinated: " . ($sr['coordinated_at'] ?? '—') . " · Recertify: " . ($sr['expires_at'] ?? '—') . " ($exp)\n";
        echo "  Trustee: " . ($sr['trustee_call'] ?? '—') . " · Alt: " . ($sr['alt_call'] ?? '—') . "\n\n";
    }
    if (empty($seras)) echo "(none)\n\n";
} catch (Exception $e) { echo "ERROR: " . $e->getMessage() . "\n\n"; }

// ── SITES ─────────────────────────────────────────────────────
echo "=== SITES ===\n";
$sites = db_rows("SELECT id, name, city, state, status, phase FROM sites ORDER BY id");
foreach ($sites as $s) {
    echo "[{$s['status']}] #{$s['id']} {$s['name']} · {$s['city']}, {$s['state']} · Phase {$s['phase']}\n";
}
echo "\n";

// ── SYSTEMS ───────────────────────────────────────────────────
echo "=== SYSTEMS ===\n";
$systems = db_rows("
    SELECT s.id, s.callsign, s.label, s.system_type, s.freq_tx, s.status, si.name AS site_name
    FROM systems s JOIN sites si ON si.id=s.site_id
    ORDER BY si.id, s.sort_order
");
foreach ($systems as $s) {
    echo "#{$s['id']} [{$s['site_name']}] {$s['callsign']}";
    echo $s['label'] ? " · {$s['label']}" : '';
    echo " · {$s['system_type']}";
    echo $s['freq_tx'] ? " · {$s['freq_tx']}" : '';
    echo " [{$s['status']}]\n";
}
echo "\n";

// ── OPERATORS ─────────────────────────────────────────────────
echo "=== OPERATORS ===\n";
$ops = db_rows("SELECT id, callsign, role, is_active FROM operators ORDER BY id");
foreach ($ops as $o) {
    echo "#{$o['id']} {$o['callsign']} · {$o['role']}" . ($o['is_active'] ? '' : ' [INACTIVE]') . "\n";
}
echo "\n";

echo $line . "\n";
echo "END DIAGNOSTICS · " . date('H:i:s') . "\n";
