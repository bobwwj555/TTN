<?php
/**
 * TTN Telemetry Receive API
 * LOCATION: /home/obdswlpx/dev.ttn.radio/api/telemetry-receive.php
 *
 * Receives POST from ttn-logger.php on node servers
 * Validates shared secret
 * Writes telemetry snapshots to sys_telemetry table
 * Logs connection events for history
 */

require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// IP whitelist — pulled from asl_servers, no hardcoding
// New sites get access automatically when added to Admin → Network → ASL Servers
$allowed_ips = array_column(
    db_rows("SELECT ip_address FROM asl_servers WHERE ip_address IS NOT NULL AND ip_address != '' AND is_active = 1"),
    'ip_address'
);
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (empty($allowed_ips) || !in_array($remote, $allowed_ips, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// Validate secret — from header or body
$secret_header = $_SERVER['HTTP_X_TTN_SECRET'] ?? '';
$raw           = file_get_contents('php://input');
$data          = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$secret_body = $data['secret'] ?? '';
$secret      = $secret_header ?: $secret_body;

// Validate against site setting
$expected = s('telemetry_secret', '');
if (!$expected || !hash_equals($expected, $secret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid secret']);
    exit;
}

$nodes    = $data['nodes']    ?? [];
$ts       = $data['timestamp'] ?? time();
$hostname = $data['hostname']  ?? '';
$saved    = 0;
$errors   = [];

// Update asl_servers last_seen immediately — before processing nodes
$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($hostname) {
    db_execute("UPDATE asl_servers SET last_seen=NOW() WHERE hostname=?", [$hostname]);
}
if ($remote_ip) {
    db_execute("UPDATE asl_servers SET last_seen=NOW() WHERE ip_address=?", [$remote_ip]);
}

foreach ($nodes as $node_num => $node_data) {
    if (empty($node_data['ok'])) continue;

    // Find ASL record — system_id may be null for private/unlinked nodes
    $sys_asl = db_row("SELECT * FROM sys_asl WHERE asl_number = ?", [$node_num]);
    if (!$sys_asl) {
        $errors[] = "Node $node_num not found in sys_asl";
        continue;
    }
    $system_id = $sys_asl['system_id'];

    // Only write sys_telemetry if we have a system_id
    if ($system_id) {
        db_insert('sys_telemetry', [
            'system_id'       => $system_id,
            'recorded_at'     => date('Y-m-d H:i:s', $ts),
            'is_online'       => 1,
            'connected_nodes' => (int)($node_data['conn_count'] ?? 0),
        ]);
    }

    // Write connection events to conn_log
    // conn_log requires system_id — skip private unlinked nodes
    if (!$system_id) {
        $saved++;
        continue;
    }

    try {
        $connections = $node_data['connections'] ?? [];

        // Get current known connections for this node
        $existing = db_rows(
            "SELECT connected_node FROM conn_log WHERE system_id=? AND disconnected_at IS NULL",
            [$system_id]
        );
        $existing_nodes = array_column($existing, 'connected_node');
        $current_nodes  = array_column($connections, 'node');

        // New connections — resolve callsign from TTN registry first
        foreach ($connections as $conn) {
            if (!in_array($conn['node'], $existing_nodes)) {
                // Check TTN node registry first for callsign/location
                $ttn_node = db_row("SELECT callsign, location_note FROM sys_asl WHERE asl_number=?", [$conn['node']]);
                $callsign = $ttn_node['callsign'] ?? $conn['callsign'] ?? '';
                $location = $ttn_node['location_note'] ?? $conn['location'] ?? '';
                db_insert('conn_log', [
                    'system_id'      => $system_id,
                    'connected_node' => $conn['node'],
                    'callsign'       => $callsign,
                    'location'       => $location,
                    'direction'      => $conn['direction'] ?? '',
                    'connected_at'   => date('Y-m-d H:i:s', $ts),
                ]);
            }
        }

        // Disconnected nodes
        foreach ($existing_nodes as $en) {
            if (!in_array($en, $current_nodes)) {
                db_execute(
                    "UPDATE conn_log SET disconnected_at=? WHERE system_id=? AND connected_node=? AND disconnected_at IS NULL",
                    [date('Y-m-d H:i:s', $ts), $system_id, $en]
                );
            }
        }

        // Keyed events
        if (!empty($node_data['keyed'])) {
            db_execute(
                "UPDATE sys_telemetry SET last_keyed_at=NOW() WHERE system_id=? ORDER BY id DESC LIMIT 1",
                [$system_id]
            );
        }

    } catch (Exception $e) {
        // conn_log table may not exist yet — non-fatal
        $errors[] = "conn_log: " . $e->getMessage();
    }

    $saved++;
}

echo json_encode([
    'ok'     => true,
    'saved'  => $saved,
    'errors' => $errors,
    'ts'     => date('H:i:s'),
]);
