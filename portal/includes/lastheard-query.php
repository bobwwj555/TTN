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
 *
 * v7 (2026-09-26): AllStar events switched from conn_log (connect/
 * disconnect records tailed off connectlog) to ptt_log (real AMI
 * key/unkey transitions captured live by node_server/ttn-ptt-listener.php
 * + api/ptt-event-receive.php, built and verified against a real
 * transmission earlier the same session -- see
 * claude/TTN_Session_Update_2026-09-25_1940.md). Replaced, not unioned,
 * per Bobby's explicit direction. Full reasoning is inline in
 * ttn_lastheard_events() below, next to the new query.
 */

function ttn_lastheard_events(int $days): array {
    $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    $events = [];

    // v7: sourced from ptt_log, not conn_log -- see file header. Three
    // things that don't carry over unchanged from the conn_log version,
    // flagged rather than silently handled:
    //
    // 1. system_id semantics differ between the two tables and must NOT
    //    be filtered the same way. conn_log.system_id is hardcoded to
    //    the HUB's own system_id on every row (ttn-logger-allstar.php's
    //    $hub_system_id = 8, confirmed against its live source), so
    //    "WHERE system_id = TTN_HUB_SYSTEM_ID" there really just meant
    //    "give me AllStar connect events." ptt_log.system_id is the
    //    opposite: it's the KEYING node's own resolved system_id
    //    (confirmed empirically -- node 40245 writes system_id=10, node
    //    450331 writes system_id=13, neither is 8). Filtering ptt_log by
    //    TTN_HUB_SYSTEM_ID would keep almost nothing. There's currently
    //    only one PTT listener deployed (watching node 65392 on VM700),
    //    so every ptt_log row is already implicitly hub-scoped by
    //    construction -- no system_id filter is applied here at all.
    //
    // 2. Only 'key' rows are pulled, not 'unkey'. ptt_log stores both
    //    halves of every transmission as separate rows; each 'key' row
    //    IS one last-heard event by itself, matching how every other
    //    mode's row here already represents a single discrete event,
    //    not a duration. disconnected_at is set to null for every row
    //    from this source -- a keyup is a point-in-time event, not a
    //    connection with an end time, and ECR's own reference UI (the
    //    concrete UX target for this page) doesn't show one either.
    //    Live "still transmitting" state is a separate question already
    //    handled by ttn_lastheard_currently_keyed(), not per-row here --
    //    and as of v7 that function still reads sys_telemetry, NOT
    //    ptt_log (confirmed by reading its real source; an earlier
    //    guess that it might already be ptt_log-aware, based on its
    //    last_checked timestamp coincidentally landing in tonight's
    //    testing window, was wrong -- worth a separate fix, not done
    //    here since it wasn't part of what was asked).
    //
    // 3. callsign/location aren't columns on ptt_log itself (it only
    //    has system_id/asl_number/direction/event_time) -- pulled via
    //    an INNER JOIN on sys_asl.asl_number, the same lookup key
    //    ptt-event-receive.php already uses to resolve system_id on
    //    write (confirmed live schema: sys_asl has callsign,
    //    location_note, visibility, is_active among its columns).
    //    Two extra join conditions are a deliberate new addition, not
    //    something conn_log's query ever needed: visibility = 'public'
    //    and is_active = 1. conn_log had no visibility concept at all
    //    (it's not joined to sys_asl), so this is the first time a
    //    private/operator-only or retired sys_asl entry could leak into
    //    the fully-public last-heard feed (this file's own header, and
    //    technical-learnings.md: last-heard is public and never gated
    //    except the 3-day window). Using INNER JOIN + WHERE here (not a
    //    LEFT JOIN with a nullable filter, which would still leak the
    //    bare event minus the callsign) keeps that rule intact.
    //    Flagging this as a judgment call made without asking first: if
    //    a node's activity should show even when ttn_private or
    //    operator_private, this filter needs loosening. Also note
    //    sys_asl.location_note is an admin-entered site note (e.g. "New
    //    Market, TN -- TNONE" for node 450331), a different source than
    //    conn_log.location's free text captured live off the
    //    connectlog line -- close enough for this feed, but the
    //    displayed text may look different than before for some nodes
    //    (e.g. node 40245/WA4ADT has no location_note set at all yet).
    try {
        $rows = db_rows("
            SELECT p.asl_number AS node, a.callsign AS callsign, a.location_note AS location,
                   p.event_time AS connected_at
            FROM ptt_log p
            INNER JOIN sys_asl a ON a.asl_number = p.asl_number
            WHERE p.direction = 'key' AND p.event_time >= ?
              AND a.visibility = 'public' AND a.is_active = 1
            ORDER BY p.event_time DESC
        ", [$since]);
        foreach ($rows as $r) {
            $events[] = [
                'mode' => 'AllStar', 'callsign' => $r['callsign'], 'detail' => $r['node'],
                'location' => $r['location'] ?: null,
                'connected_at' => $r['connected_at'], 'disconnected_at' => null,
                'src_id' => null, // AllStar has no separate radio-ID concept -- node IS the identity
            ];
        }
    } catch (\Throwable $e) {
        error_log('[lastheard] AllStar query failed (check ptt_log/sys_asl column names): ' . $e->getMessage());
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
    //
    // NOT switched to ptt_log in v7 -- out of scope for the fix asked
    // for this round (that was specifically about ttn_lastheard_events()
    // going stale, not this function), and is_online here answers a
    // different question (is the node's AMI connection up at all) than
    // ptt_log would (is someone transmitting right now). Worth a
    // follow-up: a true ptt_log-based "currently keyed" would need to
    // find, per node, the most recent row and check whether it's a
    // 'key' with no later 'unkey' -- flagging as a real next step, not
    // doing it here since it wasn't asked for.
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
