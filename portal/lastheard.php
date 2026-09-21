<?php
/**
 * TTN Last-Heard -- public page
 * LOCATION (proposed): portal/lastheard.php
 *
 * v3: initial server-side render now calls the same
 * includes/lastheard-query.php functions api/lastheard-feed.php uses,
 * instead of a second, separately-written copy of the queries -- that
 * duplication is what let the page and the feed drift apart between
 * the v1 and v2 rounds this session.
 *
 * v4: bootstrap fixed per Bobby's live grep-confirmation --
 * ttn_config.php is at the absolute path /etc/ttn_config.php, not a
 * relative includes/ path, and db_row()/db_rows() live in a separate
 * TTN_INCLUDES/db.php that index.php/diag.php require right after
 * ttn_config.php -- that require was missing entirely before now,
 * which would have been an undefined-function fatal on every db_row()
 * call, not graceful degradation. header.php/footer.php's TTN_INCLUDES
 * paths were already correct -- also confirmed live, no change needed.
 *
 * v5: conn_log and sys_telemetry column names corrected against live
 * DESCRIBE output (in lastheard-query.php) -- conn_log now also
 * selects `location` for AllStar rows (part of the original design,
 * missing before); sys_telemetry's real columns are is_online/
 * last_keyed_at/recorded_at, not the is_keyed/status/updated_at
 * guessed earlier.
 *
 * v6: enrich-*.php modules actually wired in. Before this round, five
 * fully-built enrich-*.php files existed with no call site anywhere --
 * copying them onto CT713 alone would not have changed what this page
 * shows. ttn_lastheard_enrich_events() (in lastheard-query.php) is the
 * missing glue. The requires below are best-effort: each enrich-*.php
 * file is required only if it's actually present on disk, so this page
 * works whether zero, some, or all three are deployed -- each source
 * activates automatically the moment its file lands in includes/.
 *
 * v7: callsigns are now links out to QRZ (Bobby's ask, 2026-09-20) --
 * qrzLink() below, used everywhere a raw callsign was being interpolated
 * (feed rows + the DMR/P25 currently-keyed boxes). Points at
 * qrz.com/db/<CALLSIGN> -- QRZ's page there 200s and redirects to the
 * right listing (or a not-found state) regardless of case, so no local
 * validation of the callsign shape is attempted. Not applied to the
 * enrichSummary() name/location bits -- those aren't callsigns.
 *
 * v8: feed rewritten as a real table (Bobby's concrete reference,
 * 2026-09-20: ECR Hub Monitor, ecrhub.hamcolo.com/live/ -- Node#/Via/
 * Type/Timestamp(UTC)/Description, newest row on top, Node# and Via
 * both clickable). Adapted per-mode rather than copied verbatim, since
 * TTN carries three modes (AllStar/DMR/P25) against ECR's two
 * (AllStar/IRLP) -- see idLink() in the script below for exactly what
 * each mode's ID column links to and why, including which link targets
 * are confirmed-real vs. a deliberately conservative fallback where
 * they weren't confirmable this session. "Newest on top" needed no
 * change -- ttn_lastheard_events() already returns connected_at DESC.
 * Poll interval dropped 30000ms -> 5000ms to match the near-real-time
 * backend change proposed alongside this (see the separate systemd
 * daemon notes) -- if that backend change ISN'T deployed, this just
 * means more frequent polls of the same 5-minute-stale data, worth
 * reverting if so.
 */

require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php'; // defines db_row()/db_rows() -- confirmed via index.php/diag.php

// Best-effort enrichment sources -- deliberately NOT require_once,
// since a missing file must not fatal the page. Must load before
// lastheard-query.php's ttn_lastheard_events() is actually CALLED
// below (not before lastheard-query.php is required -- PHP only needs
// these defined by call time), so function_exists() checks inside
// ttn_lastheard_enrich_events() see them.
foreach (['enrich-allstarlink.php', 'enrich-radioid.php', 'enrich-qrz.php'] as $ttn_enrich_file) {
    $ttn_enrich_path = __DIR__ . '/includes/' . $ttn_enrich_file;
    if (file_exists($ttn_enrich_path)) {
        require_once $ttn_enrich_path;
    }
}
unset($ttn_enrich_file, $ttn_enrich_path);

require_once __DIR__ . '/includes/lastheard-query.php';

$events = ttn_lastheard_events(3);
$keyed  = ttn_lastheard_currently_keyed();

$page_title = 'Last Heard';
require_once TTN_INCLUDES . '/header.php'; // confirmed via grep on the live index.php (line 91)
?>
<style>
.lh-wrap{padding:3rem 5vw;background:var(--bg);color:var(--t1, #ddd)}
.lh-keyed{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin:1.5rem 0}
.lh-keyed .box{background:var(--panel);border:1px solid var(--border2);padding:0.9rem;font-size:0.85rem}
.lh-keyed .box.on{border-color:var(--green)}
.lh-keyed .box.stale{border-color:var(--amber)}
.lh-keyed h4{margin:0 0 0.4rem;font-size:0.75rem;letter-spacing:0.1em;text-transform:uppercase;color:var(--t3)}
.lh-keyed .idle{color:var(--t3)}
.lh-feed{background:var(--panel);border:1px solid var(--border2);margin-top:1rem;overflow-x:auto}
.lh-table{width:100%;border-collapse:collapse;font-size:0.85rem}
.lh-table thead th{text-align:left;font-size:0.7rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--t3);padding:0.6rem 1rem;border-bottom:1px solid var(--border2);white-space:nowrap}
.lh-table tbody td{padding:0.5rem 1rem;border-bottom:1px solid var(--border2);vertical-align:top}
.lh-table tbody tr:last-child td{border-bottom:none}
.lh-table tbody tr:hover td{background:rgba(255,255,255,0.03)}
.lh-table .mode{color:var(--t3);font-size:0.7rem;text-transform:uppercase;white-space:nowrap}
.lh-table .idcol{white-space:nowrap;font-variant-numeric:tabular-nums}
.lh-table .via{white-space:nowrap;color:var(--t3);font-size:0.8rem}
.lh-table .call{color:var(--green);font-weight:700;white-space:nowrap}
.lh-table .ts{color:var(--t3);font-size:0.75rem;white-space:nowrap;font-variant-numeric:tabular-nums}
.lh-table .desc{color:var(--t1, #ddd)}
.lh-table .desc .sub{color:var(--t3);font-size:0.75rem}
.lh-empty{color:var(--t3);font-size:0.85rem;padding:1rem}
.lh-feed a.call-link,.lh-keyed a.call-link{color:inherit;text-decoration:none;border-bottom:1px dotted currentColor}
.lh-feed a.call-link:hover,.lh-keyed a.call-link:hover{text-decoration:none;border-bottom-style:solid}
</style>
<div class="lh-wrap">
  <h1>Last Heard</h1>
  <p style="color:var(--t3)">Rolling 3-day public window. <span id="lh-updated"></span></p>

  <div class="lh-keyed" id="lh-keyed"></div>

  <div class="lh-feed" id="lh-feed">
    <div class="lh-empty">Loading…</div>
  </div>
</div>
<script>
const seedEvents = <?= json_encode($events) ?>;
const seedKeyed  = <?= json_encode($keyed) ?>;

function qrzLink(call) {
    // Raw callsign -> a link to its QRZ.com listing. Returns the plain
    // em-dash placeholder unchanged when there's no callsign to link.
    if (!call) return '—';
    const safe = String(call);
    return `<a class="call-link" href="https://www.qrz.com/db/${encodeURIComponent(safe)}" target="_blank" rel="noopener">${safe}</a>`;
}

function idLink(e) {
    // The clickable technical identifier for this row -- ECR Hub
    // Monitor's Node#/Via pattern, adapted per-mode since TTN carries
    // three modes against ECR's two:
    //  - AllStar: the connecting node number -> its AllStarLink stats
    //    node-info page. URL pattern confirmed real (stats.allstarlink.org
    //    nodeinfo.cgi?node=<n>), not guessed.
    //  - DMR: src_id is NOT per-talker -- confirmed 2026-09-20 fixed at
    //    3147984 for every DMR event, the DVSwitch gateway's own constant
    //    registration ID, not the individual radio's. Dropped the
    //    radioid.net link (it always pointed at the same gateway record
    //    regardless of who talked). Links to the DMR bridge leg's own
    //    AllStarLink node-info page instead (node 1800, DVSwitch's fixed
    //    DMR->hub leg) -- confirmed real URL pattern, honestly represents
    //    which physical path the event came in on.
    //  - P25: TTN's P25 designator is a fixed constant (276) for every
    //    single row -- there's no per-row destination the way DMR's
    //    src_id gives one. Links to pistar.uk's P25 reflector list (a
    //    real, previously-confirmed-reachable reference page) rather
    //    than a guessed dvref.com per-reflector URL -- that site's exact
    //    public page structure (vs. its documented API paths) isn't
    //    confirmed, and a guessed link risks a dead link on a public,
    //    grant-facing page. Worth revisiting if/when DVRef's real page
    //    structure gets confirmed.
    if (e.mode === 'AllStar' && e.detail) {
        return `<a class="call-link" href="http://stats.allstarlink.org/nodeinfo.cgi?node=${encodeURIComponent(e.detail)}" target="_blank" rel="noopener">${e.detail}</a>`;
    }
    if (e.mode === 'DMR') {
        if (e.detail) {
            return `<a class="call-link" href="http://stats.allstarlink.org/nodeinfo.cgi?node=1800" target="_blank" rel="noopener">${e.detail}</a>`;
        }
        return '—';
    }
    if (e.mode === 'P25' && e.detail) {
        return `<a class="call-link" href="https://www.pistar.uk/p25_reflectors.php" target="_blank" rel="noopener">${e.detail}</a>`;
    }
    return e.detail || '—';
}

function viaLabel(e) {
    // ECR's Node#/Via structure -- Via is the connecting path a row came
    // in on. AllStar: the connecting node itself (same identity as the ID
    // column -- TTN is single-hop, so these legitimately match, same as
    // most of ECR's own AllStar rows). DMR/P25: the fixed DVSwitch bridge
    // leg every event of that mode passes through (see idLink()'s comment
    // above for why there's no per-event distinction possible beyond mode
    // -- confirmed 2026-09-20, src_id is a constant gateway ID, not
    // per-talker).
    if (e.mode === 'AllStar') {
        return e.detail ? `Node ${e.detail}` : '—';
    }
    if (e.mode === 'DMR') return 'DMR bridge';
    if (e.mode === 'P25') return 'P25 bridge';
    return '—';
}

function renderKeyed(keyed) {
    const el = document.getElementById('lh-keyed');
    const boxes = [];
    const a = keyed.AllStar;
    boxes.push(`<div class="box ${a && a.keyed ? 'on' : ''}"><h4>AllStar</h4>${a && a.keyed ? 'Online' : '<span class="idle">Offline</span>'}${a && a.last_keyed_at ? `<br><span style="color:var(--t3);font-size:0.75rem">last keyed ${a.last_keyed_at}</span>` : ''}${a ? ` <span style="color:var(--t3);font-size:0.75rem">(checked ${a.last_checked || '—'})</span>` : ''}</div>`);
    for (const mode of ['DMR', 'P25']) {
        const rows = keyed[mode] || [];
        if (!rows.length) { boxes.push(`<div class="box"><h4>${mode}</h4><span class="idle">Idle</span></div>`); continue; }
        boxes.push(rows.map(r => `<div class="box on ${r.stale ? 'stale' : ''}"><h4>${mode}${r.stale ? ' (stale?)' : ''}</h4>${qrzLink(r.callsign)} → ${r.talkgroup || '—'}<br><span style="color:var(--t3);font-size:0.75rem">since ${r.since}</span></div>`).join(''));
    }
    el.innerHTML = boxes.join('');
}

function enrichSummary(e) {
    // Namespaced by source deliberately (matches lastheard-query.php's
    // ttn_lastheard_enrich_events()) -- radioid.net and QRZ can both
    // return a name for the same callsign, so which source said it
    // stays visible rather than collapsing into one ambiguous field.
    const bits = [];
    const en = e.enrich || {};
    if (en.radioid && en.radioid.name) bits.push(en.radioid.name + (en.radioid.city ? ` (${en.radioid.city}, ${en.radioid.state || ''})`.replace(', )', ')') : ''));
    else if (en.qrz && en.qrz.name) bits.push(en.qrz.name + (en.qrz.location ? ` (${en.qrz.location})` : ''));
    if (en.allstarlink && en.allstarlink.freq) bits.push(en.allstarlink.freq + (en.allstarlink.ctcss ? ` / ${en.allstarlink.ctcss}` : ''));
    return bits.join(' · ');
}

function renderFeed(events) {
    const el = document.getElementById('lh-feed');
    if (!events.length) { el.innerHTML = '<div class="lh-empty">No activity in this window.</div>'; return; }
    // Already newest-first -- ttn_lastheard_events() returns connected_at
    // DESC server-side, so no client-side sort needed here.
    const rows = events.slice(0, 50).map(e => {
        const extra = enrichSummary(e);
        const locHtml = e.location ? `<div class="sub">${e.location}</div>` : '';
        const extraHtml = extra ? `<div class="sub">${extra}</div>` : '';
        return `
        <tr>
            <td class="mode">${e.mode}</td>
            <td class="idcol">${idLink(e)}</td>
            <td class="via">${viaLabel(e)}</td>
            <td class="call">${qrzLink(e.callsign)}</td>
            <td class="ts">${e.connected_at}</td>
            <td class="desc">${locHtml}${extraHtml}</td>
        </tr>`;
    }).join('');
    el.innerHTML = `<table class="lh-table">
        <thead><tr><th>Type</th><th>ID</th><th>Via</th><th>Callsign</th><th>Time (UTC)</th><th>Details</th></tr></thead>
        <tbody>${rows}</tbody>
    </table>`;
}

renderKeyed(seedKeyed);
renderFeed(seedEvents);
document.getElementById('lh-updated').textContent = 'Updated ' + new Date().toLocaleTimeString();

async function refresh() {
    try {
        // ASSUMPTION: fetch path -- adjust if api/ isn't served at /api/ off this page's own path.
        const res = await fetch('/api/lastheard-feed.php?days=3');
        if (!res.ok) return;
        const data = await res.json();
        renderKeyed(data.currently_keyed);
        renderFeed(data.events);
        document.getElementById('lh-updated').textContent = 'Updated ' + new Date().toLocaleTimeString();
    } catch (e) { /* leave last good render on screen */ }
}
// 5s, down from 30s -- paired with the proposed systemd log-tailer
// daemon (replaces the 5-min cron) so the page's own staleness stops
// being the bottleneck once the backend actually updates near-instantly.
setInterval(refresh, 5000);
</script>
<?php require_once TTN_INCLUDES . '/footer.php'; // confirmed via grep on the live index.php (line 462) ?>
