#!/usr/bin/env php
<?php
/**
 * TTN PTT Listener — AllStar real-time keyup/unkey capture
 *
 * Companion to ttn-logger.php (which handles periodic connection-state
 * snapshots on a 5-min cron). This is NOT a cron job -- it's a persistent
 * process, run under systemd with Restart=always, that stays connected to
 * this node's AMI and watches for RPT_ALINKS events in real time, POSTing
 * each individual key/unkey transition to the portal's dedicated
 * ptt-event-receive.php endpoint (kept separate from telemetry-receive.php
 * on purpose -- see that file's own header comment for why).
 *
 * Confirmed live against VM700/node 65392's real AMI stream, 2026-09-25:
 * RPT_ALINKS's EventValue carries a per-connected-node key-state string,
 * e.g. "8,40245TK,625410TU,...": count, then <node><mode-char><K|U> per
 * connected node. Diffing consecutive values against the last-seen state
 * identifies exactly which node transitioned and to which state.
 *
 * Filters to this node's own AMI events only. The same physical keyup also
 * fires mirrored RPT_ALINKS/RPT_TXKEYED events under other local node
 * contexts on the same box (e.g. DVSwitch DMR/P25 bridge legs, Echolink) --
 * confirmed in the same live capture -- which would double-log the same
 * transmission if not filtered by Node.
 *
 * Reads the same /etc/ttn-logger.conf and AMI ini as ttn-logger.php --
 * no separate config file to maintain.
 *
 * Usage: ttn-ptt-listener.php <node_number>   (e.g. 65392)
 */

$WATCH_NODE = $argv[1] ?? null;
if (!$WATCH_NODE) {
    fwrite(STDERR, "Usage: ttn-ptt-listener.php <node_number>\n");
    exit(1);
}

function log_line($msg) {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] $msg\n");
}

$conf_file = '/etc/ttn-logger.conf';
$conf = file_exists($conf_file) ? parse_ini_file($conf_file) : [];
$portal_url = rtrim($conf['portal_url'] ?? '', '/');
if ($portal_url === '') {
    fwrite(STDERR, "No portal_url in $conf_file\n");
    exit(1);
}
// portal_url in ttn-logger.conf points at telemetry-receive.php -- reuse the
// same host/path/secret rather than requiring a second config key, just
// swap the filename for the new endpoint.
$ptt_url = preg_replace('/telemetry-receive\.php$/', 'ptt-event-receive.php', $portal_url);
$secret = $conf['portal_secret'] ?? '';
if ($ptt_url === $portal_url) {
    fwrite(STDERR, "Warning: portal_url did not match expected telemetry-receive.php pattern; ptt_url may be wrong: $ptt_url\n");
}

$ini_candidates = [
    '/etc/allmon3/allmon3.ini', '/usr/local/etc/allmon3/allmon3.ini',
    '/var/www/html/allmon3.ini', '/etc/asterisk/allmon.ini',
    '/var/www/html/allmon.ini', '/usr/local/etc/allmon.ini',
];
$ini = null;
foreach ($ini_candidates as $p) {
    if (is_readable($p)) { $ini = parse_ini_file($p, true); break; }
}
if (!$ini || !isset($ini[$WATCH_NODE])) {
    fwrite(STDERR, "No AMI credentials found for node $WATCH_NODE\n");
    exit(1);
}
$sec = $ini[$WATCH_NODE];
$host = $sec['host'] ?? $sec['hostname'] ?? '127.0.0.1';
$port = $sec['port'] ?? 5038;
$user = $sec['user'] ?? $sec['username'] ?? '';
$pass = $sec['pass'] ?? $sec['passwd'] ?? $sec['secret'] ?? $sec['password'] ?? '';
if (!$user || !$pass) {
    fwrite(STDERR, "Missing AMI user/pass for node $WATCH_NODE. Keys found: " . implode(',', array_keys($sec)) . "\n");
    exit(1);
}

function connect_ami($host, $port, $user, $pass) {
    $sock = @fsockopen($host, $port, $errno, $errstr, 5);
    if (!$sock) {
        fwrite(STDERR, "AMI connect failed: $errstr\n");
        return null;
    }
    stream_set_timeout($sock, 300); // 5 min idle timeout -- bounds a half-dead
                                     // connection instead of blocking forever
    fgets($sock); // banner
    fwrite($sock, "Action: Login\r\nUsername: $user\r\nSecret: $pass\r\n\r\n");
    usleep(300000);
    $resp = fread($sock, 8192);
    if (strpos($resp, 'Success') === false) {
        fwrite(STDERR, "AMI login did not report success: $resp\n");
        fclose($sock);
        return null;
    }
    return $sock;
}

function post_event($url, $secret, $asl_number, $direction, $event_time) {
    $payload = json_encode([
        'secret'     => $secret,
        'asl_number' => $asl_number,
        'direction'  => $direction,
        'event_time' => $event_time,
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nX-TTN-Secret: $secret\r\n",
        'content' => $payload,
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    $result = @file_get_contents($url, false, $ctx);
    if ($result === false) {
        log_line("POST FAILED for node $asl_number $direction (network/connect error)");
    } else {
        log_line("$asl_number $direction -> $result");
    }
}

/**
 * "8,40245TK,625410TU,..." -> ['40245' => 'K', '625410' => 'U', ...]
 * Drops the leading count. Each entry is <node digits><one-or-more mode
 * chars><K|U> -- confirmed format from the live 2026-09-25 capture used
 * "T" as the sole mode char (transceive), but the pattern below tolerates
 * any non-digit run before the final K/U rather than hardcoding "T".
 */
function parse_alinks($value) {
    $parts = explode(',', $value);
    array_shift($parts);
    $states = [];
    foreach ($parts as $p) {
        if (preg_match('/^(\d+)[A-Z]*([KU])$/', trim($p), $m)) {
            $states[$m[1]] = $m[2];
        }
    }
    return $states;
}

$sock = connect_ami($host, $port, $user, $pass);
if (!$sock) exit(1);

log_line("Connected, watching node $WATCH_NODE for PTT transitions");

// null = not yet seeded. The first RPT_ALINKS message after (re)connect only
// seeds the baseline state and emits NO transition events -- otherwise every
// (re)start would falsely report every currently-keyed node as a brand-new
// keyup, the same cold-start false-positive pattern already found and fixed
// in conn_log earlier this session (see TTN_Session_Update_2026-09-25, §3).
$last_state = null;
$buffer = '';

while (true) {
    $line = fgets($sock, 4096);
    if ($line === false) {
        $meta = stream_get_meta_data($sock);
        $reason = !empty($meta['timed_out']) ? 'idle timeout' : 'connection closed';
        fwrite(STDERR, "AMI $reason -- exiting for systemd restart\n");
        exit(1);
    }
    $buffer .= $line;

    if (trim($line) === '') {
        // blank line = end of one AMI packet
        if (strpos($buffer, "Event: RPT_ALINKS\r\n") !== false
            && strpos($buffer, "Node: $WATCH_NODE\r\n") !== false
            && preg_match('/EventValue: ([^\r\n]*)/', $buffer, $m)) {

            $new_state = parse_alinks($m[1]);

            if ($last_state !== null) {
                foreach ($new_state as $node => $state) {
                    $prev = $last_state[$node] ?? 'U';
                    if ($prev !== $state) {
                        post_event($ptt_url, $secret, $node,
                            $state === 'K' ? 'key' : 'unkey',
                            date('Y-m-d H:i:s'));
                    }
                }
                // Also catch nodes that disappeared from the link list entirely
                // while still marked keyed -- treat as an implicit unkey.
                foreach ($last_state as $node => $state) {
                    if ($state === 'K' && !isset($new_state[$node])) {
                        post_event($ptt_url, $secret, $node, 'unkey', date('Y-m-d H:i:s'));
                    }
                }
            } else {
                log_line('Seeded initial state (' . count($new_state) . ' nodes), watching for transitions from here');
            }
            $last_state = $new_state;
        }
        $buffer = '';
    }
}
