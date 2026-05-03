<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';

$page_title   = 'Network Sites';
$page_section = 'network';

$sites = db_rows("
    SELECT si.*,
        si.power_primary AS power_type,
        sys.callsign     AS sys_callsign,
        sys.erp_watts,
        sys.freq_tx      AS primary_tx,
        sys.freq_rx      AS primary_rx,
        sys.access_code  AS primary_pl,
        sys.band         AS primary_band,
        sa.asl_number    AS hub_asl,
        COUNT(DISTINCT all_sys.id) AS system_count
    FROM sites si
    LEFT JOIN systems sys     ON sys.site_id = si.id AND sys.sort_order = 0
    LEFT JOIN sys_asl sa      ON sa.system_id = sys.id AND sa.is_hub = 1
    LEFT JOIN systems all_sys ON all_sys.site_id = si.id AND all_sys.is_public = 1
    WHERE si.is_public = 1
    GROUP BY si.id
    ORDER BY FIELD(si.status,'live','building','planned','offline'), si.phase, si.name
");

$counts = ['live'=>0,'building'=>0,'planned'=>0,'offline'=>0];
foreach ($sites as $s) if (isset($counts[$s['status']])) $counts[$s['status']]++;

// Last heard per site — most recent sys_telemetry.last_keyed_at across all systems at each site
$site_last_heard = [];
try {
    $rows = db_rows("
        SELECT s.site_id, MAX(st.last_keyed_at) AS last_keyed
        FROM sys_telemetry st
        JOIN systems s ON s.id = st.system_id
        WHERE st.last_keyed_at IS NOT NULL
        GROUP BY s.site_id
    ");
    foreach ($rows as $r) $site_last_heard[$r['site_id']] = $r['last_keyed'];
} catch (Exception $e) {}

function time_ago(?string $dt): string {
    if (!$dt) return '';
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return $diff . 's ago';
    if ($diff < 3600)  return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    return floor($diff/86400) . 'd ago';
}

function site_badge(string $s): string {
    return match($s) {
        'live'     => '<span class="badge b-live">● LIVE</span>',
        'building' => '<span class="badge b-build">◐ BUILDING</span>',
        'planned'  => '<span class="badge b-plan">○ PLANNED</span>',
        default    => '<span class="badge b-off">✕ OFFLINE</span>',
    };
}

$extra_head = '<style>
.sites-hd{padding:3rem 5vw 1.5rem;border-bottom:1px solid var(--border2)}
.sites-filters{display:flex;gap:0.5rem;padding:1rem 5vw;flex-wrap:wrap;border-bottom:1px solid var(--border2)}
.filter-btn{font-family:var(--mono);font-size:0.6rem;letter-spacing:0.1em;text-transform:uppercase;padding:0.4rem 1rem;border:1px solid var(--border2);background:transparent;color:var(--t2);cursor:pointer;transition:all 0.12s;text-decoration:none}
.filter-btn:hover,.filter-btn.active{border-color:var(--green);color:var(--green);background:var(--gglow)}
.sites-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1px;background:var(--border2);padding:1px}
.nc{background:var(--panel);display:flex;flex-direction:column;cursor:pointer;transition:background 0.12s}
.nc:hover{background:var(--panel2)}
.nc-photo{width:100%;height:140px;object-fit:cover;display:block}
.nc-photo-placeholder{width:100%;height:140px;background:var(--panel2);display:flex;align-items:center;justify-content:center;font-family:var(--display);font-weight:800;font-size:2.5rem;color:var(--border2)}
.nc-body{padding:1rem;flex:1;display:flex;flex-direction:column;gap:0.3rem}
.nc-name{font-family:var(--display);font-weight:700;font-size:1rem;color:var(--t1);text-transform:uppercase;letter-spacing:0.04em}
.nc-sub{font-family:var(--mono);font-size:0.65rem;color:var(--amber)}
.nc-loc{font-family:var(--mono);font-size:0.65rem;color:var(--t3)}
.nc-freqs{margin-top:0.5rem;display:flex;flex-direction:column;gap:0.2rem}
.nc-freq{font-family:var(--mono);font-size:0.7rem;color:var(--green)}
.nc-freq .band{color:var(--t3);font-size:0.6rem;margin-left:0.3rem}
.nc-asl{font-family:var(--mono);font-size:0.62rem;color:var(--amber);margin-top:0.3rem}
.nc-ft{margin-top:auto;padding-top:0.8rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.3rem}
.nc-power{font-family:var(--mono);font-size:0.58rem;color:var(--t3)}
.nc-phase{font-family:var(--mono);font-size:0.55rem;color:var(--t3)}
.nc-link{font-family:var(--mono);font-size:0.6rem;color:var(--green);text-decoration:none;letter-spacing:0.08em;text-transform:uppercase}
.badge{font-family:var(--mono);font-size:0.55rem;letter-spacing:0.08em;padding:0.15rem 0.45rem}
.b-live{color:var(--green)}.b-build{color:var(--amber)}.b-plan{color:var(--t3)}.b-off{color:var(--red)}
.no-results{padding:3rem;text-align:center;font-family:var(--mono);font-size:0.75rem;color:var(--t3)}
</style>';

require_once TTN_INCLUDES . '/header.php';
?>
<main style="padding-top:46px">

<div class="sites-hd">
    <div style="font-family:var(--mono);font-size:0.55rem;color:var(--t3);letter-spacing:0.15em;text-transform:uppercase;margin-bottom:0.6rem">Network</div>
    <h1 style="font-family:var(--display);font-weight:700;font-size:1.8rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--t1);margin-bottom:0.5rem">Phase 1 · <?= count($sites) ?> Sites</h1>
    <p style="font-family:var(--mono);font-size:0.75rem;color:var(--t2);max-width:600px">AllStarLink-linked 6m repeater backbone across Tennessee and north Mississippi. Solar primary power. No dues, no politics. Open to all licensed hams.</p>
    <div style="display:flex;gap:1.5rem;margin-top:1rem;flex-wrap:wrap">
        <span style="font-family:var(--mono);font-size:0.65rem;color:var(--green)">● <?= $counts['live'] ?> Live</span>
        <span style="font-family:var(--mono);font-size:0.65rem;color:var(--amber)">◐ <?= $counts['building'] ?> Building</span>
        <span style="font-family:var(--mono);font-size:0.65rem;color:var(--t3)">○ <?= $counts['planned'] ?> Planned</span>
        <span id="connCount" style="font-family:var(--mono);font-size:0.65rem;color:var(--t3)">— Connected Now</span>
    </div>
</div>

<div class="sites-filters">
    <a href="#" class="filter-btn active" data-filter="all" onclick="filterSites('all',this);return false">All Sites</a>
    <a href="#" class="filter-btn" data-filter="live"     onclick="filterSites('live',this);return false">Live</a>
    <a href="#" class="filter-btn" data-filter="building" onclick="filterSites('building',this);return false">Building</a>
    <a href="#" class="filter-btn" data-filter="planned"  onclick="filterSites('planned',this);return false">Planned</a>
    <a href="#" class="filter-btn" data-filter="6m"       onclick="filterSites('6m',this);return false">6m Nodes</a>
    <a href="#" class="filter-btn" data-filter="TN"       onclick="filterSites('TN',this);return false">Tennessee</a>
    <a href="#" class="filter-btn" data-filter="MS"       onclick="filterSites('MS',this);return false">Mississippi</a>
</div>

<div class="sites-grid" id="sitesGrid">
<?php foreach ($sites as $site):
    $sys_list = db_rows("SELECT * FROM systems WHERE site_id=? AND is_public=1 ORDER BY sort_order", [$site['id']]);
    $asl_list = db_rows("SELECT sa.asl_number, sa.is_hub FROM sys_asl sa JOIN systems s ON s.id=sa.system_id WHERE s.site_id=? ORDER BY sa.is_hub DESC, sa.asl_number", [$site['id']]);
    $has_6m   = false;
    foreach ($sys_list as $sys) if ($sys['band'] === '6m') $has_6m = true;
?>
<div class="nc"
    data-status="<?= htmlspecialchars($site['status']) ?>"
    data-band="<?= $has_6m ? '6m' : '' ?>"
    data-state="<?= htmlspecialchars($site['state'] ?? '') ?>">
    <?php if (!empty($site['photo_url'])): ?>
    <img src="<?= htmlspecialchars($site['photo_url']) ?>" alt="<?= htmlspecialchars($site['name']) ?>" class="nc-photo" loading="lazy">
    <?php else: ?>
    <div class="nc-photo-placeholder"><?= htmlspecialchars(strtoupper(substr($site['name'],0,3))) ?></div>
    <?php endif; ?>
    <div class="nc-body">
        <div class="nc-name"><?= htmlspecialchars($site['name']) ?></div>
        <?php if ($site['sys_callsign']): ?>
        <div class="nc-sub"><?= htmlspecialchars($site['sys_callsign']) ?></div>
        <?php endif; ?>
        <div class="nc-loc"><?= htmlspecialchars($site['city'] ?? '') ?><?= $site['state'] ? ', '.$site['state'] : '' ?><?= $site['tower_height_ft'] ? ' · '.$site['tower_height_ft'].'ft' : '' ?></div>

        <?php if (!empty($sys_list)): ?>
        <div class="nc-freqs">
        <?php foreach ($sys_list as $sys): if (!$sys['freq_tx'] || $sys['freq_tx']=='0.0000') continue; ?>
        <div class="nc-freq">
            <?= htmlspecialchars($sys['band'] ?? '') ?>
            <?= htmlspecialchars($sys['freq_tx']) ?><?= $sys['freq_rx'] ? '/'.htmlspecialchars($sys['freq_rx']) : '' ?>
            <?= $sys['access_code'] ? '<span style="color:var(--t3)">PL '.htmlspecialchars($sys['access_code']).'</span>' : '' ?>
            <?php if ($sys['label']): ?><span class="band"><?= htmlspecialchars($sys['label']) ?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($asl_list)): ?>
        <div class="nc-asl">ASL: <?= implode('· ', array_map(fn($a) => htmlspecialchars($a['asl_number']), $asl_list)) ?></div>
        <?php endif; ?>

        <?php if ($site['power_primary']): ?>
        <div class="nc-power">⚡ <?= htmlspecialchars($site['power_primary']) ?></div>
        <?php endif; ?>

        <div class="nc-ft">
            <div><?= site_badge($site['status']) ?><span style="font-family:var(--mono);font-size:0.55rem;color:var(--t3);margin-left:0.4rem">Phase <?= $site['phase'] ?> · <?= $site['system_count'] ?> System<?= $site['system_count']!=1?'s':'' ?></span></div>
            <?php
            $lh = $site_last_heard[$site['id']] ?? null;
            if ($lh): ?>
            <span style="font-family:var(--mono);font-size:0.55rem;color:var(--t3)">heard <?= time_ago($lh) ?></span>
            <?php endif; ?>
            <a href="<?= s('site_url') ?>/sites/detail/?site=<?= $site['id'] ?>" class="nc-link">Details ›</a>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<div class="no-results" id="noResults" style="display:none">No sites match this filter.</div>

</main>
<?php require_once TTN_INCLUDES . '/footer.php'; ?>

<script>
function filterSites(filter, btn) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    let shown = 0;
    document.querySelectorAll('.nc').forEach(card => {
        let show = filter === 'all'
            || card.dataset.status === filter
            || card.dataset.band   === filter
            || card.dataset.state  === filter;
        card.style.display = show ? '' : 'none';
        if (show) shown++;
    });
    document.getElementById('noResults').style.display = shown ? 'none' : 'block';
}

window.onNodeStatus = function(data) {
    const el = document.getElementById('connCount');
    if (el && data.conn_count !== undefined) el.textContent = data.conn_count + ' Connected Now';
};
</script>
