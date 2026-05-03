<?php
/**
 * TTN Node Status Proxy — ASL3 Edition
 * LOCATION: /var/www/html/ttn-status.php  (on tn.w4bww.net)
 *
 * Serves cached data from ttn-logger.php cron first (fast path).
 * Falls back to live AMI poll using ASL3 XStat/SawStat commands.
 *
 * Usage: /ttn-status.php?node=450330
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$node = preg_replace('/[^0-9]/', '', $_GET['node'] ?? '');
if (!$node) {
    echo json_encode(['ok' => false, 'error' => 'No node specified']);
    exit;
}

// ── CACHE FAST PATH ───────────────────────────────────────────
// ttn-logger.php (cron, every minute) writes /tmp/ttn_node_cache.json
$cache_file = '/tmp/ttn_node_cache.json';
$cache_max  = 90; // seconds

if (file_exists($cache_file)) {
    $cache_age = time() - filemtime($cache_file);
    if ($cache_age < $cache_max) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if ($cached && isset($cached['nodes'][$node])) {
            $nd = $cached['nodes'][$node];
            if (!empty($nd['ok'])) {
                $nd['cached']     = true;
                $nd['cache_age']  = $cache_age;
                $nd['fetched_at'] = time();
                $nd['ts']         = date('H:i:s');
                echo json_encode($nd);
                exit;
            }
        }
    }
}
// Cache miss or stale — fall through to live AMI poll

// ── READ INI FILE ─────────────────────────────────────────────
$ini_paths = [
    '/etc/allmon3/allmon3.ini',
    '/usr/local/etc/allmon3/allmon3.ini',
    '/var/www/html/allmon3.ini',
    '/etc/asterisk/allmon.ini',
    '/var/www/html/allmon.ini',
    '/usr/local/etc/allmon.ini',
];

$ini_file = null;
foreach ($ini_paths as $path) {
    if (file_exists($path) && is_readable($path)) { $ini_file = $path; break; }
}

if (!$ini_file) {
    echo json_encode(['ok' => false, 'error' => 'Could not find allmon3.ini']);
    exit;
}

function parse_allmon_ini(string $path): array {
    $result = []; $section = null;
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim(preg_replace('/\s*[;#].*$/', '', $line));
        if ($line === '') continue;
        if (preg_match('/^\[(\d+)\]$/', $line, $m)) { $section = $m[1]; $result[$section] = []; continue; }
        if ($section && strpos($line, '=') !== false) { [$k,$v] = explode('=',$line,2); $result[$section][trim($k)] = trim($v); }
    }
    return $result;
}

$ini = parse_allmon_ini($ini_file);
$cfg = $ini[$node] ?? $ini[array_key_first($ini)] ?? null;
if (!$cfg) {
    echo json_encode(['ok' => false, 'error' => "Node $node not in ini file"]);
    exit;
}

$ami_host = $cfg['host'] ?? '127.0.0.1';
$ami_port = (int)($cfg['port'] ?? $cfg['ami_port'] ?? 5038);
$ami_user = $cfg['user'] ?? $cfg['ami_user'] ?? 'admin';
$ami_pass = $cfg['pass'] ?? $cfg['passwd']   ?? $cfg['password'] ?? $cfg['ami_pass'] ?? '';
$timeout  = 5;

// ── LSNODES CACHE ─────────────────────────────────────────────
$lsnodes_cache = '/tmp/ttn_lsnodes.json';
$lsnodes = [];
if (file_exists($lsnodes_cache) && (time() - filemtime($lsnodes_cache)) < 3600) {
    $lsnodes = json_decode(file_get_contents($lsnodes_cache), true) ?? [];
}

// ── CONNECT TO AMI ────────────────────────────────────────────
$sock = @fsockopen($ami_host, $ami_port, $errno, $errstr, $timeout);
if (!$sock) {
    echo json_encode(['ok' => false, 'error' => "AMI connection failed: $errstr ($errno)"]);
    exit;
}
stream_set_timeout($sock, $timeout);
fgets($sock, 256); // banner

// ASL3 uppercase field names
fwrite($sock, "ACTION: Login\r\nUSERNAME: $ami_user\r\nSECRET: $ami_pass\r\n\r\n");

// Drain all queued events — ASL3 sends FullyBooted + SuccessfulAuth after login
$response = ''; $logged_in = false;
stream_set_timeout($sock, 1);
while (!feof($sock)) {
    $line = fgets($sock, 256);
    if ($line === false) break;
    $response .= $line;
    if (strpos($line, 'Authentication accepted') !== false) $logged_in = true;
}
stream_set_timeout($sock, $timeout);
if (!$logged_in) {
    fclose($sock);
    echo json_encode(['ok' => false, 'error' => 'AMI login failed']);
    exit;
}

// ── XSTAT ─────────────────────────────────────────────────────
fwrite($sock, "ACTION: RptStatus\r\nCOMMAND: XStat\r\nNODE: $node\r\n\r\n");
$xstat_raw = ''; $start = time();
while (!feof($sock) && (time() - $start) < $timeout) {
    $line       = fgets($sock, 512);
    $xstat_raw .= $line;
    if (strpos(trim($line), 'tel_mode:') !== false) break;
}

// ── SAWSTAT ───────────────────────────────────────────────────
fwrite($sock, "ACTION: RptStatus\r\nCOMMAND: SawStat\r\nNODE: $node\r\n\r\n");
$saw_raw = ''; $start = time(); $in_saw = false;
while (!feof($sock) && (time() - $start) < $timeout) {
    $line     = fgets($sock, 512);
    $saw_raw .= $line;
    $trim = trim($line);
    if (strpos($trim, 'Response: Success') !== false) $in_saw = true;
    if ($in_saw && $trim === '') break;
}

fclose($sock);

// ── PARSE XSTAT ───────────────────────────────────────────────
$connections = [];
$txkeyed  = false;
$rxkeyed  = false;

foreach (explode("\n", $xstat_raw) as $line) {
    $line = trim($line);
    if (strpos($line, 'Conn: ') === 0) {
        $ce = preg_split('/\s+/', ltrim(substr($line, 6)));
        $nname = $ce[0] ?? '';
        if (!$nname || $nname === $node) continue;
        if (count($ce) >= 6) { $dir = $ce[3] ?? ''; }
        elseif (count($ce) >= 5) { $dir = $ce[2] ?? ''; }
        else { $dir = ''; }
        $resolved = $lsnodes[$nname] ?? [];
        $connections[] = [
            'node'      => $nname,
            'callsign'  => $resolved['callsign'] ?? '',
            'location'  => $resolved['location'] ?? '',
            'direction' => $dir,
            'mode'      => '',
        ];
    }
    if (strpos($line, 'LinkedNodes:') === 0) {
        $mode_map = ['T' => 'Transceive', 'R' => 'Monitor', 'C' => 'Connecting'];
        foreach (explode(',', substr($line, 12)) as $lnk) {
            $lnk = trim($lnk);
            if (preg_match('/^([TRC])(.+)$/', $lnk, $m)) {
                foreach ($connections as &$c) {
                    if ($c['node'] === $m[2]) { $c['mode'] = $mode_map[$m[1]] ?? ''; break; }
                }
                unset($c);
            }
        }
    }
    if (strpos($line, 'Var: RPT_TXKEYED=1') !== false) $txkeyed = true;
    if (preg_match('/^Var: RPT_RXKEYED=1$/', trim($line))) $rxkeyed = true;
}

// ── PARSE SAWSTAT ─────────────────────────────────────────────
$keyed = $txkeyed || $rxkeyed;
foreach (explode("\n", $saw_raw) as $line) {
    $line = trim($line);
    if (strpos($line, 'Conn: ') === 0) {
        $ce = preg_split('/\s+/', ltrim(substr($line, 6)));
        if (isset($ce[1]) && $ce[1] === '1') $keyed = true;
    }
}

$callsign = $lsnodes[$node]['callsign'] ?? '';

echo json_encode([
    'ok'          => true,
    'node'        => $node,
    'callsign'    => $callsign,
    'keyed'       => $keyed,
    'txkeyed'     => $txkeyed,
    'rxkeyed'     => $rxkeyed,
    'conn_count'  => count($connections),
    'connections' => $connections,
    'fetched_at'  => time(),
    'ts'          => date('H:i:s'),
    'cached'      => false,
]);
