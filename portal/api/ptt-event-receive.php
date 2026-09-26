<?php
/**
 * TTN PTT Event Receive API
 * LOCATION: portal/api/ptt-event-receive.php in the TTN repo, deployed to
 * /var/www/html/api/ptt-event-receive.php on CT713 via ttn-deploy.
 *
 * Receives individual PTT key/unkey transition events from the AllStar PTT
 * listener (node_server/ttn-ptt-listener.php) running on node servers.
 *
 * Deliberately a SEPARATE file from telemetry-receive.php, not a branch
 * inside it: that endpoint handles periodic (5-min) connection-state
 * snapshots with diff-against-last-known-state logic; this one handles
 * discrete, irregularly-timed transmission events that already know what
 * they are when they arrive. Different traffic shape, different file --
 * avoids mixing two vantage points in one code path (the exact class of
 * problem investigated at length elsewhere this session).
 *
 * Auth: same shared secret as telemetry-receive.php
 * (site_settings.telemetry_secret), same hash_equals() check.
 *
 * Requires/DB setup, secret validation and status codes below confirmed
 * verbatim against the real telemetry-receive.php on CT713 (2026-09-25) --
 * same pattern, not guessed.
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

// Validate secret — from header or body (same pattern as telemetry-receive.php)
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

// Accept either a single event or a batch under "events" -- the listener
// currently sends one at a time, but batching is free to support.
$events = isset($data['events']) && is_array($data['events']) ? $data['events'] : [$data];

$saved  = 0;
$errors = [];

foreach ($events as $ev) {
    $asl_number = trim((string)($ev['asl_number'] ?? ''));
    $direction  = $ev['direction'] ?? '';
    $event_time = $ev['event_time'] ?? null;

    if ($asl_number === '' || !in_array($direction, ['key', 'unkey'], true) || !$event_time) {
        $errors[] = 'Malformed event: ' . json_encode($ev);
        continue;
    }

    $stmt = $pdo->prepare('SELECT system_id FROM sys_asl WHERE asl_number = ? LIMIT 1');
    $stmt->execute([$asl_number]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Same non-fatal skip-and-report pattern as telemetry-receive.php's
    // handling of 1800/1801 -- an unresolved node is never a hard failure.
    if (!$row || !$row['system_id']) {
        $errors[] = "Node $asl_number not found in sys_asl";
        continue;
    }

    $ins = $pdo->prepare(
        'INSERT INTO ptt_log (system_id, asl_number, direction, event_time) VALUES (?, ?, ?, ?)'
    );
    $ins->execute([$row['system_id'], $asl_number, $direction, $event_time]);
    $saved++;
}

echo json_encode(['ok' => true, 'saved' => $saved, 'errors' => $errors]);
