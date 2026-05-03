<?php
/**
 * TTN Node Logger — ASL3 Edition
 * LOCATION: /var/www/html/ttn-logger.php  (on tn.w4bww.net)
 *
 * Run via cron every minute (as www-data):
 *   */5 * * * * /usr/bin/php /var/www/html/ttn-logger.php >> /var/log/ttn-logger.log 2>&1
 *
 * ASL3 AMI commands used:
 *   ACTION: RptStatus / COMMAND: XStat   — connections, keyed state, direction
 *   ACTION: RptStatus / COMMAND: SawStat — PTT, sec since key/unkey
 */

// ── CONFIG ────────────────────────────────────────────────────
$conf_file = '/etc/ttn-logger.conf';
$conf      = file_exists($conf_file) ? parse_ini_file($conf_file) : [];

$portal_url    = $conf['portal_url']    ?? 'https://dev.ttn.radio/api/telemetry-receive.php';
$portal_secret = $conf['portal_secret'] ?? '';
$log_local     = $conf['log_local']     ?? '/var/log/ttn-telemetry.log';

// ── INI FILE ──────────────────────────────────────────────────
$ini_paths = [
    '/etc/allmon3/allmon3.ini',
    '/usr/local/etc/allmon3/allmon3.ini',
    '/var/www/html/allmon3.ini',
    '/etc/asterisk/allmon.ini',
    '/var/www/html/allmon.ini',
];

$ini_file = null;
foreach ($ini_paths as $path) {
    if (file_exists($path) && is_readable($path)) { $ini_file = $path; break; }
}

if (!$ini_file) { die("[" . date('Y-m-d H:i:s') . "] ERROR: No ini file found\n"); }

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
if (!$ini) { die("[" . date('Y-m-d H:i:s') . "] ERROR: Could not parse $ini_file\n"); }

// ── LSNODES CACHE ─────────────────────────────────────────────
$lsnodes_cache = '/tmp/ttn_lsnodes.json';
$lsnodes       = [];

if (!file_exists($lsnodes_cache) || (time() - filemtime($lsnodes_cache)) > 3600) {
    echo "[" . date('H:i:s') . "] Refreshing lsnodes cache...\n";
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $raw = @file_get_contents('http://www.allstarlink.org/cgi-bin/allmon/lsnodes.pl', false, $ctx);
    if ($raw) {
        $tmp = [];
        foreach (explode("\n", $raw) as $line) {
            $parts = explode('|', $line);
            if (count($parts) >= 3) {
                $tmp[trim($parts[0])] = [
                    'callsign' => trim($parts[1]),
                    'location' => trim($parts[2]),
                    'state'    => trim($parts[3] ?? ''),
                ];
            }
        }
        if (!empty($tmp)) {
            file_put_contents($lsnodes_cache, json_encode($tmp));
            $lsnodes = $tmp;
            echo "[" . date('H:i:s') . "] lsnodes: " . count($tmp) . " nodes cached\n";
        }
    }
} else {
    $lsnodes = json_decode(file_get_contents($lsnodes_cache), true) ?? [];
}

// ── AMI HELPERS ───────────────────────────────────────────────
function ami_connect(string $host, int $port, string $user, string $pass, int $timeout = 5) {
    $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$sock) return null;
    stream_set_timeout($sock, $timeout);
    fgets($sock, 256); // banner
    fwrite($sock, "ACTION: Login\r\nUSERNAME: $user\r\nSECRET: $pass\r\n\r\n");

    // Drain all queued events — ASL3 sends FullyBooted + SuccessfulAuth after login
    // Keep reading until socket goes quiet (stream timeout)
    $resp = ''; $start = time(); $logged_in = false;
    stream_set_timeout($sock, 1); // short timeout to detect end of burst
    while (!feof($sock) && time()-$start < $timeout) {
        $line = fgets($sock, 256);
        if ($line === false) break; // stream timed out — buffer empty
        $resp .= $line;
        if (strpos($line, 'Authentication accepted') !== false) $logged_in = true;
    }
    stream_set_timeout($sock, $timeout); // restore full timeout
    if (!$logged_in) { fclose($sock); return null; }
    return $sock;
}

function ami_rptstatus($sock, string $command, string $node, int $timeout = 5): string {
    fwrite($sock, "ACTION: RptStatus\r\nCOMMAND: $command\r\nNODE: $node\r\n\r\n");
    $data = ''; $start = time(); $success_count = 0;
    while (!feof($sock) && time()-$start < $timeout) {
        $line  = fgets($sock, 512);
        if ($line === false) break;
        $data .= $line;
        $trim  = trim($line);

        // Bail immediately on error — node not in Asterisk
        if (strpos($trim, 'Response: Error') !== false) break;

        // XStat hard terminator
        if (strpos($trim, 'tel_mode:') !== false) break;

        // SawStat ends with blank line after Response: Success
        if ($command === 'SawStat' && strpos($trim, 'Response: Success') !== false) {
            fgets($sock, 512); // consume trailing blank
            break;
        }
    }
    return $data;
}

// ── PARSE XSTAT RESPONSE ──────────────────────────────────────
// Returns ['connections' => [...], 'txkeyed' => bool, 'rxkeyed' => bool, 'txekeyed' => bool]
function parse_xstat(string $data, array $lsnodes, string $node): array {
    $connections = [];
    $txkeyed  = false;
    $rxkeyed  = false;
    $txekeyed = false;

    foreach (explode("\n", $data) as $line) {
        $line = trim($line);

        // Conn: NODE [IP] DIR CTIME CSTATE
        if (strpos($line, 'Conn: ') === 0) {
            $ce = preg_split('/\s+/', ltrim(substr($line, 6)));
            $nname = $ce[0] ?? '';
            if (!$nname || $nname === $node) continue;

            // 6 elements = has IP, 5 = no IP
            if (count($ce) >= 6) {
                $dir    = $ce[3] ?? '';
                $cstate = $ce[5] ?? '';
            } elseif (count($ce) >= 5) {
                $dir    = $ce[2] ?? '';
                $cstate = $ce[4] ?? '';
            } else {
                $dir = $cstate = '';
            }

            $resolved = $lsnodes[$nname] ?? [];
            $connections[] = [
                'node'      => $nname,
                'callsign'  => $resolved['callsign'] ?? '',
                'location'  => $resolved['location'] ?? '',
                'direction' => $dir,
                'state'     => $cstate,
                'mode'      => '',
            ];
        }

        // LinkedNodes: T450331,R450332 — update mode on connections
        if (strpos($line, 'LinkedNodes:') === 0) {
            $parts = explode(',', substr($line, 12));
            foreach ($parts as $lnk) {
                $lnk = trim($lnk);
                if (!$lnk || !preg_match('/^([TRCM])(.+)$/', $lnk, $m)) continue;
                $mode_map = ['T' => 'Transceive', 'R' => 'Monitor', 'C' => 'Connecting', 'M' => 'Monitor'];
                $mode = $mode_map[$m[1]] ?? 'Unknown';
                foreach ($connections as &$c) {
                    if ($c['node'] === $m[2]) { $c['mode'] = $mode; break; }
                }
                unset($c);
            }
        }

        if (strpos($line, 'Var: RPT_TXKEYED=1')  !== false) $txkeyed  = true;
        if (strpos($line, 'Var: RPT_TXEKEYED=1') !== false) $txekeyed = true;
        if (preg_match('/^Var: RPT_RXKEYED=1$/', trim($line))) $rxkeyed = true;
    }

    return compact('connections', 'txkeyed', 'rxkeyed', 'txekeyed');
}

// ── PARSE SAWSTAT RESPONSE ────────────────────────────────────
// Returns ['keyed' => bool, 'last_keyed_node' => str]
function parse_sawstat(string $data): array {
    $keyed = false;
    $last_keyed_node = '';
    foreach (explode("\n", $data) as $line) {
        $line = trim($line);
        // Conn: NODE PTT SEC_SINCE_KEY SEC_SINCE_UNKEY
        if (strpos($line, 'Conn: ') === 0) {
            $ce = preg_split('/\s+/', ltrim(substr($line, 6)));
            if (isset($ce[1]) && $ce[1] === '1') {
                $keyed = true;
                $last_keyed_node = $ce[0] ?? '';
            }
        }
    }
    return compact('keyed', 'last_keyed_node');
}

// ── POLL ALL NODES ────────────────────────────────────────────
$snapshot = [
    'secret'    => $portal_secret,
    'hostname'  => gethostname(),
    'timestamp' => time(),
    'nodes'     => [],
];

foreach ($ini as $node => $cfg) {
    if (!is_array($cfg)) continue;
    if (!ctype_digit((string)$node)) continue;

    $ami_host = $cfg['host']  ?? '127.0.0.1';
    $ami_port = (int)($cfg['port'] ?? $cfg['ami_port'] ?? 5038);
    $ami_user = $cfg['user']  ?? $cfg['ami_user']  ?? 'admin';
    $ami_pass = $cfg['pass']  ?? $cfg['passwd']    ?? $cfg['password'] ?? $cfg['ami_pass'] ?? '';

    echo "[" . date('H:i:s') . "] Polling node $node on $ami_host:$ami_port...\n";

    $sock = ami_connect($ami_host, $ami_port, $ami_user, $ami_pass);
    if (!$sock) {
        echo "[" . date('H:i:s') . "] WARN: Could not connect to AMI for node $node\n";
        $snapshot['nodes'][$node] = ['ok' => false, 'error' => 'AMI connection failed'];
        continue;
    }

    // XStat — connections + keyed vars
    $xstat_raw  = ami_rptstatus($sock, 'XStat',   $node);

    // If node not in Asterisk, skip it
    if (strpos($xstat_raw, 'Response: Error') !== false) {
        fclose($sock);
        echo "[" . date('H:i:s') . "] Node $node: not in Asterisk, skipping\n";
        $snapshot['nodes'][$node] = ['ok' => false, 'error' => 'Node not in Asterisk'];
        continue;
    }

    // SawStat — PTT state
    $saw_raw    = ami_rptstatus($sock, 'SawStat',  $node);
    fclose($sock);

    $xstat  = parse_xstat($xstat_raw, $lsnodes, $node);
    $saw    = parse_sawstat($saw_raw);

    // Keyed = txkeyed OR rxkeyed OR sawstat PTT
    $is_keyed = $xstat['txkeyed'] || $xstat['rxkeyed'] || $saw['keyed'];

    // Resolve callsign from lsnodes
    $callsign = $lsnodes[$node]['callsign'] ?? '';

    $node_data = [
        'ok'          => true,
        'node'        => $node,
        'callsign'    => $callsign,
        'keyed'       => $is_keyed,
        'txkeyed'     => $xstat['txkeyed'],
        'rxkeyed'     => $xstat['rxkeyed'],
        'conn_count'  => count($xstat['connections']),
        'connections' => $xstat['connections'],
        'last_keyed_node' => $saw['last_keyed_node'],
    ];

    $snapshot['nodes'][$node] = $node_data;
    echo "[" . date('H:i:s') . "] Node $node: " . count($xstat['connections']) . " connected, " .
         "tx=" . ($xstat['txkeyed']?'YES':'no') . " rx=" . ($xstat['rxkeyed']?'YES':'no') . "\n";
}

// ── WRITE NODE CACHE ──────────────────────────────────────────
// ttn-status.php reads this instead of hitting AMI live on page load
$cache_file = '/tmp/ttn_node_cache.json';
@file_put_contents($cache_file, json_encode($snapshot));

// ── LOG LOCALLY ───────────────────────────────────────────────
$log_entry = date('Y-m-d H:i:s') . " | " . count($snapshot['nodes']) . " nodes | " .
    implode(', ', array_map(fn($n,$d) => "$n:" . ($d['conn_count']??0) . "conn",
        array_keys($snapshot['nodes']), $snapshot['nodes'])) . "\n";

@file_put_contents($log_local, $log_entry, FILE_APPEND);

// ── POST TO PORTAL ────────────────────────────────────────────
if ($portal_url && $portal_secret) {
    $payload = json_encode($snapshot);
    $ctx     = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nX-TTN-Secret: $portal_secret\r\n",
            'content' => $payload,
            'timeout' => 10,
        ]
    ]);
    $result = @file_get_contents($portal_url, false, $ctx);
    if ($result !== false) {
        echo "[" . date('H:i:s') . "] Portal POST: $result\n";
    } else {
        echo "[" . date('H:i:s') . "] WARN: Could not POST to portal\n";
    }
} else {
    echo "[" . date('H:i:s') . "] Skipping portal POST — no secret configured\n";
}

echo "[" . date('H:i:s') . "] Done.\n";
