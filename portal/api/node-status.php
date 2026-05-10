<?php
/**
 * TTN Node Status API — DB-backed
 * LOCATION: /home/obdswlpx/dev.ttn.radio/api/node-status.php
 *
 * Returns cached node status from sys_telemetry and conn_log.
 * Data is written by ttn-logger.php cron (every 2 min) on each node server.
 * No live AMI connection — always fast.
 *
 * Usage: /api/node-status.php?node=65392
 */

require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$node = preg_replace('/[^0-9]/', '', $_GET['node'] ?? '');

if (!$node) {
    echo json_encode(['ok' => false, 'error' => 'No node specified']);
    exit;
}

// Find system for this ASL node
$sys = db_row("
    SELECT s.id, s.callsign, s.site_id
    FROM systems s
    JOIN sys_asl sa ON sa.system_id = s.id
    WHERE sa.asl_number = ?
    LIMIT 1
", [$node]);

if (!$sys) {
    echo json_encode(['ok' => false, 'error' => "Node $node not found in DB"]);
    exit;
}

// Latest telemetry
$telem = null;
try {
    $telem = db_row("
        SELECT is_online, last_keyed_at, connected_nodes, recorded_at
        FROM sys_telemetry
        WHERE system_id = ?
        ORDER BY recorded_at DESC, connected_nodes DESC
        LIMIT 1
    ", [$sys['id']]);
} catch (Exception $e) {}

// Recent connections (currently active = no disconnected_at)
$active = [];
try {
	$active = db_rows("
	    SELECT c.connected_node AS node,
	           COALESCE(NULLIF(c.callsign,''), sa.callsign, '') AS callsign,
	           COALESCE(NULLIF(c.location,''), '') AS location,
	           c.direction
	    FROM conn_log c
	    LEFT JOIN sys_asl sa ON sa.asl_number = c.connected_node
	    WHERE c.system_id = ? AND c.disconnected_at IS NULL
	    ORDER BY c.connected_at DESC
	    LIMIT 20
	", [$sys['id']]);
} catch (Exception $e) {}

// Last heard — most recent conn_log entry if telemetry has nothing
$last_keyed = $telem['last_keyed_at'] ?? null;
if (!$last_keyed) {
    try {
        $lh = db_row("SELECT connected_at FROM conn_log WHERE system_id = ? ORDER BY connected_at DESC LIMIT 1", [$sys['id']]);
        if ($lh) $last_keyed = $lh['connected_at'];
    } catch (Exception $e) {}
}

$is_online = (bool)($telem['is_online'] ?? false);
$data_age  = $telem ? (time() - strtotime($telem['recorded_at'])) : null;

echo json_encode([
    'ok'            => true,
    'node'          => $node,
    'callsign'      => $sys['callsign'],
    'is_online'     => $is_online,
    'conn_count'    => (int)($telem['connected_nodes'] ?? 0),
    'connections'   => $active,
    'last_keyed_at' => $last_keyed,
    'data_age_sec'  => $data_age,
    'ts'            => date('H:i:s'),
    'source'        => 'db_cache',
]);
