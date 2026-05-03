<?php
require_once '/home/obdswlpx/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';

$page_title = s('org_name', 'Tennessee Technological Community');

// Settings
$site_url    = s('site_url',    'https://dev.ttn.radio');
$hub_node    = s('hub_node',    '65392');
$hub_freq    = s('hub_freq',    '53.870');
$hub_url     = s('hub_url',     'https://hub.ttn.radio');
$donate_url  = s('site_donate_url', '/donate/');
$img_logo    = s('img_logo',    '/assets/img/Diamond_and_Small_Coil.jpeg');
$img_hero    = s('img_hero',    '/assets/img/image-2-687x1024.jpg');
$img_coverage= s('img_coverage','/assets/img/Estimate_Coverage_Map.png');
$facebook    = s('social_facebook', '');
$github      = s('social_github',   '');
$email       = s('contact_email',   'bobwwj555@gmail.com');
$phone       = s('contact_phone',   '865-202-6696');
$org_name    = s('org_name',    'Tennessee Technological Community');
$callsign    = s('org_callsign','W4BWW');
$ein         = s('org_ein',     '41-2680033');
$paypal_url  = s('paypal_url',  '');
$gofundme    = 'https://www.gofundme.com/f/support-ttns-6meter-network-initiative';

// Sites with primary system info
$sites = db_rows("
    SELECT si.*,
        sys.callsign      AS sys_callsign,
        sys.freq_tx       AS primary_tx,
        sys.freq_rx       AS primary_rx,
        sys.access_code   AS primary_pl,
        sys.band          AS primary_band,
        sys.erp_watts,
        sa.asl_number     AS hub_asl
    FROM sites si
    LEFT JOIN systems sys ON sys.site_id = si.id AND sys.sort_order = 0
    LEFT JOIN sys_asl sa  ON sa.system_id = sys.id AND sa.is_hub = 1
    WHERE si.is_public = 1
    ORDER BY FIELD(si.status,'live','building','planned','offline'), si.phase, si.name
");

$site_count    = count($sites);
$live_count    = count(array_filter($sites, fn($n) => $n['status'] === 'live'));
$build_count   = count(array_filter($sites, fn($n) => $n['status'] === 'building'));
$planned_count = count(array_filter($sites, fn($n) => $n['status'] === 'planned'));

// Last heard — most recent keyed event for the hub node, shown when nobody is connected
$hub_last_keyed = null;
$hub_conn_count = null;
try {
    $row = db_row("
        SELECT st.last_keyed_at, st.connected_nodes, st.is_online
        FROM sys_telemetry st
        JOIN systems s  ON s.id  = st.system_id
        JOIN sys_asl sa ON sa.system_id = s.id
        WHERE sa.asl_number = ?
        ORDER BY st.recorded_at DESC
        LIMIT 1
    ", [$hub_node]);
    if ($row) {
        $hub_last_keyed = $row['last_keyed_at'];
        $hub_conn_count = $row['connected_nodes'];
    }
} catch (Exception $e) {}

function time_ago(?string $dt): string {
    if (!$dt) return '';
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return $diff . 's ago';
    if ($diff < 3600)  return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    return floor($diff/86400) . 'd ago';
}

// Team
$team = db_rows("
    SELECT o.*, o.city AS location_name, o.state AS location_state
    FROM operators o
    WHERE o.is_active = 1 AND o.is_public = 1
    ORDER BY o.sort_order ASC, o.callsign ASC
");

// Roadmap
$roadmap = db_rows("SELECT * FROM roadmap_items ORDER BY phase, sort_order");
$roadmap_by_phase = [];
foreach ($roadmap as $item) {
    $roadmap_by_phase[$item['phase']][] = $item;
}

require_once TTN_INCLUDES . '/header.php';
?>
<style>
/* ── HOMEPAGE SPECIFIC ── */
.hero{display:grid;grid-template-columns:1fr 360px;min-height:calc(100vh - 46px);position:relative;overflow:hidden}
.hero-left{display:flex;flex-direction:column;justify-content:center;padding:5rem 5vw;position:relative;z-index:2}
.hero-eyebrow{font-family:var(--mono);font-size:0.58rem;color:var(--t3);letter-spacing:0.2em;text-transform:uppercase;margin-bottom:1rem}
.hero-h1{font-family:var(--display);font-weight:800;font-size:clamp(2rem,5vw,3.8rem);text-transform:uppercase;letter-spacing:0.04em;line-height:1.0;color:var(--t1);margin-bottom:1.2rem}
.hero-h1 em{color:var(--green);font-style:normal}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}
.hero-sub{font-size:0.88rem;color:var(--t2);line-height:1.8;max-width:480px;margin-bottom:2rem}
.hero-btns{display:flex;gap:0.8rem;flex-wrap:wrap}
.btn-p{background:var(--green);color:#000;font-family:var(--mono);font-size:0.65rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;padding:0.75rem 1.4rem;text-decoration:none;transition:all 0.15s}
.btn-p:hover{background:#00cc6a;text-decoration:none}
.btn-g{background:transparent;color:var(--t2);font-family:var(--mono);font-size:0.65rem;letter-spacing:0.1em;text-transform:uppercase;padding:0.75rem 1.4rem;border:1px solid var(--border2);text-decoration:none;transition:all 0.15s}
.btn-g:hover{border-color:var(--green);color:var(--green);text-decoration:none}

/* Node status panel */
.hero-right{background:var(--panel);border-left:1px solid var(--border2);display:flex;flex-direction:column;overflow:hidden}
.nsp-hd{padding:1rem;border-bottom:1px solid var(--border2);display:flex;align-items:center;justify-content:space-between}
.nsp-hd .live{font-family:var(--mono);font-size:0.62rem;color:var(--green);letter-spacing:0.1em}
.nsp-refresh{font-family:var(--mono);font-size:0.58rem;color:var(--t3);cursor:pointer;padding:0.2rem 0.5rem;border:1px solid var(--border2);background:none;color:var(--t3);transition:all 0.12s}
.nsp-refresh:hover{border-color:var(--green);color:var(--green)}
.nsp-live-banner{font-family:var(--mono);font-size:0.62rem;letter-spacing:0.08em;padding:0.5rem 1rem;background:var(--panel2);border-bottom:1px solid var(--border2)}
.nsp-live-banner.loading{color:var(--t3)}
.nsp-live-banner.ok{color:var(--green)}
.nsp-live-banner.error{color:var(--red)}
.live-nodes{flex:1;overflow-y:auto;padding:0.5rem 0}
.live-node-row{display:flex;align-items:center;gap:0.6rem;padding:0.4rem 1rem;font-family:var(--mono);font-size:0.7rem;border-bottom:1px solid var(--border)}
.ln-node{color:var(--amber);min-width:55px}
.ln-call{color:var(--t1);flex:1}
.ln-state{font-size:0.58rem;color:var(--t3)}
.nsp-ft{display:grid;grid-template-columns:repeat(3,1fr);border-top:1px solid var(--border2)}
.nft{padding:0.8rem;text-align:center;border-right:1px solid var(--border2)}
.nft:last-child{border-right:none}
.nft-v{display:block;font-family:var(--display);font-weight:700;font-size:1.3rem;color:var(--t1);line-height:1}
.nft-l{font-family:var(--mono);font-size:0.5rem;color:var(--t3);letter-spacing:0.12em;text-transform:uppercase;margin-top:0.2rem}

/* Stats bar */
.stats-bar{display:grid;grid-template-columns:repeat(4,1fr);background:var(--panel);border-top:1px solid var(--border2);border-bottom:1px solid var(--border2)}
.stat-item{padding:1.2rem;text-align:center;border-right:1px solid var(--border2)}
.stat-item:last-child{border-right:none}
.stat-v{font-family:var(--display);font-weight:800;font-size:2rem;line-height:1}
.stat-l{font-family:var(--mono);font-size:0.52rem;color:var(--t3);letter-spacing:0.12em;text-transform:uppercase;margin-top:0.2rem}

/* Ticker */
.ticker{display:flex;align-items:center;background:var(--panel2);border-bottom:1px solid var(--border2);overflow:hidden;height:32px}
.tick-lbl{font-family:var(--mono);font-size:0.52rem;color:var(--green);letter-spacing:0.15em;padding:0 1rem;white-space:nowrap;border-right:1px solid var(--border2);text-transform:uppercase}
.tick-scroll{overflow:hidden;flex:1}
.tick-inner{display:inline-flex;gap:0;white-space:nowrap;animation:ticker 40s linear infinite}
.tick-inner span{font-family:var(--mono);font-size:0.6rem;color:var(--t2);padding:0 0.5rem}
.tick-inner .hi{color:var(--t1)}
.tick-inner .sep{color:var(--border2)}
@keyframes ticker{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}

/* Node panel */
.node-panel{padding:1.5rem 5vw}
.node-panel-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1px;background:var(--border2);border:1px solid var(--border2)}
.node-row{background:var(--panel);padding:0.9rem 1rem;display:flex;align-items:center;gap:0.8rem}
.nr-call{font-family:var(--display);font-weight:700;font-size:0.85rem;color:var(--t1);min-width:80px}
.nr-sub{font-family:var(--mono);font-size:0.6rem;color:var(--amber);min-width:60px}
.nr-loc{flex:1;font-family:var(--mono);font-size:0.62rem;color:var(--t2)}
.nr-loc small{display:block;color:var(--t3);font-size:0.55rem;margin-top:0.1rem}
.nr-freq{font-family:var(--mono);font-size:0.7rem;color:var(--green);text-align:right}
.nr-freq small{display:block;color:var(--t3);font-size:0.55rem}
.s-live{color:var(--green)}.s-build{color:var(--amber)}.s-plan{color:var(--t3)}
.nr-st{font-family:var(--mono);font-size:0.55rem;text-transform:uppercase;letter-spacing:0.08em;min-width:55px;text-align:right}

/* Mission */
.mission{padding:5rem 5vw;background:var(--bg2)}
.sec-label{font-family:var(--mono);font-size:0.55rem;color:var(--green);letter-spacing:0.2em;text-transform:uppercase;margin-bottom:0.8rem}
.m-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:2rem;margin:2rem 0}
.m-card{border-left:2px solid var(--border2);padding-left:1.5rem}
.m-card:hover{border-left-color:var(--green)}
.m-card-num{font-family:var(--mono);font-size:0.55rem;color:var(--green);letter-spacing:0.15em;margin-bottom:0.5rem}
.m-card h3{font-family:var(--display);font-weight:700;font-size:1rem;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:0.6rem;color:var(--t1)}
.m-card p{font-size:0.85rem;color:var(--t2);line-height:1.7}
.m-quote{margin-top:3rem;border-left:3px solid var(--green);padding:0.8rem 1.5rem;background:var(--gglow);font-family:var(--mono);font-size:0.75rem;color:var(--green);line-height:1.6}

/* Network table */
.network{padding:5rem 5vw}
.sites-tbl{width:100%;border-collapse:collapse;font-size:0.8rem;margin-top:1.5rem}
.sites-tbl th{font-family:var(--mono);font-size:0.55rem;letter-spacing:0.12em;text-transform:uppercase;color:var(--t3);padding:0.6rem 0.8rem;border-bottom:1px solid var(--border2);text-align:left;background:var(--panel)}
.sites-tbl td{padding:0.65rem 0.8rem;border-bottom:1px solid var(--border);color:var(--t1);vertical-align:top}
.sites-tbl tr:hover td{background:var(--panel)}
.td-call{font-family:var(--display);font-weight:700;color:var(--t1)}
.td-sub{font-family:var(--mono);font-size:0.65rem;color:var(--amber)}
.td-loc small{display:block;font-size:0.68rem;color:var(--t3);margin-top:0.2rem}
.td-freq{font-family:var(--mono);font-size:0.75rem}
.td-node{font-family:var(--mono);font-size:0.7rem;color:var(--amber)}
.badge{font-family:var(--mono);font-size:0.58rem;letter-spacing:0.08em;padding:0.15rem 0.45rem}
.b-live{color:var(--green)}.b-build{color:var(--amber)}.b-plan{color:var(--t3)}.b-remote{color:#a78bfa}

/* Roadmap */
.roadmap-sec{padding:5rem 5vw;background:var(--bg2)}
.rm-phases{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:2rem;margin:2rem 0}
.rm-phase{background:var(--panel);border:1px solid var(--border2);padding:1.5rem}
.rm-phase-num{font-family:var(--display);font-weight:800;font-size:2.5rem;color:var(--border2);line-height:1;margin-bottom:0.3rem}
.rm-phase-tag{font-family:var(--mono);font-size:0.55rem;color:var(--green);letter-spacing:0.15em;text-transform:uppercase;margin-bottom:0.5rem}
.rm-phase h3{font-family:var(--display);font-weight:700;font-size:1rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--t1);margin-bottom:0.4rem}
.rm-phase-dates{font-family:var(--mono);font-size:0.62rem;color:var(--t3);margin-bottom:1rem}
.rm-items{list-style:none;margin-top:1rem}
.rm-items li{font-size:0.8rem;color:var(--t2);padding:0.3rem 0;display:flex;align-items:flex-start;gap:0.5rem;line-height:1.5}
.rm-items li::before{content:'○';color:var(--t3);font-size:0.6rem;margin-top:0.15rem;flex-shrink:0}
.rm-items li.done::before{content:'✓';color:var(--green)}
.rm-items li.prog::before{content:'◐';color:var(--amber)}

/* Team */
.team-sec{padding:5rem 5vw}
.tc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1.5rem;margin-top:2rem}
.tc-card{background:var(--panel);border:1px solid var(--border2);padding:1.5rem;display:flex;flex-direction:column;gap:0.4rem}
.tc-photo{width:60px;height:60px;object-fit:cover;border:1px solid var(--border2);margin-bottom:0.5rem}
.tc-photo-placeholder{width:60px;height:60px;background:var(--panel2);border:1px solid var(--border2);display:flex;align-items:center;justify-content:center;font-family:var(--display);font-weight:700;font-size:1.1rem;color:var(--t3);margin-bottom:0.5rem}
.tc-call{font-family:var(--display);font-weight:700;font-size:1rem;color:var(--amber)}
.tc-name{font-size:0.82rem;color:var(--t1)}
.tc-role{font-family:var(--mono);font-size:0.58rem;color:var(--t3);letter-spacing:0.08em;text-transform:uppercase}
.tc-loc{font-family:var(--mono);font-size:0.65rem;color:var(--t3)}
.tc-bio{font-size:0.78rem;color:var(--t2);line-height:1.6;margin-top:0.3rem}
.tc-node{font-family:var(--mono);font-size:0.62rem;color:var(--t3);margin-top:0.2rem}
.tc-node span{color:var(--green)}
.tc-foot{margin-top:auto;padding-top:0.8rem;display:flex;gap:0.6rem;flex-wrap:wrap}
.tc-link{font-family:var(--mono);font-size:0.58rem;color:var(--green);text-decoration:none;letter-spacing:0.08em;text-transform:uppercase}

/* Connect / CTA */
.cta{padding:5rem 5vw;background:var(--panel);border-top:1px solid var(--border2);text-align:center}
.cta-freq{font-family:var(--display);font-weight:800;font-size:clamp(1.5rem,4vw,3rem);color:var(--green);letter-spacing:0.06em}
.cta-sub{font-family:var(--mono);font-size:0.72rem;color:var(--t3);letter-spacing:0.1em;margin:0.5rem 0 1.5rem}
.cta p{font-size:0.88rem;color:var(--t2);margin-bottom:2rem;max-width:500px;margin-left:auto;margin-right:auto}
.btns{display:flex;gap:0.8rem;flex-wrap:wrap;justify-content:center;margin-top:1.5rem}

@media(max-width:900px){
    .hero{grid-template-columns:1fr}
    .hero-right{display:none}
    .stats-bar{grid-template-columns:repeat(2,1fr)}
    .sites-tbl{font-size:0.72rem}
    .sites-tbl th,.sites-tbl td{padding:0.5rem}
}
</style>

<main style="padding-top:46px">

<!-- HERO -->
<section class="hero" id="top" style="padding:0<?php if($img_hero): ?>;background-image:linear-gradient(to right, rgba(10,10,10,0.97) 55%, rgba(10,10,10,0.3)),url('<?= htmlspecialchars($img_hero) ?>');background-size:cover;background-position:center right<?php endif; ?>">
    <div class="hero-left">
        <div class="hero-eyebrow"><?= htmlspecialchars($org_name) ?> · <?= htmlspecialchars($callsign) ?> · Est. 2025</div>
        <h1 class="hero-h1">BUILT BY<br><em id="tw-word"></em><span id="tw-cursor" style="color:var(--green);animation:blink 0.7s step-end infinite">█</span><br><?= htmlspecialchars($org_name) ?></h1>
        <p class="hero-sub">A <strong>volunteer-built, RF-linked statewide backbone</strong> for hams who want to ragchew, build, break things, and fix them. No dues. No politics. Open spectrum. Real hardware.</p>
        <p style="font-family:var(--mono);font-size:0.65rem;color:var(--t3);margin-bottom:2rem;letter-spacing:0.04em">// Elmer the Elmers — passing real RF knowledge to the next generation</p>
        <div class="hero-btns">
            <a href="#network" class="btn-p">▸ VIEW NETWORK</a>
            <a href="#connect" class="btn-g">CONNECT TO TTN</a>
            <a href="<?= htmlspecialchars($gofundme) ?>" class="btn-g" target="_blank">SUPPORT TTN</a>
        </div>
    </div>
    <div class="hero-right">
        <div class="nsp-hd">
            <span class="live">◉ LIVE · NODE <?= htmlspecialchars($hub_node) ?></span>
            <button class="nsp-refresh" onclick="fetchNodeStatus(TTN_HUB_NODE)">↻ REFRESH</button>
        </div>
        <div class="nsp-live-banner loading" id="liveBanner">⟳ POLLING ALLSTARLINK...</div>
        <div class="live-nodes" id="liveNodes"></div>
        <div class="nsp-ft">
            <div class="nft"><span class="nft-v"><?= $site_count ?></span><div class="nft-l">Sites</div></div>
            <div class="nft"><span class="nft-v" id="ftConnected">—</span><div class="nft-l">Connected</div></div>
            <div class="nft"><span class="nft-v"><?= htmlspecialchars($hub_node) ?></span><div class="nft-l">Hub Node</div></div>
        </div>
    </div>
</section>

<!-- STATS BAR -->
<div class="stats-bar">
    <div class="stat-item"><div class="stat-v" style="color:var(--t1)"><?= $site_count ?></div><div class="stat-l">Sites Total</div></div>
    <div class="stat-item"><div class="stat-v" style="color:var(--green)"><?= $live_count ?></div><div class="stat-l">Live</div></div>
    <div class="stat-item"><div class="stat-v" style="color:var(--amber)"><?= $build_count ?></div><div class="stat-l">Building</div></div>
    <div class="stat-item"><div class="stat-v" style="color:var(--t3)"><?= $planned_count ?></div><div class="stat-l">Planned</div></div>
</div>

<!-- TICKER -->
<div class="ticker">
    <div class="tick-lbl">TTN NET</div>
    <div class="tick-scroll"><div class="tick-inner">
    <?php foreach ($sites as $n): if (!$n['primary_tx'] || $n['primary_tx'] == '0.0000') continue; ?>
    <span class="hi"><?= htmlspecialchars($n['primary_tx']) ?><?= $n['primary_rx'] ? '/'.$n['primary_rx'] : '' ?><?= $n['primary_pl'] ? ' PL '.$n['primary_pl'] : '' ?> · <?= htmlspecialchars($n['name']) ?><?= $n['sys_callsign'] ? ' · '.$n['sys_callsign'] : '' ?></span><span class="sep">|</span>
    <?php endforeach; ?>
    <span class="hi">ALLSTARLINK HUB · NODE <?= htmlspecialchars($hub_node) ?> · HUB.TTN.RADIO</span><span class="sep">|</span>
    <span>NO DUES · NO POLITICS · OPEN TO ALL LICENSED HAMS</span><span class="sep">|</span>
    <span>TTN · EIN <?= htmlspecialchars($ein) ?> · TN 501(c)(3)</span><span class="sep">|</span>
    <span class="hi">ELMER THE ELMERS — PASS IT ON</span><span class="sep">|</span>
    <?php foreach ($sites as $n): if (!$n['primary_tx'] || $n['primary_tx'] == '0.0000') continue; ?>
    <span class="hi"><?= htmlspecialchars($n['primary_tx']) ?><?= $n['primary_rx'] ? '/'.$n['primary_rx'] : '' ?><?= $n['primary_pl'] ? ' PL '.$n['primary_pl'] : '' ?> · <?= htmlspecialchars($n['name']) ?><?= $n['sys_callsign'] ? ' · '.$n['sys_callsign'] : '' ?></span><span class="sep">|</span>
    <?php endforeach; ?>
    <span class="hi">ALLSTARLINK HUB · NODE <?= htmlspecialchars($hub_node) ?> · HUB.TTN.RADIO</span><span class="sep">|</span>
    <span>NO DUES · NO POLITICS · OPEN TO ALL LICENSED HAMS</span><span class="sep">|</span>
    <span>TTN · EIN <?= htmlspecialchars($ein) ?> · TN 501(c)(3)</span><span class="sep">|</span>
    <span class="hi">ELMER THE ELMERS — PASS IT ON</span><span class="sep">|</span>
    </div></div>
</div>

<!-- SITE PANEL -->
<div class="node-panel">
    <div class="node-panel-grid">
    <?php foreach ($sites as $n):
        $st_class = 's-plan'; $st_label = '○ PLAN';
        if ($n['status'] === 'live')     { $st_class = 's-live'; $st_label = '● LIVE'; }
        if ($n['status'] === 'building') { $st_class = 's-build'; $st_label = '◐ BUILD'; }
    ?>
    <div class="node-row">
        <div class="nr-call"><?= htmlspecialchars($n['name']) ?></div>
        <?php if ($n['sys_callsign']): ?>
        <div class="nr-sub"><?= htmlspecialchars($n['sys_callsign']) ?></div>
        <?php endif; ?>
        <div class="nr-loc"><?= htmlspecialchars($n['city'] ?? '') ?><?= $n['state'] ? ', '.$n['state'] : '' ?>
            <small><?= $n['tower_height_ft'] ? $n['tower_height_ft'].'ft' : '' ?><?= $n['power_primary'] ? ' · '.$n['power_primary'] : '' ?></small>
        </div>
        <?php if ($n['primary_tx'] && $n['primary_tx'] != '0.0000'): ?>
        <div class="nr-freq"><?= htmlspecialchars($n['primary_tx']) ?><?= $n['primary_rx'] ? '/'.$n['primary_rx'] : '' ?><small><?= $n['primary_pl'] ? 'PL '.$n['primary_pl'] : '' ?></small></div>
        <?php else: ?>
        <div class="nr-freq" style="color:var(--t3)">TBD</div>
        <?php endif; ?>
        <div class="nr-st <?= $st_class ?>"><?= $st_label ?></div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<!-- MISSION -->
<section class="mission" id="mission">
    <div class="sec-label">Mission</div>
    <h2 style="font-family:var(--display);font-weight:700;font-size:1.8rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--t1)">The Technological Community</h2>
    <div class="m-grid">
        <div class="m-card"><div class="m-card-num">PILLAR 01</div><h3>Elmer the Elmers</h3><p>A teachers lounge for the advanced technical crowd. Preserving duplexer tuning, RF propagation, tower work, and repeater-building knowledge — the tacit stuff that goes silent key with the old guard if we don't capture it now.</p></div>
        <div class="m-card"><div class="m-card-num">PILLAR 02</div><h3>Freedom to Tinker</h3><p>Protecting the right of every licensed ham to experiment, break things, and build on open RF systems — without commercial gatekeeping, club politics, or a dues card to prove you belong.</p></div>
        <div class="m-card"><div class="m-card-num">PILLAR 03</div><h3>Decentralized Resilience</h3><p>A self-healing, outage-proof 6m backbone that routes around damage — RF-only when the internet fails. No single point of failure. No commercial dependency. Built by engineers, for engineers.</p></div>
    </div>
    <div class="m-quote">"The Net interprets censorship as damage and routes around it." — TTN doesn't wait for permission to communicate.</div>
</section>

<!-- NETWORK TABLE -->
<section class="network" id="network">
    <div class="sec-label">Network</div>
    <h2 style="font-family:var(--display);font-weight:700;font-size:1.8rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--t1)">Phase 1 Sites — <?= $site_count ?> Sites</h2>
    <table class="sites-tbl">
        <thead><tr><th>Site</th><th>Callsign</th><th>Location</th><th>Frequency</th><th>AllStar</th><th>Tower</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($sites as $n):
            $all_sys = db_rows("SELECT * FROM systems WHERE site_id=? AND is_public=1 ORDER BY sort_order", [$n['id']]);
            $all_asl = db_rows("SELECT sa.asl_number, sa.is_hub FROM sys_asl sa JOIN systems s ON s.id=sa.system_id WHERE s.site_id=? ORDER BY sa.is_hub DESC, sa.asl_number", [$n['id']]);
            $badge = match($n['status'] ?? 'planned') {
                'live'     => '<span class="badge b-live">● LIVE</span>',
                'building' => '<span class="badge b-build">◐ BUILDING</span>',
                default    => '<span class="badge b-plan">○ PLANNED</span>',
            };
        ?>
        <tr>
            <td class="td-call"><?= htmlspecialchars($n['name']) ?></td>
            <td class="td-sub"><?= htmlspecialchars($n['sys_callsign'] ?? '') ?></td>
            <td class="td-loc"><?= htmlspecialchars($n['name']) ?><?= $n['city'] ? ', '.htmlspecialchars($n['city']) : '' ?><?= $n['state'] ? ', '.$n['state'] : '' ?>
                <small><?= $n['power_primary'] ? htmlspecialchars($n['power_primary']) : '' ?><?= $n['erp_watts'] ? ' · '.htmlspecialchars($n['erp_watts']).'W ERP' : '' ?></small>
            </td>
            <td class="td-freq">
                <?php foreach ($all_sys as $sys): if (!$sys['freq_tx'] || $sys['freq_tx']=='0.0000') continue; ?>
                <?= htmlspecialchars($sys['freq_tx']) ?><?= $sys['freq_rx'] ? '/'.htmlspecialchars($sys['freq_rx']) : '' ?><?= $sys['access_code'] ? ' PL'.htmlspecialchars($sys['access_code']) : '' ?>
                <?php if ($sys['label']): ?><small><?= htmlspecialchars($sys['label']) ?></small><?php endif; ?>&nbsp;
                <?php endforeach; ?>
                <?php if (empty($all_sys)): ?>TBD<?php endif; ?>
            </td>
            <td class="td-node">
                <?php if (!empty($all_asl)): ?>
                <?= implode(' / ', array_map(fn($a) => htmlspecialchars($a['asl_number']), $all_asl)) ?>
                <?php else: ?>TBD<?php endif; ?>
            </td>
            <td style="font-family:var(--mono);font-size:0.72rem;color:var(--t2)"><?= $n['tower_height_ft'] ? $n['tower_height_ft'].'ft' : '' ?><?= $n['tower_type'] ? ' '.htmlspecialchars($n['tower_type']) : '' ?></td>
            <td><?= $badge ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<!-- ROADMAP -->
<section class="roadmap-sec" id="roadmap">
    <div class="sec-label">Roadmap</div>
    <h2 style="font-family:var(--display);font-weight:700;font-size:1.8rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--t1)">Three-Phase Build</h2>
    <div class="rm-phases">
    <?php foreach ($roadmap_by_phase as $phase => $items):
        $phase_meta = ['1'=>['PHASE 01 · ACTIVE','Rapid Coverage','NOW — 2026','var(--green)'],
                       '2'=>['PHASE 02','RF Linking','2026 — 2029','var(--amber)'],
                       '3'=>['PHASE 03','Zero Internet','2029+','var(--t3)']];
        $pm = $phase_meta[$phase] ?? ['PHASE '.$phase,'','','var(--t3)'];
    ?>
    <div class="rm-phase">
        <div class="rm-phase-num" style="color:<?= $pm[3] ?>"><?= $phase ?></div>
        <div class="rm-phase-tag" style="color:<?= $pm[3] ?>"><?= $pm[0] ?></div>
        <h3><?= htmlspecialchars($pm[1]) ?></h3>
        <div class="rm-phase-dates"><?= $pm[2] ?></div>
        <ul class="rm-items">
        <?php foreach ($items as $item):
            $cls = $item['status'] === 'done' ? 'done' : ($item['status'] === 'in_progress' ? 'prog' : '');
        ?>
        <li class="<?= $cls ?>"><span><?= htmlspecialchars($item['title']) ?><?= $item['description'] ? ' <span style="color:var(--t3);font-size:0.72rem">— '.$item['description'].'</span>' : '' ?></span></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endforeach; ?>
    </div>
</section>

<!-- TEAM -->
<section class="team-sec" id="team">
    <div class="sec-label">Team</div>
    <h2 style="font-family:var(--display);font-weight:700;font-size:1.8rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--t1)">100+ Years Combined RF Experience</h2>
    <div class="tc-grid">
    <?php foreach ($team as $m):
        $crew_sites = db_rows("SELECT si.name, si.id FROM site_crew sc JOIN sites si ON si.id=sc.site_id WHERE sc.operator_id=? AND sc.approved=1 ORDER BY si.name", [$m['id']]);
        $all_asls   = [];
        foreach ($crew_sites as $cs) {
            $asls = db_rows("SELECT sa.asl_number FROM sys_asl sa JOIN systems s ON s.id=sa.system_id WHERE s.site_id=? ORDER BY sa.is_hub DESC", [$cs['id']]);
            foreach ($asls as $a) $all_asls[] = $a['asl_number'];
        }
    ?>
    <div class="tc-card">
        <?php if (!empty($m['photo_url'])): ?>
        <img src="<?= htmlspecialchars($m['photo_url']) ?>" alt="<?= htmlspecialchars($m['callsign']) ?>" class="tc-photo">
        <?php else: ?>
        <div class="tc-photo-placeholder"><?= htmlspecialchars(substr($m['callsign'],0,2)) ?></div>
        <?php endif; ?>
        <div class="tc-call"><?= htmlspecialchars($m['callsign']) ?></div>
        <div class="tc-name"><?= htmlspecialchars($m['display_name'] ?: $m['callsign']) ?></div>
        <?php if (!empty($crew_sites)): ?>
        <div class="tc-role"><?= htmlspecialchars($crew_sites[0]['name']) ?></div>
        <?php endif; ?>
        <?php if ($m['city'] || $m['state']): ?>
        <div class="tc-loc">📍 <?= htmlspecialchars(trim(($m['city']??'').($m['state'] ? ', '.$m['state'] : ''))) ?></div>
        <?php endif; ?>
        <?php if ($m['bio']): ?><div class="tc-bio"><?= nl2br(htmlspecialchars($m['bio'])) ?></div><?php endif; ?>
        <?php if (!empty($crew_sites) || !empty($all_asls)): ?>
        <div class="tc-node">
            <?php if (!empty($crew_sites)): ?>Site: <?php foreach($crew_sites as $i=>$cs): ?><span><?= htmlspecialchars($cs['name']) ?></span><?= $i<count($crew_sites)-1?' · ':'' ?><?php endforeach; ?><?php endif; ?>
            <?php if (!empty($all_asls)): ?> · ASL: <?php foreach($all_asls as $i=>$a): ?><span><?= htmlspecialchars($a) ?></span><?= $i<count($all_asls)-1?' ':'' ?><?php endforeach; ?><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="tc-foot">
            <?php if ($m['qrz_url']): ?><a href="<?= htmlspecialchars($m['qrz_url']) ?>" target="_blank" class="tc-link">QRZ ↗</a><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</section>

<!-- CONNECT -->
<section class="cta" id="connect">
    <?php $hub_sys = db_rows("SELECT * FROM systems WHERE site_id=(SELECT site_id FROM systems WHERE freq_tx=? LIMIT 1) AND is_public=1 ORDER BY sort_order LIMIT 1", [$hub_freq]); $hf = $hub_sys[0] ?? null; ?>
    <?php if ($hf && $hf['freq_tx'] && $hf['freq_tx'] != '0.0000'): ?>
    <div class="cta-freq"><?= htmlspecialchars($hf['freq_tx']) ?> / <?= htmlspecialchars($hf['freq_rx'] ?? '') ?></div>
    <div class="cta-sub">PL <?= htmlspecialchars($hf['access_code'] ?? '') ?> · AllStar <?= htmlspecialchars($hub_node) ?> · New Market TN</div>
    <?php else: ?>
    <div class="cta-freq"><?= htmlspecialchars($hub_freq) ?></div>
    <div class="cta-sub">AllStar <?= htmlspecialchars($hub_node) ?> · New Market TN</div>
    <?php endif; ?>
    <p>No membership. No dues. A valid license and a radio. Connect from anywhere via AllStarLink node HUB.TTN.RADIO.</p>
    <div class="btns">
        <a href="<?= htmlspecialchars($hub_url) ?>/allscan" class="btn-p" target="_blank">▸ ALLSCAN</a>
        <a href="<?= htmlspecialchars($gofundme) ?>" class="btn-g" target="_blank">GOFUNDME</a>
        <?php if ($paypal_url): ?><a href="<?= htmlspecialchars($paypal_url) ?>" class="btn-g" target="_blank">PAYPAL</a><?php endif; ?>
        <?php if ($facebook): ?><a href="<?= htmlspecialchars($facebook) ?>" class="btn-g" target="_blank">FACEBOOK</a><?php endif; ?>
    </div>
</section>

</main>

<?php require_once TTN_INCLUDES . '/footer.php'; ?>

<script>
const HUB_NODE       = TTN_HUB_NODE;
const HUB_LAST_KEYED = <?= $hub_last_keyed ? '"' . htmlspecialchars(time_ago($hub_last_keyed)) . '"' : 'null' ?>;

async function fetchNodeStatus(node) {
    const banner    = document.getElementById('liveBanner');
    const liveNodes = document.getElementById('liveNodes');
    const ftConn    = document.getElementById('ftConnected');
    if (!banner) return;
    banner.className = 'nsp-live-banner loading';
    banner.textContent = '⟳ POLLING NODE SERVER...';

    try {
        const res = await fetch(TTN_STATUS_URL + '?node=' + node, {
            signal: AbortSignal.timeout(8000)
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (!data.ok) throw new Error('Node not responding');

        const count = data.conn_count ?? 0;
        if (ftConn) ftConn.textContent = count;
        banner.className = 'nsp-live-banner ok';
        banner.textContent = count + ' NODES CONNECTED';

        if (liveNodes) {
            const conns = data.connections || [];
            if (conns.length) {
                liveNodes.innerHTML = conns.map(n => {
                    const num  = n.node || n.nodenum || '—';
                    const call = n.callsign || n.call || '';
                    const state= n.state || '';
                    return `<div class="live-node-row"><span class="ln-node">${num}</span><span class="ln-call">${call}</span><span class="ln-state">${state}</span></div>`;
                }).join('');
            } else {
                const lh = HUB_LAST_KEYED ? `<span style="color:var(--t3)"> · last heard ${HUB_LAST_KEYED}</span>` : '';
                liveNodes.innerHTML = `<div style="padding:1rem;font-family:var(--mono);font-size:0.65rem;color:var(--t3)">Hub online · No active connections${lh}</div>`;
            }
        }
    } catch(e) {
        banner.className = 'nsp-live-banner error';
        banner.textContent = '✕ UNABLE TO REACH NODE SERVER';
        if (liveNodes) {
            const lh = HUB_LAST_KEYED ? ` · last heard ${HUB_LAST_KEYED}` : '';
            liveNodes.innerHTML = `<div style="padding:1rem;font-family:var(--mono);font-size:0.65rem;color:var(--t3)">Connect: AllStar ${node}${lh}</div>`;
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    fetchNodeStatus(HUB_NODE);
    setInterval(() => fetchNodeStatus(HUB_NODE), 60000);
});

// ── TYPEWRITER ───────────────────────────────────────────────
(function() {
    const words = [
        'ENGINEERS.','BUILDERS.','ELMERS.','OPERATORS.',
        'CLIMBERS.','HACKERS.','VOLUNTEERS.','DREAMERS.',
        'MAKERS.','SURVIVORS.','YOU.','HAMS.'
    ];
    const el     = document.getElementById('tw-word');
    const cursor = document.getElementById('tw-cursor');
    if (!el) return;

    let wi = 0, ci = 0, deleting = false, paused = false;
    const TYPE_SPEED   = 80;
    const DELETE_SPEED = 40;
    const PAUSE_END    = 1800;
    const PAUSE_START  = 300;

    function tick() {
        const word = words[wi];
        if (paused) { paused = false; setTimeout(tick, deleting ? PAUSE_START : PAUSE_END); return; }

        if (!deleting) {
            el.textContent = word.slice(0, ++ci);
            if (ci === word.length) { paused = true; deleting = true; }
            setTimeout(tick, paused ? PAUSE_END : TYPE_SPEED);
        } else {
            el.textContent = word.slice(0, --ci);
            if (ci === 0) { deleting = false; wi = (wi + 1) % words.length; paused = true; }
            setTimeout(tick, paused ? PAUSE_START : DELETE_SPEED);
        }
    }
    // Start after a short delay
    setTimeout(tick, 600);
})();

// CW easter egg handled entirely by ttn.js
</script>
