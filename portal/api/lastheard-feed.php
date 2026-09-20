<?php
/**
 * TTN Last-Heard Feed -- public JSON API
 * LOCATION (proposed): portal/api/lastheard-feed.php
 *
 * v3: query logic moved into includes/lastheard-query.php, shared with
 * lastheard.php's server-side initial render, so the two can't drift
 * into separately-maintained copies again (that's what happened
 * between the v1 and v2 rounds this session).
 *
 * ACCESS RULE (technical-learnings.md): last-heard is fully public,
 * never gated itself. Gated: DF/tracking (not in this feed at all),
 * admin tools (not this endpoint), history past a 3-day public window.
 *
 * NOTE ON SHAPE: `events` is a single time-ordered array (most-recent
 * first), not the mode-grouped shape TTN_Bubble_Chart_Design_2026-08-02.md
 * specifies -- that doc's shape is for the separate, not-yet-built
 * bubble-chart component, not this feed. Flagging the conflict here
 * rather than silently picking one; worth a line in that doc or the
 * TodoList confirming this is deliberate.
 *
 * v4: bootstrap fixed per Bobby's live grep-confirmation -- ttn_config.php
 * is at /etc/ttn_config.php (absolute, not a relative includes/ path),
 * and db_row()/db_rows() live in TTN_INCLUDES/db.php, required right
 * after ttn_config.php on both index.php and diag.php -- that require
 * was missing before, so every db_row() call here was an undefined-
 * function fatal, not the graceful degradation the try/catch blocks in
 * lastheard-query.php were meant to provide.
 *
 * v5: conn_log and sys_telemetry column names corrected against live
 * DESCRIBE output (in lastheard-query.php) -- conn_log now also
 * selects `location` for AllStar rows (part of the original design,
 * missing before); sys_telemetry's real columns are is_online/
 * last_keyed_at/recorded_at, not the is_keyed/status/updated_at
 * guessed earlier.
 *
 * v6: same enrich-*.php wiring as lastheard.php -- see that file's
 * header for the full explanation. Kept identical here (same
 * best-effort, file-exists-gated requires) so the feed and the page's
 * initial server render never disagree about which enrichment sources
 * are active, same reasoning that put the query logic in a shared file
 * in the first place.
 */

require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php'; // defines db_row()/db_rows() -- confirmed via index.php/diag.php

// Best-effort enrichment sources -- deliberately NOT require_once,
// since a missing file must not fatal this endpoint.
foreach (['enrich-allstarlink.php', 'enrich-radioid.php', 'enrich-qrz.php'] as $ttn_enrich_file) {
    $ttn_enrich_path = __DIR__ . '/../includes/' . $ttn_enrich_file;
    if (file_exists($ttn_enrich_path)) {
        require_once $ttn_enrich_path;
    }
}
unset($ttn_enrich_file, $ttn_enrich_path);

require_once __DIR__ . '/../includes/lastheard-query.php';

header('Content-Type: application/json');

$days = isset($_GET['days']) ? max(1, (int)$_GET['days']) : 3;

// ASSUMPTION: session/login check -- swap for auth.php's actual helper.
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$is_logged_in = !empty($_SESSION['operator_id']);
if ($days > 3 && !$is_logged_in) {
    $days = 3;
}

echo json_encode([
    'window_days'     => $days,
    'generated_at'    => date('c'),
    'events'          => ttn_lastheard_events($days),
    'currently_keyed' => ttn_lastheard_currently_keyed(),
], JSON_PRETTY_PRINT);
