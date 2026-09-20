<?php
/**
 * TTN Last-Heard -- shared query logic
 * LOCATION (proposed): portal/includes/lastheard-query.php
 *
 * Single source of truth for both api/lastheard-feed.php and
 * lastheard.php's initial server-side render, so the two can no longer
 * drift into two separately-maintained copies of the same queries
 * (which is exactly what happened between rounds this session).
 *
 * Assumes the caller has already required BOTH /etc/ttn_config.php
 * (defines TTN_HUB_SYSTEM_ID, TTN_INCLUDES) AND TTN_INCLUDES.'/db.php'
 * (defines db_row()/db_rows()) -- confirmed as two separate requires
 * via index.php/diag.php. Without db.php, every call below is an
 * undefined-function fatal, not a caught exception.
 *
 * ENRICHMENT WIRING (added this round -- previously the five
 * enrich-*.php modules existed as standalone functions with no call
 * site anywhere in this file, so nothing they returned ever reached a
 * real last-heard row despite being fully built and tested). See
 * ttn_lastheard_enrich_events() below for what's wired, what's
 * deliberately not, and why. The caller optionally requires whichever
 * of includes/enrich-allstarlink.php, includes/enrich-radioid.php,
 * includes/enrich-qrz.php it has deployed -- every enrichment call
 * here is guarded by function_exists(), so this file works whether
 * zero, some, or all three are present, and each one activates
 * automatically the moment its file lands alongside this one.
 */

function ttn_lastheard_events(int $days): array {
    $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    $events = [];

    // conn_log columns confirmed live: id, system_id, connected_node,
    // callsign, location, direction, connected_at, disconnected_at.
    // `location` is the node/site's registered location (this is a
    // fixed AllStar repeater node, not a mobile transmitter), same
    // public-facing granularity as the site markers already on
    // /network/ -- not the DF/triangulation data the standing content
    // rule keeps gated. Flagging that reasoning here rather than
    // asserting it silently, since the rule is absolute and worth a
    // second look on your end given it's a live-data column, not a doc.
    try {
        $rows = db_rows("
            SELECT connected_node AS node, callsign, location, connected_at, disconnected_at
            FROM conn_log
            WHERE system_id = ? AND connected_at >= ?
            ORDER BY connected_at DESC
        ", [TTN_HUB_SYSTEM_ID, $since]);
        foreach ($rows as $r) {
            $events[] = [
                'mode' => 'AllStar', 'callsign' => $r['callsign'], 'detail' => $r['node'],
                'location' => $r['location'] ?: null,
                'connected_at' => $r['connected_at'], 'disconnected_at' => $r['disconnected_at'],
                'src_id' => null, // AllStar has no separate radio-ID concept -- node IS the identity
            ];
        }
    } catch (\Throwable $e) {
        error_log('[lastheard] AllStar query failed (check conn_log column names): ' . $e->getMessage());
    }

    // Same connection as db_row()/db_rows() already has open, fully
    // qualified against ttn_topology -- no second connection/credentials
    // file. Requires the SELECT grant on ttn_topology Bobby put on this
    // connection's DB user.
    foreach (['DMR' => 'ttn_topology.dmr_conn_log', 'P25' => 'ttn_topology.p25_conn_log'] as $mode => $table) {
        try {
            $rows = db_rows("
                SELECT src_id, callsign, talkgroup, connected_at, disconnected_at
                FROM {$table}
                WHERE system_id = ? AND connected_at >= ?
                ORDER BY connected_at DESC
            ", [TTN_HUB_SYSTEM_ID, $since]);
            foreach ($rows as $r) {
                $events[] = [
                    'mode' => $mode, 'callsign' => $r['callsign'], 'detail' => $r['talkgroup'],
                    'connected_at' => $r['connected_at'], 'disconnected_at' => $r['disconnected_at'],
                    // src_id was already in the SELECT above but was never
                    // carried into the event array before this round -- a
                    // real gap, not a stylistic gap: radioid.net enrichment
                    // below needs this and had nothing to key off of.
                    'src_id' => $r['src_id'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            error_log("[lastheard] {$mode} query failed (check ttn_topology grant on this connection's DB user): " . $e->getMessage());
        }
    }

    ttn_lastheard_enrich_events($events);

    usort($events, fn($a, $b) => strcmp($b['connected_at'], $a['connected_at']));
    return $events;
}

/**
 * Per-row enrichment -- the actual call sites that were missing
 * entirely before this round. Modifies $events in place, adding an
 * `enrich` sub-array to each event (namespaced by source, since
 * radioid.net and QRZ can both return a `name` for the same callsign
 * and collapsing them into one field would hide which source said
 * what) and filling `callsign` only when conn_log/dmr_conn_log/
 * p25_conn_log's own value was empty -- locally-logged data always
 * wins over anything fetched here.
 *
 * Deliberately NOT wired here, with reasons (see TTN_TodoList for the
 * fuller writeup):
 * - EchoLink (ttn_enrich_echolink_callsign): blocked on Bobby's still-
 *   open conn_log investigation -- which connected_node/callsign
 *   values in AllStar-mode rows are actually EchoLink vs. genuine RF
 *   nodes isn't settled yet, so there's no principled way to pick
 *   which rows to call it against without guessing.
 * - DVRef (ttn_enrich_dvref_p25_reflector): answers "which network does
 *   this designator route to," not "who transmitted this" -- it's
 *   page-level context (TTN's P25 gateway routes to reflector 276),
 *   not a per-row field. Worth adding as a one-time banner on this
 *   page, but that's a different, smaller addition from row
 *   enrichment and wasn't asked for here.
 * - radioid.net is explicitly DMR-only, never applied to P25 rows even
 *   though p25_conn_log also has a src_id column -- DMR and P25 radio
 *   IDs are different numeric namespaces, and a numeric-only match
 *   against the wrong database would attach a confident-looking but
 *   possibly wrong identity, which is worse than showing nothing.
 */
function ttn_lastheard_enrich_events(array &$events): void {
    foreach ($events as &$e) {
        $e['enrich'] = [];

        if ($e['mode'] === 'AllStar' && empty($e['callsign']) && function_exists('ttn_enrich_allstarlink_node')) {
            // Fallback only, per enrich-allstarlink.php's own scope note
            // -- only reached at all because conn_log's own callsign was
            // empty for this row.
            try {
                $a = ttn_enrich_allstarlink_node((string)$e['detail']);
                if ($a) {
                    if (!empty($a['callsign'])) {
                        $e['callsign'] = $a['callsign'];
                    }
                    $e['enrich']['allstarlink'] = [
                        'freq'  => $a['freq'] ?? null,
                        'ctcss' => $a['ctcss'] ?? null,
                    ];
                }
            } catch (\Throwable $ex) {
                error_log('[lastheard] allstarlink enrichment failed: ' . $ex->getMessage());
            }
        }

        if ($e['mode'] === 'DMR' && !empty($e['src_id']) && function_exists('ttn_enrich_radioid_user')) {
            try {
                $ri = ttn_enrich_radioid_user((string)$e['src_id']);
                if ($ri) {
                    if (empty($e['callsign']) && !empty($ri['callsign'])) {
                        $e['callsign'] = $ri['callsign'];
                    }
                    $e['enrich']['radioid'] = [
                        'name'  => $ri['name'] ?: null,
                        'city'  => $ri['city'] ?: null,
                        'state' => $ri['state'] ?: null,
                    ];
                }
            } catch (\Throwable $ex) {
                error_log('[lastheard] radioid enrichment failed: ' . $ex->getMessage());
            }
        }

        // Generic, mode-independent -- runs against whatever callsign is
        // known at this point (from the DB directly, or just filled in
        // above by AllStarLink/radioid.net). No-ops until Bobby drops
        // real credentials into /etc/ttn/qrz_credentials.ini -- safe to
        // leave wired in now, it just won't add anything until then.
        if (!empty($e['callsign']) && function_exists('ttn_qrz_lookup')) {
            try {
                $q = ttn_qrz_lookup($e['callsign']);
                if ($q) {
                    $e['enrich']['qrz'] = [
                        'name'     => $q['name'] ?: null,
                        'grid'     => $q['grid'] ?: null,
                        'location' => $q['location'] ?: null,
                    ];
                }
            } catch (\Throwable $ex) {
                error_log('[lastheard] qrz enrichment failed: ' . $ex->getMessage());
            }
        }
    }
    unset($e);
}

function ttn_lastheard_currently_keyed(): array {
    $keyed = ['AllStar' => null, 'DMR' => [], 'P25' => []];

    // sys_telemetry columns confirmed live: id, system_id, recorded_at,
    // is_online, last_keyed_at, connected_nodes, recording_url. No
    // is_keyed/status/updated_at -- those were wrong guesses in v1-v3.
    // 'keyed' uses is_online as the base signal; last_keyed_at is
    // exposed separately so the page can show "last keyed at <time>"
    // even when currently offline, rather than collapsing the two into
    // one boolean.
    try {
        $t = db_row("SELECT * FROM sys_telemetry WHERE system_id = ? ORDER BY recorded_at DESC LIMIT 1", [TTN_HUB_SYSTEM_ID]);
        if ($t) {
            $keyed['AllStar'] = [
                'keyed'         => !empty($t['is_online']),
                'last_keyed_at' => $t['last_keyed_at'] ?? null,
                'last_checked'  => $t['recorded_at'] ?? null,
            ];
        }
    } catch (\Throwable $e) {
        error_log('[lastheard] sys_telemetry query failed (check column names): ' . $e->getMessage());
    }

    // disconnected_at IS NULL is a "no end event seen yet" proxy, not a
    // real live signal -- DMR/P25 have no live query interface, same
    // reason the logger tails a log file instead of polling. `stale`
    // flags "open longer than one ~5min cron cycle" so the UI can hint
    // "probably still keyed" vs. "probably a missed end event."
    foreach (['DMR' => 'ttn_topology.dmr_conn_log', 'P25' => 'ttn_topology.p25_conn_log'] as $mode => $table) {
        try {
            $open = db_rows("
                SELECT callsign, talkgroup, connected_at FROM {$table}
                WHERE system_id = ? AND disconnected_at IS NULL
                ORDER BY connected_at DESC
            ", [TTN_HUB_SYSTEM_ID]);
            foreach ($open as $o) {
                $keyed[$mode][] = [
                    'callsign'  => $o['callsign'],
                    'talkgroup' => $o['talkgroup'],
                    'since'     => $o['connected_at'],
                    'stale'     => (time() - strtotime($o['connected_at'])) > 300,
                ];
            }
        } catch (\Throwable $e) {
            error_log("[lastheard] {$mode} open-row query failed: " . $e->getMessage());
        }
    }

    return $keyed;
}
