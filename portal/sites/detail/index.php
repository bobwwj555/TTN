<?php
require_once '/home/obdswlpx/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';

$site_url = s('site_url','https://dev.ttn.radio');
$site_id  = (int)($_GET['site'] ?? 0);

if (!$site_id) {
    header('Location: ' . $site_url . '/sites/');
    exit;
}

$site = db_row("SELECT * FROM sites WHERE id = ? AND is_public = 1", [$site_id]);

if (!$site) {
    http_response_code(404);
    $page_title   = 'Site Not Found';
    $page_section = 'network';
    require_once TTN_INCLUDES . '/header.php';
    echo '<main style="padding-top:46px;padding:6rem 5vw;text-align:center"><p style="font-family:var(--mono);color:var(--t3)">Site not found. <a href="' . $site_url . '/sites/" style="color:var(--green)">← All Sites</a></p></main>';
    require_once TTN_INCLUDES . '/footer.php';
    exit;
}

// Systems at this site
$systems = db_rows("SELECT * FROM systems WHERE site_id = ? AND is_public = 1 ORDER BY sort_order", [$site_id]);

// For each system load modes, ASL, EchoLink
$sys_data = [];
foreach ($systems as $sys) {
    $modes = db_rows("SELECT * FROM sys_modes WHERE system_id = ? ORDER BY is_primary DESC", [$sys['id']]);
    $asls  = db_rows("SELECT sa.*, srv.hostname FROM sys_asl sa LEFT JOIN asl_servers srv ON srv.id = sa.server_id WHERE sa.system_id = ? ORDER BY sa.is_hub DESC, sa.asl_number", [$sys['id']]);
    $el    = [];
    try { $el = db_rows("SELECT * FROM sys_echolink WHERE system_id = ?", [$sys['id']]); } catch (Exception $e) {}
    $dmr   = null;
    try { $dmr = db_row("SELECT * FROM sys_dmr WHERE system_id = ?", [$sys['id']]); } catch (Exception $e) {}
    $tgs   = $dmr ? db_rows("SELECT * FROM dmr_talkgroups WHERE sys_dmr_id = ? ORDER BY timeslot, tg_number", [$dmr['id']]) : [];
    $sera  = db_row("SELECT * FROM sera_records WHERE system_id = ?", [$sys['id']]);
    $sys_data[$sys['id']] = compact('modes','asls','el','dmr','tgs','sera');
    try {
        $sys_data[$sys['id']]['interfaces'] = db_rows(
            "SELECT * FROM sys_interfaces WHERE system_id=? AND is_public=1 ORDER BY sort_order, label",
            [$sys['id']]
        );
    } catch (Exception $e) { $sys_data[$sys['id']]['interfaces'] = []; }
    try {
        $sys_data[$sys['id']]['sera'] = db_row(
            "SELECT * FROM sera_records WHERE system_id=? AND status='coordinated' ORDER BY coordinated_at DESC LIMIT 1",
            [$sys['id']]
        );
    } catch (Exception $e) { $sys_data[$sys['id']]['sera'] = null; }
}

// Site crew
$crew = db_rows("
    SELECT o.callsign, o.display_name, o.qrz_url, o.photo_url, sc.role
    FROM site_crew sc
    JOIN operators o ON o.id = sc.operator_id
    WHERE sc.site_id = ? AND sc.approved = 1
    ORDER BY FIELD(sc.role,'trustee','site_manager','operator','builder','alternate'), o.sort_order
", [$site_id]);

// Build log
$buildlog = db_rows("
    SELECT b.*, o.callsign AS op_call
    FROM buildlog b
    JOIN operators o ON o.id = b.operator_id
    WHERE b.site_id = ?
    AND b.is_public = 1
    ORDER BY b.entry_date DESC
    LIMIT 10
", [$site_id]);

// Assets
$assets = db_rows("
    SELECT * FROM assets
    WHERE site_id = ? AND is_active = 1
    ORDER BY category, make, model
", [$site_id]);

// Latest telemetry
$telemetry = null;
try {
    $telemetry = db_row("SELECT * FROM site_telemetry WHERE site_id = ? ORDER BY recorded_at DESC LIMIT 1", [$site_id]);
} catch (Exception $e) {}

// Per-system live status — latest sys_telemetry row per system
$sys_status = [];
foreach ($systems as $sys) {
    try {
        $row = db_row(
            "SELECT is_online, last_keyed_at, connected_nodes, recorded_at
             FROM sys_telemetry WHERE system_id = ? ORDER BY recorded_at DESC LIMIT 1",
            [$sys['id']]
        );
        if ($row) $sys_status[$sys['id']] = $row;
    } catch (Exception $e) {}
}

// Recent connection history for all systems at this site (last 40 events)
$conn_history = [];
try {
    $sys_ids = array_column($systems, 'id');
    if ($sys_ids) {
        $placeholders = implode(',', array_fill(0, count($sys_ids), '?'));
        $conn_history = db_rows(
            "SELECT cl.*, s.callsign AS sys_callsign, s.label AS sys_label
             FROM conn_log cl
             JOIN systems s ON s.id = cl.system_id
             WHERE cl.system_id IN ($placeholders)
             ORDER BY cl.connected_at DESC
             LIMIT 40",
            $sys_ids
        );
    }
} catch (Exception $e) {}

// Site-wide last heard — most recent keyed event across all systems
$site_last_keyed = null;
foreach ($sys_status as $st) {
    if ($st['last_keyed_at']) {
        if (!$site_last_keyed || strtotime($st['last_keyed_at']) > strtotime($site_last_keyed))
            $site_last_keyed = $st['last_keyed_at'];
    }
}

// Hub ASL for live polling
$hub_asl = null;
foreach ($systems as $sys) {
    $asls = $sys_data[$sys['id']]['asls'];
    foreach ($asls as $a) {
        if ($a['is_hub']) { $hub_asl = $a['asl_number']; break 2; }
    }
}
if (!$hub_asl) {
    foreach ($systems as $sys) {
        $asls = $sys_data[$sys['id']]['asls'];
        if (!empty($asls)) { $hub_asl = $asls[0]['asl_number']; break; }
    }
}

$status_labels = [
    'live'     => ['● LIVE',     'green'],
    'building' => ['◐ BUILDING', 'amber'],
    'planned'  => ['○ PLANNED',  't3'],
    'offline'  => ['✕ OFFLINE',  'red'],
];
[$status_label, $status_color] = $status_labels[$site['status']] ?? ['UNKNOWN', 't3'];

function time_ago(?string $dt): string {
    if (!$dt) return '';
    $diff = time() - strtotime($dt);
    if ($diff < 60)        return $diff . 's ago';
    if ($diff < 3600)      return floor($diff/60) . 'm ago';
    if ($diff < 86400)     return floor($diff/3600) . 'h ago';
    return floor($diff/86400) . 'd ago';
}

$page_title   = $site['name'] . ' · ' . ($site['city'] ?: $site['state']);
$page_section = 'network';

require_once TTN_INCLUDES . '/header.php';
?>
<style>
.det-hero{padding:3.5rem 5vw 2rem;position:relative;overflow:hidden;border-bottom:1px solid var(--border2)}
.det-hero-bg{position:absolute;inset:0;background:radial-gradient(ellipse 60% 50% at 70% 50%,rgba(255,171,0,0.03),transparent 70%)}
.det-hero-grid{position:absolute;inset:0;background-image:linear-gradient(var(--border) 1px,transparent 1px),linear-gradient(90deg,var(--border) 1px,transparent 1px);background-size:40px 40px;opacity:0.4}
.det-hero-inner{position:relative;z-index:1;display:grid;grid-template-columns:1fr auto;gap:2rem;align-items:start}
.det-breadcrumb{font-family:var(--mono);font-size:0.6rem;color:var(--t3);letter-spacing:0.1em;text-transform:uppercase;margin-bottom:1rem}
.det-breadcrumb a{color:var(--t3);text-decoration:none}.det-breadcrumb a:hover{color:var(--green)}
.det-call{font-family:var(--display);font-weight:800;font-size:clamp(2.5rem,5vw,4.5rem);line-height:0.9;color:var(--amber);text-transform:uppercase}
.det-name{font-family:var(--display);font-weight:300;font-size:1.1rem;color:var(--t1);text-transform:uppercase;letter-spacing:0.1em;margin-top:0.3rem}
.det-loc{font-family:var(--mono);font-size:0.75rem;color:var(--t3);margin-top:0.2rem;letter-spacing:0.06em}
.det-status{font-family:var(--mono);font-size:0.75rem;letter-spacing:0.12em;text-transform:uppercase;margin-top:0.8rem}
.det-status.green{color:var(--green)}.det-status.amber{color:var(--amber)}.det-status.t3{color:var(--t3)}.det-status.red{color:var(--red)}
.live-panel{background:var(--panel);border:1px solid var(--border2);font-family:var(--mono);min-width:260px}
.lp-hd{background:var(--panel2);border-bottom:1px solid var(--border2);padding:0.5rem 0.9rem;font-size:0.58rem;letter-spacing:0.15em;color:var(--t3);text-transform:uppercase;display:flex;justify-content:space-between}
.lp-hd .g{color:var(--green)}
.lp-banner{padding:0.35rem 0.9rem;font-size:0.6rem;letter-spacing:0.08em;border-bottom:1px solid var(--border2)}
.lp-banner.ok{color:var(--green);background:var(--gglow)}.lp-banner.loading{color:var(--t3)}.lp-banner.error{color:var(--red)}
.lp-conn-row{display:flex;gap:0.5rem;padding:0.3rem 0.9rem;border-bottom:1px solid var(--border);font-size:0.62rem;align-items:center}
.lp-node{color:var(--amber);min-width:55px}.lp-call{color:var(--t1);flex:1}.lp-dir{color:var(--green);font-size:0.54rem}
.lp-empty{padding:0.6rem 0.9rem;font-size:0.6rem;color:var(--t3)}
.lp-refresh{cursor:pointer;border:1px solid var(--border2);padding:0.1rem 0.4rem;font-size:0.55rem;color:var(--t3)}
.lp-refresh:hover{color:var(--green);border-color:var(--gdim)}
.det-content{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border2);border-top:1px solid var(--border2)}
.det-col{background:var(--bg);padding:2.5rem 5vw;display:flex;flex-direction:column;gap:2rem}
.sys-card{background:var(--panel);border:1px solid var(--border2);margin-bottom:1rem}
.sys-hd{background:var(--panel2);border-bottom:1px solid var(--border2);padding:0.7rem 1rem;display:flex;align-items:center;justify-content:space-between}
.sys-callsign{font-family:var(--mono);font-size:0.8rem;color:var(--amber)}
.sys-label{font-family:var(--mono);font-size:0.6rem;color:var(--t3);letter-spacing:0.08em}
.sys-body{padding:0.8rem 1rem}
.sys-freq{font-family:var(--mono);font-size:1rem;color:var(--t1);margin-bottom:0.3rem}
.sys-pl{font-family:var(--mono);font-size:0.65rem;color:var(--t3)}
.sys-modes{display:flex;gap:0.4rem;flex-wrap:wrap;margin:0.5rem 0}
.mode-tag{font-family:var(--mono);font-size:0.55rem;letter-spacing:0.1em;text-transform:uppercase;padding:0.15rem 0.4rem;border:1px solid var(--border2);color:var(--t2)}
.mode-tag.fm{border-color:var(--green);color:var(--green)}
.mode-tag.dmr{border-color:var(--amber);color:var(--amber)}
.sys-asl{font-family:var(--mono);font-size:0.65rem;color:var(--t3);margin-top:0.5rem}
.sys-asl span{color:var(--amber)}
.sera-badge{display:inline-flex;align-items:center;gap:0.3rem;font-family:var(--mono);font-size:0.55rem;letter-spacing:0.1em;text-transform:uppercase;padding:0.15rem 0.5rem;border:1px solid var(--green);color:var(--green);margin-top:0.4rem}
.spec-tbl{width:100%;font-family:var(--mono);font-size:0.7rem;border-collapse:collapse}
.spec-tbl tr{border-bottom:1px solid var(--border)}
.spec-tbl tr:last-child{border-bottom:none}
.spec-tbl td{padding:0.5rem 0.7rem;vertical-align:top}
.spec-tbl td:first-child{color:var(--t3);font-size:0.6rem;letter-spacing:0.1em;text-transform:uppercase;width:130px;white-space:nowrap}
.spec-tbl td:last-child{color:var(--t1)}
.crew-row{display:flex;align-items:center;gap:0.8rem;padding:0.6rem 0;border-bottom:1px solid var(--border)}
.crew-row:last-child{border-bottom:none}
.crew-photo{width:40px;height:40px;border-radius:2px;object-fit:cover;border:1px solid var(--border2);flex-shrink:0}
.crew-placeholder{width:40px;height:40px;background:var(--panel2);border:1px solid var(--border2);display:flex;align-items:center;justify-content:center;font-family:var(--mono);font-size:0.7rem;color:var(--amber);flex-shrink:0}
.crew-call{font-family:var(--mono);font-size:0.75rem;color:var(--amber)}
.crew-name{font-family:var(--mono);font-size:0.6rem;color:var(--t2)}
.crew-role{font-family:var(--mono);font-size:0.55rem;color:var(--t3);letter-spacing:0.08em;text-transform:uppercase}
.bl-entry{border-bottom:1px solid var(--border);padding:1rem 0}
.bl-entry:first-child{padding-top:0}.bl-entry:last-child{border-bottom:none;padding-bottom:0}
.bl-meta{font-family:var(--mono);font-size:0.58rem;color:var(--t3);letter-spacing:0.08em;text-transform:uppercase;margin-bottom:0.4rem;display:flex;gap:1rem}
.bl-title{font-family:var(--display);font-weight:700;font-size:0.95rem;color:var(--t1);margin-bottom:0.4rem}
.bl-body{font-size:0.85rem;color:var(--t2);line-height:1.6}
.asset-row{display:grid;grid-template-columns:100px 1fr auto;gap:0.5rem;padding:0.5rem 0;border-bottom:1px solid var(--border);font-size:0.75rem;align-items:center}
.asset-row:last-child{border-bottom:none}
.asset-cat{font-family:var(--mono);font-size:0.58rem;color:var(--green);letter-spacing:0.08em;text-transform:uppercase}
.asset-name{color:var(--t1)}.asset-cond{font-family:var(--mono);font-size:0.58rem;color:var(--t3);text-transform:uppercase}
.conn-hist-row{display:grid;grid-template-columns:56px 70px 1fr auto;gap:0.4rem;padding:0.35rem 0;border-bottom:1px solid var(--border);font-family:var(--mono);font-size:0.62rem;align-items:center}
.conn-hist-row:last-child{border-bottom:none}
.ch-time{color:var(--t3);font-size:0.56rem}
.ch-node{color:var(--amber)}
.ch-call{color:var(--t1)}
.ch-dir{font-size:0.54rem;text-transform:uppercase;letter-spacing:0.08em}
.ch-dir.in{color:var(--green)}.ch-dir.out{color:var(--t3)}
.ch-sys{font-size:0.54rem;color:var(--t3);text-align:right}
@media(max-width:900px){.det-hero-inner{grid-template-columns:1fr}.live-panel{min-width:auto;width:100%}.det-content{grid-template-columns:1fr}}
</style>

<main style="padding-top:46px">

<div class="det-hero">
    <div class="det-hero-bg"></div>
    <div class="det-hero-grid"></div>
    <div class="det-hero-inner">
        <div>
            <div class="det-breadcrumb">
                <a href="<?= $site_url ?>/sites/">← Network</a>
                &nbsp;/&nbsp; <?= htmlspecialchars($site['name']) ?>
            </div>
            <div class="det-call"><?= htmlspecialchars($site['callsign'] ?? $site['name']) ?></div>
            <div class="det-name"><?= htmlspecialchars($site['name']) ?></div>
            <div class="det-loc">
                <?= htmlspecialchars($site['city'] ? $site['city'].', '.$site['state'] : $site['state']) ?>
                <?= $site['tower_height_ft'] ? ' · '.$site['tower_height_ft'].'ft' : '' ?>
                <?= $site['tower_type'] ? ' '.$site['tower_type'] : '' ?>
            </div>
            <div class="det-status <?= $status_color ?>"><?= $status_label ?>
                <?php if ($site_last_keyed): ?>
                <span style="color:var(--t3);font-size:0.6rem;margin-left:0.8rem;font-weight:400">Last heard <?= time_ago($site_last_keyed) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($hub_asl): ?>
        <div class="live-panel">
            <div class="lp-hd">
                <span class="g">◉ ASL <?= htmlspecialchars($hub_asl) ?></span>
                <span class="lp-refresh" onclick="pollDetail()">↻</span>
            </div>
            <div class="lp-banner loading" id="detBanner">⟳ Polling...</div>
            <div id="detConnections"><div class="lp-empty">Loading...</div></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="det-content">

    <!-- LEFT COLUMN -->
    <div class="det-col">

        <!-- SYSTEMS -->
        <div>
            <div class="sec-label" style="margin-bottom:1rem">Systems (<?= count($systems) ?>)</div>
            <?php foreach ($systems as $sys):
                $sd = $sys_data[$sys['id']];
            ?>
            <div class="sys-card">
                <div class="sys-hd">
                    <div>
                        <span class="sys-callsign"><?= htmlspecialchars($sys['callsign']) ?></span>
                        <?php if ($sys['label']): ?>
                        <span class="sys-label"> · <?= htmlspecialchars($sys['label']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.8rem">
                        <?php
                        $st = $sys_status[$sys['id']] ?? null;
                        if ($st):
                            $dot = $st['is_online'] ? '<span style="color:var(--green)">●</span>' : '<span style="color:var(--t3)">○</span>';
                            $lk  = $st['last_keyed_at'] ? time_ago($st['last_keyed_at']) : '';
                        ?>
                        <span style="font-family:var(--mono);font-size:0.58rem;color:var(--t3)">
                            <?= $dot ?>
                            <?php if ($lk): ?><span style="margin-left:0.3rem">heard <?= $lk ?></span><?php endif; ?>
                            <?php if ($st['connected_nodes']): ?><span style="color:var(--amber);margin-left:0.4rem"><?= $st['connected_nodes'] ?> conn</span><?php endif; ?>
                        </span>
                        <?php endif; ?>
                        <span style="font-family:var(--mono);font-size:0.6rem;color:var(--t3);text-transform:uppercase"><?= $sys['system_type'] ?></span>
                    </div>
                </div>
                <div class="sys-body">
                    <?php if ($sys['freq_tx']): ?>
                    <div class="sys-freq">
                        <?= htmlspecialchars($sys['freq_tx']) ?>
                        <?= $sys['freq_rx'] ? ' / '.htmlspecialchars($sys['freq_rx']) : '' ?>
                        <?php if ($sys['band']): ?><span style="font-size:0.65rem;color:var(--t3);margin-left:0.4rem"><?= htmlspecialchars($sys['band']) ?></span><?php endif; ?>
                    </div>
                    <?php if ($sys['access_code']): ?>
                    <div class="sys-pl"><?= htmlspecialchars($sys['access_type']) ?> <?= htmlspecialchars($sys['access_code']) ?></div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- Modes -->
                    <?php if ($sd['modes']): ?>
                    <div class="sys-modes">
                        <?php foreach ($sd['modes'] as $m): ?>
                        <span class="mode-tag <?= strtolower($m['mode']) ?>"><?= $m['mode'] ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- ASL nodes -->
                    <?php if ($sd['asls']): ?>
                    <div class="sys-asl">ASL:
                        <?php foreach ($sd['asls'] as $a): ?>
                        <span><?= htmlspecialchars($a['asl_number']) ?></span><?= $a['is_hub'] ? ' ★' : '' ?>
                        <?php endforeach; ?>
                        <?php if (!empty($sd['asls'][0]['hostname'])): ?>
                        <span style="color:var(--t3);margin-left:0.4rem">@ <?= htmlspecialchars($sd['asls'][0]['hostname']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- EchoLink -->
                    <?php foreach ($sd['el'] as $e): ?>
                    <div class="sys-asl">EchoLink: <span><?= htmlspecialchars($e['el_callsign']) ?></span> #<?= htmlspecialchars($e['el_number']) ?></div>
                    <?php endforeach; ?>

                    <!-- Interface Links -->
                    <?php if (!empty($sd['interfaces'])): ?>
                    <div style="margin-top:0.6rem;display:flex;gap:0.4rem;flex-wrap:wrap">
                        <?php foreach ($sd['interfaces'] as $iface): ?>
                        <a href="<?= htmlspecialchars($iface['url']) ?>" target="_blank"
                           style="font-family:var(--mono);font-size:0.55rem;letter-spacing:0.08em;text-transform:uppercase;padding:0.15rem 0.5rem;border:1px solid var(--border2);color:var(--t2);text-decoration:none;transition:all 0.12s"
                           onmouseover="this.style.borderColor='var(--green)';this.style.color='var(--green)'"
                           onmouseout="this.style.borderColor='var(--border2)';this.style.color='var(--t2)'">
                            <?= htmlspecialchars($iface['label']) ?> ↗
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- SERA Coordination -->
                    <?php
                    $sera = $sd['sera'] ?? null;
                    if ($sera && ($sera['status'] ?? '') === 'coordinated'):
                    ?>
                    <div class="sera-badge">✓ SERA Coordinated · ID <?= htmlspecialchars($sera['sera_id']) ?></div>
                    <div style="font-family:var(--mono);font-size:0.58rem;color:var(--t3);margin-top:0.3rem">
                        <?= $sera['coordinated_at'] ? 'Coordinated ' . date('M j Y', strtotime($sera['coordinated_at'])) : '' ?>
                        <?= $sera['expires_at'] ? ' · Recertify by ' . date('M j Y', strtotime($sera['expires_at'])) : '' ?>
                        <?= $sera['erp_watts'] ? ' · ' . $sera['erp_watts'] . 'W ERP' : '' ?>
                    </div>
                    <?php endif; ?>

                    <!-- DMR -->
                    <?php if ($sd['dmr']): ?>
                    <div class="sys-asl" style="margin-top:0.4rem">DMR ID: <span><?= htmlspecialchars($sd['dmr']['dmr_repeater_id']) ?></span> · CC<?= $sd['dmr']['color_code'] ?> · <?= htmlspecialchars($sd['dmr']['network']) ?></div>
                    <?php if ($sd['tgs']): ?>
                    <div style="margin-top:0.4rem;font-family:var(--mono);font-size:0.6rem;color:var(--t3)">
                        <?php foreach ($sd['tgs'] as $tg): ?>
                        TG<?= $tg['tg_number'] ?> <?= htmlspecialchars($tg['tg_name']) ?> (TS<?= $tg['timeslot'] ?>)<?= $tg['is_static'] ? ' ★' : '' ?><br>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- SITE SPECS -->
        <div>
            <div class="sec-label" style="margin-bottom:1rem">Site Specs</div>
            <table class="spec-tbl">
                <?php if ($site['tower_height_ft']): ?>
                <tr><td>Tower</td><td><?= $site['tower_height_ft'] ?>ft <?= htmlspecialchars($site['tower_type'] ?? '') ?></td></tr>
                <?php endif; ?>
                <?php if ($site['elevation_ft']): ?>
                <tr><td>Elevation</td><td><?= number_format($site['elevation_ft']) ?>ft GAMSL</td></tr>
                <?php endif; ?>
                <?php if ($site['power_primary']): ?>
                <tr><td>Power</td><td><?= htmlspecialchars($site['power_primary']) ?><?= $site['power_backup'] ? ' / '.htmlspecialchars($site['power_backup']) : '' ?></td></tr>
                <?php endif; ?>
                <?php if ($site['solar_watts']): ?>
                <tr><td>Solar</td><td><?= $site['solar_watts'] ?>W</td></tr>
                <?php endif; ?>
                <?php if ($site['battery_ah']): ?>
                <tr><td>Battery</td><td><?= $site['battery_ah'] ?>Ah</td></tr>
                <?php endif; ?>
                <?php if ($site['lat'] && $site['lng']): ?>
                <tr><td>Coordinates</td><td><?= number_format($site['lat'],4) ?>°N <?= number_format(abs($site['lng']),4) ?>°W</td></tr>
                <?php endif; ?>
                <tr><td>Phase</td><td><?= $site['phase'] ?></td></tr>
            </table>
        </div>

        <!-- TELEMETRY -->
        <?php if ($telemetry): ?>
        <div>
            <div class="sec-label" style="margin-bottom:1rem">Last Telemetry <span style="font-size:0.55rem;color:var(--t3);margin-left:0.5rem"><?= date('M j H:i', strtotime($telemetry['recorded_at'])) ?></span></div>
            <table class="spec-tbl">
                <?php if ($telemetry['battery_volts']): ?><tr><td>Battery</td><td><?= $telemetry['battery_volts'] ?>V<?= $telemetry['battery_pct'] ? ' ('.$telemetry['battery_pct'].'%)' : '' ?></td></tr><?php endif; ?>
                <?php if ($telemetry['solar_watts']): ?><tr><td>Solar</td><td><?= $telemetry['solar_watts'] ?>W</td></tr><?php endif; ?>
                <?php if ($telemetry['load_amps']): ?><tr><td>Load</td><td><?= $telemetry['load_amps'] ?>A</td></tr><?php endif; ?>
                <?php if ($telemetry['temp_cabinet_f']): ?><tr><td>Cabinet Temp</td><td><?= $telemetry['temp_cabinet_f'] ?>°F</td></tr><?php endif; ?>
                <?php if ($telemetry['temp_outside_f']): ?><tr><td>Outside Temp</td><td><?= $telemetry['temp_outside_f'] ?>°F</td></tr><?php endif; ?>
                <?php if (!is_null($telemetry['door_open'])): ?><tr><td>Door</td><td style="color:<?= $telemetry['door_open'] ? 'var(--red)' : 'var(--green)' ?>"><?= $telemetry['door_open'] ? '⚠ OPEN' : '✓ Closed' ?></td></tr><?php endif; ?>
            </table>
            <?php if ($telemetry['camera_url']): ?>
            <img src="<?= htmlspecialchars($telemetry['camera_url']) ?>" alt="Site camera" style="max-width:100%;margin-top:0.8rem;border:1px solid var(--border2)" loading="lazy">
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- SITE PHOTO / COVERAGE -->
        <?php if ($site['photo_url'] || $site['coverage_url']): ?>
        <div style="display:grid;grid-template-columns:<?= $site['photo_url'] && $site['coverage_url'] ? '1fr 1fr' : '1fr' ?>;gap:1px;background:var(--border2)">
            <?php if ($site['photo_url']): ?>
            <div><img src="<?= htmlspecialchars($site['photo_url']) ?>" alt="Site photo" style="width:100%;height:180px;object-fit:cover;display:block;filter:saturate(0.7) brightness(0.85)"><div style="background:var(--panel2);padding:0.3rem 0.7rem;font-family:var(--mono);font-size:0.55rem;color:var(--t3);text-transform:uppercase;letter-spacing:0.1em">Site Photo</div></div>
            <?php endif; ?>
            <?php if ($site['coverage_url']): ?>
            <div><img src="<?= htmlspecialchars($site['coverage_url']) ?>" alt="Coverage map" style="width:100%;height:180px;object-fit:cover;display:block;filter:saturate(0.7) brightness(0.85)"><div style="background:var(--panel2);padding:0.3rem 0.7rem;font-family:var(--mono);font-size:0.55rem;color:var(--t3);text-transform:uppercase;letter-spacing:0.1em">Coverage</div></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- RIGHT COLUMN -->
    <div class="det-col" style="background:var(--bg2)">

        <!-- CONNECTION HISTORY -->
        <div>
            <div class="sec-label" style="margin-bottom:1rem">Connection History
                <?php if ($conn_history): ?>
                <span style="font-size:0.55rem;color:var(--t3);margin-left:0.5rem;font-family:var(--mono);font-weight:400">Recent <?= count($conn_history) ?> events</span>
                <?php endif; ?>
            </div>
            <?php if ($conn_history): ?>
            <div>
                <?php foreach ($conn_history as $ev):
                    $dir_class = strtolower($ev['direction']) === 'to'   ? 'in'
                               : (strtolower($ev['direction']) === 'from' ? 'out' : '');
                    $ago = time_ago($ev['connected_at']);
                ?>
                <div class="conn-hist-row">
                    <span class="ch-time"><?= $ago ?></span>
                    <span class="ch-node"><?= htmlspecialchars($ev['connected_node']) ?></span>
                    <span class="ch-call"><?= htmlspecialchars($ev['callsign'] ?: '—') ?>
                        <?php if ($ev['location']): ?><small style="color:var(--t3);display:block;font-size:0.54rem"><?= htmlspecialchars($ev['location']) ?></small><?php endif; ?>
                    </span>
                    <div style="text-align:right">
                        <span class="ch-dir <?= $dir_class ?>"><?= htmlspecialchars($ev['direction'] ?: '—') ?></span>
                        <?php if (count($systems) > 1): ?>
                        <div class="ch-sys"><?= htmlspecialchars($ev['sys_callsign']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="font-family:var(--mono);font-size:0.65rem;color:var(--t3)">No connection history yet — telemetry cron not yet running or no connections logged.</p>
            <?php endif; ?>
        </div>

        <!-- CREW -->
        <?php if ($crew): ?>
        <div>
            <div class="sec-label" style="margin-bottom:1rem">Site Crew</div>
            <?php foreach ($crew as $c): ?>
            <div class="crew-row">
                <?php if (!empty($c['photo_url'])): ?>
                <img src="<?= htmlspecialchars($c['photo_url']) ?>" alt="<?= htmlspecialchars($c['callsign']) ?>" class="crew-photo">
                <?php else: ?>
                <div class="crew-placeholder"><?= htmlspecialchars(substr($c['callsign'],0,2)) ?></div>
                <?php endif; ?>
                <div>
                    <div class="crew-call"><?= htmlspecialchars($c['callsign']) ?></div>
                    <div class="crew-name"><?= htmlspecialchars($c['display_name']) ?></div>
                    <div class="crew-role"><?= ucfirst(str_replace('_',' ',$c['role'])) ?></div>
                </div>
                <?php if ($c['qrz_url']): ?>
                <a href="<?= htmlspecialchars($c['qrz_url']) ?>" target="_blank" style="margin-left:auto;font-family:var(--mono);font-size:0.55rem;color:var(--t3);text-decoration:none">QRZ ↗</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- BUILD LOG -->
        <div>
            <div class="sec-label" style="margin-bottom:1rem">Build Log</div>
            <?php if ($buildlog): ?>
            <?php foreach ($buildlog as $entry): ?>
            <div class="bl-entry">
                <div class="bl-meta">
                    <span style="color:var(--green)"><?= strtoupper($entry['entry_type']) ?></span>
                    <span><?= date('M j, Y', strtotime($entry['entry_date'])) ?></span>
                    <span><?= htmlspecialchars($entry['op_call']) ?></span>
                </div>
                <div class="bl-title"><?= htmlspecialchars($entry['title']) ?></div>
                <div class="bl-body"><?= nl2br(htmlspecialchars($entry['body'])) ?></div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <p style="font-family:var(--mono);font-size:0.65rem;color:var(--t3)">No build log entries yet.</p>
            <?php endif; ?>
        </div>

        <!-- ASSETS -->
        <?php if ($assets): ?>
        <div>
            <div class="sec-label" style="margin-bottom:1rem">Equipment</div>
            <?php foreach ($assets as $a): ?>
            <div class="asset-row">
                <span class="asset-cat"><?= htmlspecialchars($a['category']) ?></span>
                <span class="asset-name">
                    <?= htmlspecialchars(trim(($a['make']??'').' '.($a['model']??''))) ?>
                    <?php if ($a['description']): ?><small style="color:var(--t3);display:block;font-size:0.65rem"><?= htmlspecialchars($a['description']) ?></small><?php endif; ?>
                </span>
                <span class="asset-cond"><?= htmlspecialchars($a['condition_rating']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<div style="padding:1.5rem 5vw;background:var(--bg);border-top:1px solid var(--border2)">
    <a href="<?= $site_url ?>/sites/" style="font-family:var(--mono);font-size:0.65rem;color:var(--t3);text-decoration:none;letter-spacing:0.1em;text-transform:uppercase">← All Sites</a>
</div>

</main>

<?php if ($hub_asl): ?>
<script>
const DETAIL_NODE = '<?= htmlspecialchars($hub_asl) ?>';
async function pollDetail() {
    const banner = document.getElementById('detBanner');
    const conns  = document.getElementById('detConnections');
    if (!banner || !conns) return;
    banner.className = 'lp-banner loading';
    banner.textContent = '⟳ Polling...';
    try {
        const res  = await fetch('<?= s('ami_proxy_url','https://tn.w4bww.net/ttn-status.php') ?>?node=' + DETAIL_NODE, { signal: AbortSignal.timeout(7000) });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'API error');
        banner.className  = 'lp-banner ok';
        banner.textContent = '◉ ONLINE · ' + (data.conn_count ?? 0) + ' connected · ' + new Date().toLocaleTimeString();
        if (data.connections && data.connections.length > 0) {
            conns.innerHTML = data.connections.map(c =>
                `<div class="lp-conn-row"><span class="lp-node">${c.node}</span><span class="lp-call">${c.callsign}</span><span class="lp-dir">${c.direction}</span></div>`
            ).join('');
        } else {
            conns.innerHTML = '<div class="lp-empty">No active connections</div>';
        }
    } catch(e) {
        banner.className  = 'lp-banner error';
        banner.textContent = '✕ Unavailable · ' + new Date().toLocaleTimeString();
        conns.innerHTML = '<div class="lp-empty" style="color:var(--t3)">Connect: ASL ' + DETAIL_NODE + '</div>';
    }
}
document.addEventListener('DOMContentLoaded', () => { pollDetail(); setInterval(pollDetail, 60000); });
</script>
<?php endif; ?>

<?php require_once TTN_INCLUDES . '/footer.php'; ?>
