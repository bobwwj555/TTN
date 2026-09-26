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
 *
 * v8 (2026-09-26, later same day): the follow-up v7 itself flagged --
 * ttn_lastheard_currently_keyed()'s AllStar branch switched from
 * sys_telemetry (is_online -- "is the AMI connection up") to ptt_log
 * (is someone actually transmitting right now). AllStar's shape in the
 * return array changed from a single object-or-null to a list, matching
 * DMR/P25's existing shape -- see that function's own comment for why.
 * lastheard.php's renderKeyed() is updated in the same patch to match;
 * a mismatched shape there would silently break (or blank) the AllStar
 * box.
 *
 * v9 (2026-09-26, later same day): ttn_lastheard_currently_keyed()'s
 * `stale` flag now excludes a row instead of just labeling it -- a
 * missed end event (a 'key'/open connection with no matching
 * 'unkey'/disconnect for >5min) isn't a real live keyup, confirmed live
 * against node 40245 sitting "currently keyed" for 20+ minutes off one
 * missed unkey. See that function's own comment for the incident.
 *
 * v10 (2026-09-26, later same day): ttn_lastheard_events()'s DMR/P25
 * rows now carry a `relayed_from` field naming the AllStar node that
 * triggered them, when one keyed shortly before -- confirmed against a
 * full day's real data that these are a genuine event-triggered relay
 * through the DVSwitch bridge, not an independent heartbeat. See that
 * function's own comment for the evidence. lastheard.php's renderFeed()
 * is updated in the same patch to display it.
 *
 * v11 (2026-09-26, later same day): ttn_lastheard_events() now dedupes
 * exact-duplicate rows (same mode, same node/talkgroup, same displayed
 * second) -- confirmed live that node 40245 wrote several such AllStar
 * duplicates, one of which cascaded into a duplicate P25 relay row too.
 * Display-layer fix only; the write-side cause is separate and needs
 * its own fix on a different host/deploy path. See that function's own
 * comment for the confirmed evidence.
 *
 * v12 (2026-09-26, later same day): a registered sys_asl callsign
 * ending in "-L" (EchoLink's own link/proxy-node convention -- see
 * ttn_is_link_node_callsign() below) is never shown as that node's
 * live occupant, in either ttn_lastheard_currently_keyed()'s AllStar
 * box or ttn_lastheard_events()'s relay-attribution tagging. Confirmed
 * live: node 1900 ("W4BWW-L", Bobby's own EchoLink link node) alternated
 * key/unkey in lockstep with node 450331 for an entire real two-way
 * EchoLink QSO -- both the currently-keyed box and the relay tagging
 * were crediting BOTH sides of that conversation to Bobby, since a link
 * node's sys_asl row records who set it up, not who's occupying it at
 * any given moment (the same distinction already forced on DMR's fixed
 * src_id/node 1800 earlier this session). This is the interim fix Bobby
 * asked for: stop crediting the owner, don't fabricate a real remote
 * callsign. Real per-occupant attribution needs Asterisk/app_rpt's own
 * EchoLink connection tracking (AMI status or its own connect/disconnect
 * log) -- not reachable from this session yet, still open.
 */

/**
 * A sys_asl callsign ending in "-L" is a Link-type registration --
 * EchoLink's own convention for a bridge/proxy node a ham sets up,
 * distinct from their own live PTT (confirmed against echolink.org's
 * validation lookup for W4BWW: three separate registered nodes under
 * one callsign, W4BWW/base, W4BWW-L/link, W4BWW-R/repeater). A link
 * node's registered owner is who configured it, not necessarily who's
 * occupying it right now -- see this file's v12 header note for the
 * confirmed incident that made this the authoritative signal instead of
 * a guess (previously flagged in ttn_lastheard_enrich_events() below as
 * blocked on exactly this unresolved question).
 */
function ttn_is_link_node_callsign(?string $callsign): bool {
    return $callsign !== null && preg_match('/-L$/', trim($callsign)) === 1;
}

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

    // v11 (2026-09-26, later same day): confirmed live -- node 40245
    // wrote 3+ exact-duplicate AllStar 'key' rows during one busy
    // multi-minute stretch (identical asl_number AND identical
    // event_time to the second, e.g. two separate rows both at
    // 21:27:48 and two more both at 21:28:38), and one of those
    // duplicate keyups produced a duplicate P25 relay row too:
    // p25_conn_log itself had two rows both connected_at=21:29:41 -- one
    // a real 5-second transmission (disconnected_at 21:29:46), the
    // other a spurious 0-duration row (disconnected_at 21:29:41, same
    // second it began). Per Bobby: a real distinct human keyup does not
    // land on the identical second as another one -- that's a
    // write-side double-insert, not real traffic, the same tied-
    // event_time phenomenon ttn_lastheard_currently_keyed() already had
    // to dedupe for this exact node, just never applied here too.
    // Deduped by (mode, detail, connected_at) -- same node/talkgroup,
    // same displayed second -- keeping whichever copy has the later/
    // longer disconnected_at (the real transmission in the confirmed
    // P25 case above; AllStar carries no disconnected_at to prefer by,
    // so either duplicate is kept arbitrarily since they're otherwise
    // identical). This is a DISPLAY-layer fix only -- the write-side
    // cause (most likely ttn-ptt-listener.php on VM700 for AllStar, and
    // MMDVM_Bridge's own P25 log for the p25_conn_log case) lives on a
    // host/deploy path this session hasn't touched yet and needs its
    // own fix separately -- see chat.
    $dedup_seen = [];
    foreach ($events as $e) {
        $key = $e['mode'] . '|' . $e['detail'] . '|' . $e['connected_at'];
        if (!isset($dedup_seen[$key])
            || ($e['disconnected_at'] ?? '') > ($dedup_seen[$key]['disconnected_at'] ?? '')) {
            $dedup_seen[$key] = $e;
        }
    }
    $events = array_values($dedup_seen);

    // v10 (2026-09-26, later same day): DMR/P25 rows tagged with the
    // AllStar keyup that triggered them, when one exists shortly before.
    // Confirmed against a full day's real data this session (63 of 65
    // DMR/P25 pairs had an AllStar 'key' event 0-63s earlier) that these
    // are a REAL, event-triggered relay through the DVSwitch bridge --
    // NOT an independent heartbeat: a ~1h47m AllStar-quiet stretch that
    // same day had zero DMR/P25 rows at all, which a fixed-interval timer
    // would have fired through dozens of times. Delay is consistently
    // ~62-63s when the triggering AllStar event is isolated, collapsing
    // to near-zero during rapid bursts -- the signature of a real audio
    // pipeline's baseline hang time being absorbed once already primed by
    // nearby traffic, not a periodic echo. Per Bobby's own root-node
    // principle (already applied to the currently-keyed box this
    // session): a relayed leg is the SAME event as its origin, not an
    // independent origination, and the feed needs to say so instead of
    // showing three unrelated-looking rows for one real keyup.
    //
    // 90s window (comfortably above the observed ~62-63s ceiling).
    // Attributes to the MOST RECENT preceding AllStar event within that
    // window, not the first -- during a burst of several closely-spaced
    // AllStar keys before one relay pair, the relay reflects whatever was
    // most recently on the air, not the start of the burst. A DMR/P25
    // event with no AllStar event in its window gets no tag at all --
    // confirmed live that this happens for real (2 of 65 pairs today, both
    // before any AllStar activity that day), and forcing an attribution
    // there would fabricate a link that isn't there.
    // v12: a link-type node (ttn_is_link_node_callsign()) is excluded
    // from the public AllStar query above by the visibility/is_active
    // filter -- confirmed live that node 1900 ("W4BWW-L") required a
    // raw, filter-free query to find at all, meaning $events never
    // carried its key events and the tagging loop below could only ever
    // fall back to whichever OTHER AllStar node (e.g. 450331) happened
    // to be nearby in time -- silently misattributing a relay actually
    // triggered by the EchoLink leg to that node's owner instead. This
    // unconditional query exists ONLY to feed relay-attribution timing
    // below; it deliberately does not add rows to $events (a link node's
    // own key events aren't shown as their own feed row here -- out of
    // scope for tonight's fix, see chat).
    $link_node_times = [];
    try {
        $link_rows = db_rows("
            SELECT p.asl_number AS node, a.callsign AS callsign, p.event_time AS connected_at
            FROM ptt_log p
            INNER JOIN sys_asl a ON a.asl_number = p.asl_number
            WHERE p.direction = 'key' AND p.event_time >= ? AND a.callsign LIKE '%-L'
            ORDER BY p.event_time DESC
        ", [$since]);
        foreach ($link_rows as $r) {
            if (ttn_is_link_node_callsign($r['callsign'])) {
                $link_node_times[] = ['t' => strtotime($r['connected_at']), 'node' => $r['node']];
            }
        }
    } catch (\Throwable $e) {
        error_log('[lastheard] link-node relay-timing query failed: ' . $e->getMessage());
    }

    $allstar_times = [];
    foreach ($events as $e) {
        if ($e['mode'] === 'AllStar') {
            $allstar_times[] = [
                't' => strtotime($e['connected_at']),
                'node' => $e['detail'],
                // Defensive -- if a link node's row ever does pass the
                // public/active filter above and lands in $events
                // directly, it still gets the same treatment.
                'is_link_node' => ttn_is_link_node_callsign($e['callsign']),
            ];
        }
    }
    foreach ($link_node_times as $l) {
        $allstar_times[] = ['t' => $l['t'], 'node' => $l['node'], 'is_link_node' => true];
    }
    foreach ($events as &$e) {
        if ($e['mode'] !== 'DMR' && $e['mode'] !== 'P25') {
            continue;
        }
        $et = strtotime($e['connected_at']);
        $best = null;
        foreach ($allstar_times as $a) {
            if ($a['t'] <= $et && ($et - $a['t']) <= 90) {
                if ($best === null || $a['t'] > $best['t']) {
                    $best = $a;
                }
            }
        }
        if ($best === null) {
            $e['relayed_from'] = null;
            $e['relayed_via_link_node'] = null;
        } elseif ($best['is_link_node']) {
            // v12: the actual trigger was a link node (e.g. Bobby's own
            // EchoLink proxy, node 1900) -- crediting this to whichever
            // real AllStar node happens to be nearby in time would
            // misattribute the OTHER station's half of a relayed
            // conversation to that node's owner (the confirmed incident
            // this round -- see file header). Tagged separately so the
            // display can say "remote station unknown" instead of naming
            // anyone.
            $e['relayed_from'] = null;
            $e['relayed_via_link_node'] = $best['node'];
        } else {
            $e['relayed_from'] = $best['node'];
            $e['relayed_via_link_node'] = null;
        }
    }
    unset($e);

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
 * - EchoLink (ttn_enrich_echolink_callsign): the "which rows are
 *   EchoLink" question this was blocked on is now settled --
 *   ttn_is_link_node_callsign() (v12) identifies a link node by its
 *   sys_asl "-L" callsign suffix, and both the currently-keyed box and
 *   relay-attribution tagging use it. Still NOT wired here: this
 *   registry lookup answers "who is node 1900 registered to" (Bobby),
 *   never "who is occupying it right now" (the actual EchoLink caller)
 *   -- the confirmed incident this round was specifically that those
 *   two are different, so calling this function here would just
 *   re-introduce the same misattribution in enrichment form. Real
 *   per-occupant attribution needs Asterisk/app_rpt's own live EchoLink
 *   connection tracking, not this validation/registry endpoint.
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
    $keyed = ['AllStar' => [], 'DMR' => [], 'P25' => []];

    // v9 (2026-09-26, later same day): confirmed live -- node 40245 had a
    // 'key' row from ~23 minutes earlier with no matching 'unkey' ever
    // logged (a missed end event, exactly the case `stale` already exists
    // to detect), and it was rendering as "currently keyed" in the
    // AllStar box the entire time, indistinguishable from a real
    // transmission except for a small "(stale?)" label. Per Bobby's
    // ECR-parity ask -- one box per REAL keyup -- a stale entry like this
    // isn't a real keyup at all, it's known-likely-wrong data, so `stale`
    // now excludes a row from the returned list instead of just labeling
    // it in place. This was very likely the real explanation for "5 nodes
    // keyed at once" off a single real transmission: several unrelated
    // missed-end-event ghosts (any node with a never-closed 'key' row,
    // for whatever earlier reason) rendering alongside one real live
    // keyup in the same box, not an AllStar linking/relay-chain issue --
    // confirmed by ptt_log itself showing only the single real node
    // (450331) during two live test keyups tonight, with 40245's stale
    // ghost the only other entry the old code would have shown alongside
    // it. Applied to all three modes for the same reason -- DMR/P25's
    // open-row query has the identical missed-disconnect-event gap.
    // NOTE: this is a display filter only, not a data fix -- 40245's
    // stale ptt_log row (and any others like it) stays in the table and
    // will simply stop being surfaced once this deploys; no DELETE
    // needed for this to take effect, though the row itself remains if a
    // later audit of ptt_log's write-side ever wants to know it happened.
    //
    // v8: switched from sys_telemetry (is_online -- "is the node's AMI
    // connection up at all") to ptt_log (is someone actually
    // transmitting right now) -- these are genuinely different
    // questions, and is_online was never the right signal for a
    // "currently keyed" indicator, just the closest thing available
    // before ptt_log existed. "Currently keyed" here means: for a given
    // asl_number, its own most recent ptt_log row is 'key' with no later
    // 'unkey' -- found via a per-asl_number MAX(event_time) join rather
    // than a correlated subquery, same latest-row-per-group pattern as
    // any other grouped-latest query. Returned as a list, not a single
    // object -- unlike sys_telemetry (one hub-wide row), ptt_log tracks
    // each watched node individually, and more than one could show as
    // still-keyed at once (e.g. a brief AMI doubling), which a single
    // object couldn't represent honestly. Mirrors DMR/P25's existing
    // list shape and stale-after-5-minutes convention below, for the
    // same reason: a 'key' with no 'unkey' seen in a long time is more
    // likely a missed end event than someone still transmitting.
    // sys_asl LEFT JOIN (not INNER) deliberately -- an unresolved
    // asl_number should still show as keyed, just without a callsign,
    // rather than silently vanishing from the box.
    try {
        $rows = db_rows("
            SELECT p.asl_number, a.callsign, p.event_time AS since
            FROM ptt_log p
            INNER JOIN (
                SELECT asl_number, MAX(event_time) AS max_time
                FROM ptt_log
                GROUP BY asl_number
            ) latest ON latest.asl_number = p.asl_number AND latest.max_time = p.event_time
            LEFT JOIN sys_asl a ON a.asl_number = p.asl_number
            WHERE p.direction = 'key'
        ");
        $seen = [];
        foreach ($rows as $r) {
            // A tied event_time for the same asl_number (confirmed to
            // happen for real tonight -- node 40245 logged several
            // identical-second rows) would otherwise join-match more
            // than once here and list the same node twice. Keep the
            // first, skip the rest, rather than relying on GROUP BY
            // behavior that depends on this server's sql_mode.
            if (isset($seen[$r['asl_number']])) {
                continue;
            }
            $seen[$r['asl_number']] = true;
            // v9: a stale (>5min, no matching unkey) row is a missed end
            // event, not a real live keyup -- excluded entirely rather
            // than shown with just a cosmetic label. See this function's
            // v9 comment above for the incident that surfaced this.
            if ((time() - strtotime($r['since'])) > 300) {
                continue;
            }
            // v12: a link-type node (callsign ending "-L") is never shown
            // as its registered owner here -- confirmed live this was
            // exactly the incident: node 1900/"W4BWW-L" rendering
            // "currently keyed" as Bobby mid-QSO with a real EchoLink
            // caller, when the live occupant was the OTHER station. See
            // ttn_is_link_node_callsign()'s comment above.
            $is_link = ttn_is_link_node_callsign($r['callsign']);
            $keyed['AllStar'][] = [
                'asl_number'   => $r['asl_number'],
                'callsign'     => $is_link ? null : ($r['callsign'] ?: null),
                'since'        => $r['since'],
                'is_link_node' => $is_link,
                // No 'stale' key -- every remaining row is already <5min
                // old (older ones were filtered above), and lastheard.php's
                // renderKeyed() treats a missing/falsy r.stale the same as
                // an explicit false, so no client-side change is needed.
            ];
        }
    } catch (\Throwable $e) {
        error_log('[lastheard] AllStar ptt_log currently-keyed query failed (check ptt_log/sys_asl column names): ' . $e->getMessage());
    }

    // disconnected_at IS NULL is a "no end event seen yet" proxy, not a
    // real live signal -- DMR/P25 have no live query interface, same
    // reason the logger tails a log file instead of polling. v9: same
    // change as AllStar above -- a row open >5min is a missed disconnect
    // event, not a real live one, so it's excluded here rather than shown
    // with a "(stale?)" label. See ttn_lastheard_currently_keyed()'s v9
    // comment for the incident that motivated this across all three modes.
    foreach (['DMR' => 'ttn_topology.dmr_conn_log', 'P25' => 'ttn_topology.p25_conn_log'] as $mode => $table) {
        try {
            $open = db_rows("
                SELECT callsign, talkgroup, connected_at FROM {$table}
                WHERE system_id = ? AND disconnected_at IS NULL
                ORDER BY connected_at DESC
            ", [TTN_HUB_SYSTEM_ID]);
            foreach ($open as $o) {
                if ((time() - strtotime($o['connected_at'])) > 300) {
                    continue;
                }
                $keyed[$mode][] = [
                    'callsign'  => $o['callsign'],
                    'talkgroup' => $o['talkgroup'],
                    'since'     => $o['connected_at'],
                ];
            }
        } catch (\Throwable $e) {
            error_log("[lastheard] {$mode} open-row query failed: " . $e->getMessage());
        }
    }

    return $keyed;
}
