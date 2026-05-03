<?php
/**
 * TTN Portal — api/session-context.php
 *
 * Structured JSON situation report for any consumer that needs to reason
 * about live TTN portal state. Designed for portal dev chats, AI context
 * loads, VLAN/telemetry systems, and eventually Bobby's own AI stack.
 *
 * Auth: shared secret via ?k= parameter (same pattern as diag.php)
 * Method: GET
 * Output: application/json
 *
 * Consumers:
 *   - Portal dev / DB work chats    → use at session start
 *   - TTN Organize Hub              → use at session start
 *   - VLAN IP logging systems       → poll for site/server state
 *   - Future: Bobby's AI            → full situational awareness
 *   - NOT needed: pure RF/hardware chats with no DB component
 *
 * Deploy to: /home/obdswlpx/public_html/api/session-context.php
 * Access:    https://dev.ttn.radio/api/session-context.php?k=ttndiag2026
 */

declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

// ── Auth ─────────────────────────────────────────────────────────────────────
// Uses same key as diag.php. Rotate both together if key changes.
$valid_key = 'ttndiag2026';
if (($_GET['k'] ?? '') !== $valid_key) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// ── DB ───────────────────────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/../ttn_config.php';  // above web root

try {
    $dsn = "mysql:host={$ttn_db_host};dbname={$ttn_db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $ttn_db_user, $ttn_db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone='+00:00'");
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed', 'detail' => $e->getMessage()]);
    exit;
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Return seconds since a UTC datetime string, or null if null/empty.
 */
function seconds_ago(?string $dt): ?int {
    if (!$dt) return null;
    return (int)(new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp()
        - (new DateTimeImmutable($dt, new DateTimeZone('UTC')))->getTimestamp();
}

/**
 * Classify a last_seen age into a health status string.
 * LIVE   < 10 min
 * STALE  10 min – 24 h
 * DEAD   > 24 h
 * NEVER  null
 */
function server_health(?string $last_seen): string {
    $s = seconds_ago($last_seen);
    if ($s === null)   return 'NEVER';
    if ($s < 600)      return 'LIVE';
    if ($s < 86400)    return 'STALE';
    return 'DEAD';
}

/**
 * Collect flags: plain-English strings describing anything wrong or missing.
 * These are the things a consumer should act on.
 */
$flags = [];

// ── 1. Generated timestamp ───────────────────────────────────────────────────
$generated_at = $now->format('Y-m-d\TH:i:s\Z');

// ── 2. Portal identity ───────────────────────────────────────────────────────
$settings_raw = $pdo->query(
    "SELECT setting_key, setting_val FROM site_settings"
)->fetchAll();
$settings = [];
foreach ($settings_raw as $row) {
    $settings[$row['setting_key']] = $row['setting_val'];
}
$portal = [
    'site_url'       => $settings['site_url']       ?? null,
    'org_name'       => $settings['org_name']        ?? null,
    'org_callsign'   => $settings['org_callsign']    ?? null,
    'hub_node'       => $settings['hub_node']        ?? null,
    'hub_url'        => $settings['hub_url']         ?? null,
];

// ── 3. ASL Servers ───────────────────────────────────────────────────────────
$servers_raw = $pdo->query("
    SELECT s.id, s.hostname, s.public_ip, s.asterisk_ip, s.ami_port, s.asl_port,
           s.asl_version, s.has_isp, s.ttn_logger_installed, s.ttn_status_installed,
           s.last_seen, s.is_active,
           si.name AS site_name
    FROM asl_servers s
    LEFT JOIN sites si ON si.id = s.site_id
    ORDER BY s.hostname
")->fetchAll();

$servers = [];
foreach ($servers_raw as $srv) {
    $health  = server_health($srv['last_seen']);
    $age_sec = seconds_ago($srv['last_seen']);

    // Flag stale / never servers
    // Servers without a public ISP (e.g. hub.ttn.radio proxied through NPM on tn.w4bww.net)
    // will always show NEVER — that is by design, not a gap. Only flag ISP-bearing servers.
    if ($srv['has_isp']) {
        if ($health === 'NEVER') {
            $flags[] = "SERVER_NEVER: {$srv['hostname']} has never checked in — logger may not be deployed or DB record added after deployment";
        } elseif ($health === 'STALE') {
            $age_h = round($age_sec / 3600, 1);
            $flags[] = "SERVER_STALE: {$srv['hostname']} last seen {$age_h}h ago — logger may be down or cron stopped";
        } elseif ($health === 'DEAD') {
            $age_d = round($age_sec / 86400, 1);
            $flags[] = "SERVER_DEAD: {$srv['hostname']} last seen {$age_d}d ago — likely offline";
        }

        // Only flag logger missing on servers that have a public ISP and can POST
        if ($srv['is_active'] && !$srv['ttn_logger_installed']) {
            $flags[] = "LOGGER_MISSING: {$srv['hostname']} — ttn-logger.php not marked installed";
        }
    }

    $servers[] = [
        'id'               => (int)$srv['id'],
        'hostname'         => $srv['hostname'],
        'site_name'        => $srv['site_name'],
        'public_ip'        => $srv['public_ip'] ?: null,
        'asterisk_ip'      => $srv['asterisk_ip'],
        'ami_port'         => (int)$srv['ami_port'],
        'asl_port'         => (int)$srv['asl_port'],
        'asl_version'      => $srv['asl_version'],
        'has_isp'          => (bool)$srv['has_isp'],
        'logger_installed' => (bool)$srv['ttn_logger_installed'],
        'status_installed' => (bool)$srv['ttn_status_installed'],
        'last_seen'        => $srv['last_seen'],
        'last_seen_sec'    => $age_sec,
        'health'           => $health,
        'is_active'        => (bool)$srv['is_active'],
    ];
}

// ── 4. Node Registry gaps ────────────────────────────────────────────────────
// Nodes with no server assigned are a known gap pattern (CRA example)
$nodes_raw = $pdo->query("
    SELECT a.id, a.asl_number, a.callsign, a.node_type, a.visibility,
           a.is_active, a.owner_operator_id,
           si.name AS site_name,
           srv.hostname AS server_hostname
    FROM sys_asl a
    LEFT JOIN systems sys ON sys.id = a.system_id
    LEFT JOIN sites si ON si.id = sys.site_id
    LEFT JOIN asl_servers srv ON srv.id = a.server_id
    ORDER BY a.asl_number
")->fetchAll();

$nodes = [];
foreach ($nodes_raw as $n) {
    if ($n['is_active'] && !$n['server_hostname']) {
        $flags[] = "NODE_NO_SERVER: ASL {$n['asl_number']} ({$n['callsign'] ?: 'no callsign'}) at {$n['site_name']} has no server record — telemetry POSTs will fail";
    }
    $nodes[] = [
        'id'             => (int)$n['id'],
        'asl_number'     => (int)$n['asl_number'],
        'callsign'       => $n['callsign'] ?: null,
        'node_type'      => $n['node_type'],
        'visibility'     => $n['visibility'],
        'site_name'      => $n['site_name'],
        'server'         => $n['server_hostname'] ?: null,
        'is_active'      => (bool)$n['is_active'],
        'has_server'     => !empty($n['server_hostname']),
    ];
}

// ── 5. Sites ─────────────────────────────────────────────────────────────────
$sites_raw = $pdo->query("
    SELECT id, name, city, state, status, phase
    FROM sites
    ORDER BY name
")->fetchAll();

$sites = [];
foreach ($sites_raw as $s) {
    $sites[] = [
        'id'     => (int)$s['id'],
        'name'   => $s['name'],
        'city'   => $s['city'],
        'state'  => $s['state'],
        'status' => $s['status'],
        'phase'  => $s['phase'],
    ];
}

// ── 6. Systems ───────────────────────────────────────────────────────────────
$systems_raw = $pdo->query("
    SELECT sys.id, sys.callsign, sys.description, sys.status,
           si.name AS site_name
    FROM systems sys
    LEFT JOIN sites si ON si.id = sys.site_id
    ORDER BY si.name, sys.callsign
")->fetchAll();

$systems = [];
foreach ($systems_raw as $sys) {
    $systems[] = [
        'id'          => (int)$sys['id'],
        'callsign'    => $sys['callsign'],
        'description' => $sys['description'],
        'status'      => $sys['status'],
        'site_name'   => $sys['site_name'],
    ];
}

// ── 7. Telemetry — latest per system ─────────────────────────────────────────
// sys_telemetry stores periodic logger POSTs. Stale telemetry = silent site.
$telem_raw = $pdo->query("
    SELECT t.system_id, t.recorded_at, t.online, t.connected_nodes, t.last_keyed,
           sys.callsign, sys.description,
           si.name AS site_name
    FROM sys_telemetry t
    JOIN (
        SELECT system_id, MAX(recorded_at) AS max_rec
        FROM sys_telemetry
        GROUP BY system_id
    ) latest ON latest.system_id = t.system_id AND latest.max_rec = t.recorded_at
    LEFT JOIN systems sys ON sys.id = t.system_id
    LEFT JOIN sites si ON si.id = sys.site_id
    ORDER BY t.recorded_at DESC
")->fetchAll();

$telemetry = [];
foreach ($telem_raw as $t) {
    $age_sec = seconds_ago($t['recorded_at']);
    $age_h   = $age_sec !== null ? round($age_sec / 3600, 1) : null;

    // Flag telemetry older than 24h for active systems
    if ($age_sec !== null && $age_sec > 86400) {
        $flags[] = "TELEMETRY_STALE: {$t['callsign']} ({$t['site_name']}) last telemetry {$age_h}h ago";
    }

    $telemetry[] = [
        'system_id'       => (int)$t['system_id'],
        'callsign'        => $t['callsign'],
        'description'     => $t['description'],
        'site_name'       => $t['site_name'],
        'recorded_at'     => $t['recorded_at'],
        'recorded_sec'    => $age_sec,
        'online'          => (bool)$t['online'],
        'connected_nodes' => (int)$t['connected_nodes'],
        'last_keyed'      => $t['last_keyed'] ?: null,
    ];
}

// ── 8. Connection log — recent events ────────────────────────────────────────
$conn_raw = $pdo->query("
    SELECT c.system_id, c.asl_node, c.direction, c.connected_at, c.disconnected_at,
           c.remote_callsign,
           sys.callsign AS system_call,
           si.name AS site_name
    FROM conn_log c
    LEFT JOIN systems sys ON sys.id = c.system_id
    LEFT JOIN sites si ON si.id = sys.site_id
    ORDER BY c.connected_at DESC
    LIMIT 20
")->fetchAll();

$conn_log = [];
foreach ($conn_raw as $c) {
    $conn_log[] = [
        'system_call'     => $c['system_call'],
        'site_name'       => $c['site_name'],
        'asl_node'        => (int)$c['asl_node'],
        'remote_callsign' => $c['remote_callsign'] ?: null,
        'direction'       => $c['direction'],
        'connected_at'    => $c['connected_at'],
        'disconnected_at' => $c['disconnected_at'] ?: null,
    ];
}

// ── 9. SERA coordination ─────────────────────────────────────────────────────
$sera_raw = $pdo->query("
    SELECT s.id, s.trustee_call, s.status, s.coordinated_at,
           s.expires_at, s.recertified_at,
           sys.callsign AS system_call, sys.description,
           si.name AS site_name
    FROM sera_records s
    LEFT JOIN systems sys ON sys.id = s.system_id
    LEFT JOIN sites si ON si.id = sys.site_id
    ORDER BY s.expires_at ASC
")->fetchAll();

$sera = [];
foreach ($sera_raw as $s) {
    // Flag SERA expiring within 90 days
    $days_left = null;
    if ($s['expires_at']) {
        $exp = new DateTimeImmutable($s['expires_at'], new DateTimeZone('UTC'));
        $days_left = (int)$now->diff($exp)->days * ($exp > $now ? 1 : -1);
        if ($days_left < 0) {
            $flags[] = "SERA_EXPIRED: {$s['trustee_call']} ({$s['site_name']}) SERA expired {$days_left}d ago";
        } elseif ($days_left < 90) {
            $flags[] = "SERA_EXPIRING: {$s['trustee_call']} ({$s['site_name']}) SERA expires in {$days_left}d";
        }
    }
    $sera[] = [
        'id'             => (int)$s['id'],
        'trustee_call'   => $s['trustee_call'],
        'system_call'    => $s['system_call'],
        'site_name'      => $s['site_name'],
        'status'         => $s['status'],
        'coordinated_at' => $s['coordinated_at'],
        'expires_at'     => $s['expires_at'],
        'recertified_at' => $s['recertified_at'] ?: null,
        'days_until_exp' => $days_left,
    ];
}

// ── 10. Grant deadlines ──────────────────────────────────────────────────────
// Hardcoded from project brief — not yet in DB. When grants table exists,
// replace this block with a DB query.
$grants_hardcoded = [
    [
        'grant_id'    => 'GRANT-ARDC-2026',
        'funder'      => 'ARDC',
        'ask'         => 37500,
        'status'      => 'Drafting',
        'deadline'    => '2026-09-01',
        'notes'       => 'Rejected March 2026. Resubmitting. New ask ~$37,500.',
    ],
    [
        'grant_id'    => 'GRANT-ARRL-2026',
        'funder'      => 'ARRL Foundation',
        'ask'         => 5000,
        'status'      => 'Drafting',
        'deadline'    => '2026-10-01',
        'notes'       => '$5,000 ask. In progress.',
    ],
];

$grants = [];
foreach ($grants_hardcoded as $g) {
    $deadline_dt = new DateTimeImmutable($g['deadline'], new DateTimeZone('UTC'));
    $days_left   = (int)$now->diff($deadline_dt)->days * ($deadline_dt > $now ? 1 : -1);

    // Flag grants within 60 days of deadline
    if ($days_left >= 0 && $days_left < 60) {
        $flags[] = "GRANT_DEADLINE_SOON: {$g['funder']} deadline in {$days_left}d ({$g['deadline']})";
    } elseif ($days_left < 0) {
        $flags[] = "GRANT_DEADLINE_PASSED: {$g['funder']} deadline passed {$days_left}d ago";
    }

    $grants[] = array_merge($g, ['days_until_deadline' => $days_left]);
}

// ── 11. Open UPDATE BRIEF flags ──────────────────────────────────────────────
// When a roadmap_items / tasks table gains update_brief_flag column, query it.
// For now emit a placeholder so consumers know the pattern exists.
// TODO: replace stub with real query once tasks table has update_brief_flag col.
$update_brief_flags = [];
// Example future query:
// $ubf = $pdo->query("SELECT id, title, notes FROM roadmap_items WHERE update_brief_flag = 1")->fetchAll();
// foreach ($ubf as $item) { $update_brief_flags[] = [...]; }

// ── 12. Network subnets / devices (VLAN awareness) ───────────────────────────
// If network_subnets and network_devices tables are populated, surface them.
// Consumers doing VLAN IP logging want to know what subnets are tracked.
$subnets = [];
try {
    $subnets = $pdo->query("
        SELECT id, name, cidr, vlan_id, description
        FROM network_subnets
        ORDER BY vlan_id
    ")->fetchAll();
} catch (PDOException $e) {
    // Table may be empty or schema may differ — not fatal
}

$network_devices = [];
try {
    $network_devices = $pdo->query("
        SELECT id, hostname, ip_address, mac_address, device_type, site_id, notes
        FROM network_devices
        ORDER BY ip_address
    ")->fetchAll();
} catch (PDOException $e) {
    // Not fatal
}

// ── 13. Operators ────────────────────────────────────────────────────────────
$operators_raw = $pdo->query("
    SELECT id, callsign, role_level, created_at
    FROM operators
    ORDER BY role_level DESC, callsign
")->fetchAll();

$role_map = [1 => 'viewer', 2 => 'operator', 3 => 'site_admin', 4 => 'admin'];
$operators = [];
foreach ($operators_raw as $op) {
    $operators[] = [
        'id'         => (int)$op['id'],
        'callsign'   => $op['callsign'],
        'role_level' => (int)$op['role_level'],
        'role_name'  => $role_map[(int)$op['role_level']] ?? 'unknown',
        'created_at' => $op['created_at'],
    ];
}

// ── 14. Summary counts ───────────────────────────────────────────────────────
$server_health_counts = ['LIVE' => 0, 'STALE' => 0, 'DEAD' => 0, 'NEVER' => 0];
foreach ($servers as $srv) {
    $server_health_counts[$srv['health']]++;
}

$nodes_no_server   = count(array_filter($nodes, fn($n) => !$n['has_server'] && $n['is_active']));
$sites_live        = count(array_filter($sites, fn($s) => $s['status'] === 'live'));
$sites_building    = count(array_filter($sites, fn($s) => $s['status'] === 'building'));
$sites_planned     = count(array_filter($sites, fn($s) => $s['status'] === 'planned'));

$summary = [
    'servers_live'        => $server_health_counts['LIVE'],
    'servers_stale'       => $server_health_counts['STALE'],
    'servers_dead'        => $server_health_counts['DEAD'],
    'servers_never'       => $server_health_counts['NEVER'],
    'nodes_total'         => count($nodes),
    'nodes_no_server'     => $nodes_no_server,
    'sites_live'          => $sites_live,
    'sites_building'      => $sites_building,
    'sites_planned'       => $sites_planned,
    'telemetry_systems'   => count($telemetry),
    'conn_log_events'     => count($conn_log),
    'sera_records'        => count($sera),
    'open_flags'          => count($flags),
    'update_brief_flags'  => count($update_brief_flags),
];

// ── Assemble response ────────────────────────────────────────────────────────
$response = [
    'generated_at'        => $generated_at,
    'endpoint_version'    => '1.0',
    'summary'             => $summary,
    'flags'               => $flags,           // Act on these first
    'portal'              => $portal,
    'asl_servers'         => $servers,
    'node_registry'       => $nodes,
    'sites'               => $sites,
    'systems'             => $systems,
    'telemetry'           => $telemetry,
    'conn_log'            => $conn_log,
    'sera'                => $sera,
    'grants'              => $grants,
    'update_brief_flags'  => $update_brief_flags,
    'network_subnets'     => $subnets,
    'network_devices'     => $network_devices,
    'operators'           => $operators,
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
