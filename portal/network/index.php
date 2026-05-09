<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';

$page_title  = 'Network';
$site_url    = s('site_url',    'https://dev.ttn.radio');
$img_coverage= s('img_coverage','/uploads/estimate_coverage_map.png');
$org_name    = s('org_name',    'Tennessee Technological Community');

$sites = db_rows("
    SELECT si.*,
        sys.callsign    AS sys_callsign,
        sys.freq_tx     AS primary_tx,
        sys.freq_rx     AS primary_rx,
        sys.access_code AS primary_pl,
        sys.band        AS primary_band,
        sa.asl_number   AS hub_asl
    FROM sites si
    LEFT JOIN systems sys ON sys.site_id = si.id AND sys.sort_order = 0
    LEFT JOIN sys_asl sa  ON sa.system_id = sys.id AND sa.is_hub = 1
    WHERE si.is_public = 1
    ORDER BY FIELD(si.status,'live','building','planned','offline'), si.phase, si.name
");

$live_count    = count(array_filter($sites, fn($s) => $s['status'] === 'live'));
$build_count   = count(array_filter($sites, fn($s) => $s['status'] === 'building'));
$planned_count = count(array_filter($sites, fn($s) => $s['status'] === 'planned'));
$total_count   = count($sites);
$mapped_sites  = array_filter($sites, fn($s) => $s['lat'] && $s['lng']);

$extra_head = '
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<style>
.net-hero{padding:5rem 5vw 3rem;background:var(--bg2);border-bottom:1px solid var(--border2)}
.net-stats{display:flex;gap:2rem;flex-wrap:wrap;margin-top:1.5rem}
.net-stat{font-family:var(--mono)}
.net-stat-v{font-size:2rem;color:var(--green);display:block;line-height:1}
.net-stat-v.amber{color:var(--amber)}
.net-stat-v.dim{color:var(--t3)}
.net-stat-l{font-size:0.62rem;letter-spacing:0.15em;text-transform:uppercase;color:var(--t3)}
.coverage-wrap{background:var(--bg);border-top:1px solid var(--border2);border-bottom:1px solid var(--border2);padding:3rem 5vw}
.coverage-img{width:100%;max-width:960px;display:block;margin:0 auto;border:1px solid var(--border2);filter:brightness(0.9) saturate(0.85)}
.coverage-cap{font-family:var(--mono);font-size:0.62rem;color:var(--t3);letter-spacing:0.1em;text-align:center;margin-top:0.8rem;text-transform:uppercase}
.map-wrap{background:var(--bg2);border-top:1px solid var(--border2);border-bottom:1px solid var(--border2);padding:3rem 5vw}
#ttn-map{height:480px;border:1px solid var(--border2);width:100%}
.leaflet-popup-content-wrapper{background:var(--panel);border:1px solid var(--border2);border-radius:0;color:var(--t1);font-family:var(--mono);font-size:0.75rem;box-shadow:0 4px 20px rgba(0,0,0,0.5)}
.leaflet-popup-tip{background:var(--panel)}
.leaflet-popup-content{margin:0.8rem 1rem}
.pop-call{color:var(--amber);font-size:0.85rem;font-weight:700}
.pop-freq{color:var(--green);margin:0.2rem 0}
.pop-status{font-size:0.62rem;letter-spacing:0.1em;text-transform:uppercase;margin-top:0.3rem}
.pop-live{color:var(--green)}.pop-build{color:var(--amber)}.pop-plan{color:var(--t3)}
.sites-sec{padding:4rem 5vw;background:var(--bg)}
.sites-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1px;background:var(--border2);border:1px solid var(--border2);margin-top:2rem}
.site-card{background:var(--panel);padding:1.4rem;transition:background 0.2s;position:relative;overflow:hidden}
.site-card:hover{background:var(--panel2)}
.site-card::before{content:"";position:absolute;top:0;left:0;right:0;height:2px}
.site-card.live::before{background:var(--green)}
.site-card.building::before{background:var(--amber)}
.site-card.planned::before{background:var(--border2)}
.sc-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:0.8rem}
.sc-call{font-family:var(--mono);font-size:0.85rem;color:var(--amber)}
.sc-name{font-family:var(--display);font-weight:700;font-size:1rem;color:var(--t1);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.15rem}
.sc-loc{font-family:var(--mono);font-size:0.68rem;color:var(--t3);letter-spacing:0.06em;margin-bottom:0.8rem}
.sc-freq{font-family:var(--mono);font-size:0.82rem;color:var(--t2);margin-bottom:0.3rem}
.sc-asl{font-family:var(--mono);font-size:0.68rem;color:var(--t3)}
.sc-asl span{color:var(--t2)}
.phase-sec{padding:4rem 5vw;background:var(--bg2);border-top:1px solid var(--border2)}
.phase-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--border2);border:1px solid var(--border2);margin-top:2rem}
.ph-card{background:var(--panel);padding:1.8rem;position:relative;overflow:hidden}
.ph-card.now{background:rgba(0,230,118,0.02)}
.ph-bg{position:absolute;bottom:-1rem;right:-0.5rem;font-family:var(--display);font-weight:800;font-size:8rem;line-height:1;color:var(--border);pointer-events:none;user-select:none}
.ph-n{font-family:var(--mono);font-size:0.58rem;color:var(--green);letter-spacing:0.2em;margin-bottom:0.4rem}
.ph-t{font-family:var(--display);font-weight:800;font-size:1.2rem;color:var(--t1);text-transform:uppercase;line-height:1.1;margin-bottom:0.3rem}
.ph-y{font-family:var(--mono);font-size:0.65rem;color:var(--amber);letter-spacing:0.08em;margin-bottom:1rem}
.ph-items{list-style:none;display:flex;flex-direction:column;gap:0.35rem;position:relative;z-index:1}
.ph-items li{font-size:0.9rem;color:var(--t2);padding-left:1rem;position:relative;line-height:1.4}
.ph-items li::before{content:"›";position:absolute;left:0;color:var(--green)}
@media(max-width:768px){
  .net-stats{gap:1.2rem}
  .phase-grid{grid-template-columns:1fr}
  .coverage-wrap,.map-wrap,.sites-sec,.phase-sec{padding:2.5rem 1.5rem}
  #ttn-map{height:320px}
}
</style>';

require_once TTN_INCLUDES . '/header.php';
?>

<!-- HERO -->
<section class="net-hero">
    <div class="sec-label">TTN · Phase 1</div>
    <h2 style="margin-bottom:0.5rem">Tennessee Network</h2>
    <p style="font-size:1.05rem;color:var(--t2);max-width:580px;line-height:1.7;margin-bottom:1.5rem">
        Volunteer-built 6-meter FM backbone linking AllStarLink nodes across Tennessee.
        Solar-primary sites, GPS-disciplined timing, sovereign infrastructure.
    </p>
    <div class="net-stats">
        <div class="net-stat"><span class="net-stat-v"><?= $live_count ?></span><span class="net-stat-l">Live Sites</span></div>
        <div class="net-stat"><span class="net-stat-v amber"><?= $build_count ?></span><span class="net-stat-l">Building</span></div>
        <div class="net-stat"><span class="net-stat-v dim"><?= $planned_count ?></span><span class="net-stat-l">Planned</span></div>
        <div class="net-stat"><span class="net-stat-v" style="color:var(--blue)"><?= $total_count ?></span><span class="net-stat-l">Total Sites</span></div>
    </div>
</section>

<!-- COVERAGE MAP -->
<section class="coverage-wrap">
    <div class="sec-label">Coverage</div>
    <h2 style="margin-bottom:1.5rem">Estimated Coverage — Phase 1</h2>
    <img class="coverage-img" src="<?= htmlspecialchars($img_coverage) ?>" alt="TTN Phase 1 Estimated Coverage Map">
    <p class="coverage-cap">Phase 1 estimated RF coverage · 6-meter FM · Tennessee · <?= date('Y') ?> · Updated as sites come online</p>
</section>

<!-- LEAFLET MAP -->
<?php if (!empty($mapped_sites)): ?>
<section class="map-wrap">
    <div class="sec-label">Site Locations</div>
    <h2 style="margin-bottom:1.5rem">Interactive Site Map</h2>
    <div id="ttn-map"></div>
</section>
<?php endif; ?>

<!-- SITE CARDS -->
<section class="sites-sec">
    <div class="sec-label">Sites</div>
    <h2>Phase 1 Sites — <?= $total_count ?> Total</h2>
    <div class="sites-grid">
    <?php foreach ($sites as $s):
        $status = $s['status'] ?? 'planned';
        $badge  = match($status) {
            'live'     => '<span class="badge b-live">● LIVE</span>',
            'building' => '<span class="badge b-build">◐ BUILDING</span>',
            default    => '<span class="badge b-plan">○ PLANNED</span>',
        };
        $loc = trim(($s['city'] ? $s['city'].', ' : '').($s['state'] ?? 'TN'));
    ?>
    <div class="site-card <?= htmlspecialchars($status) ?>">
        <div class="sc-top">
            <span class="sc-call"><?= htmlspecialchars($s['sys_callsign'] ?? '—') ?></span>
            <?= $badge ?>
        </div>
        <div class="sc-name"><?= htmlspecialchars($s['name']) ?></div>
        <div class="sc-loc"><?= htmlspecialchars($loc) ?><?= $s['county'] ? ' · '.htmlspecialchars($s['county']).' Co.' : '' ?></div>
        <?php if ($s['primary_tx'] && $s['primary_tx'] != '0.0000'): ?>
        <div class="sc-freq">
            <?= htmlspecialchars($s['primary_tx']) ?>
            <?= $s['primary_rx'] ? ' / '.htmlspecialchars($s['primary_rx']) : '' ?>
            <?= $s['primary_pl'] ? ' · PL '.htmlspecialchars($s['primary_pl']) : '' ?>
            <?= $s['primary_band'] ? ' · '.htmlspecialchars($s['primary_band']) : '' ?>
        </div>
        <?php endif; ?>
        <?php if ($s['hub_asl']): ?>
        <div class="sc-asl">AllStar <span><?= htmlspecialchars($s['hub_asl']) ?></span></div>
        <?php endif; ?>
        <?php if ($s['tower_height_ft']): ?>
        <div class="sc-asl" style="margin-top:0.25rem"><?= $s['tower_height_ft'] ?>ft
            <?= $s['tower_type'] ? htmlspecialchars($s['tower_type']) : '' ?>
            <?= $s['power_primary'] ? ' · '.htmlspecialchars($s['power_primary']) : '' ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
</section>

<!-- PHASE STRIP -->
<section class="phase-sec">
    <div class="sec-label">Roadmap</div>
    <h2>Build Phases</h2>
    <div class="phase-grid">
        <div class="ph-card now">
            <div class="ph-bg">1</div>
            <div class="ph-n">Phase 01 · Active</div>
            <div class="ph-t">Foundation</div>
            <div class="ph-y">2025 — 2026</div>
            <ul class="ph-items">
                <li>6m FM repeater backbone — statewide</li>
                <li>AllStarLink linked via IPSec VPN</li>
                <li>Solar-primary sites</li>
                <li>GPS-disciplined NTP timing</li>
                <li>Sensor fabric — ESP32 per site</li>
                <li>Simulcast v1 — GPS-timed 2-site test</li>
                <li>Live audio streaming — Icecast</li>
                <li>ARDC grant submission — Sept 1 2026</li>
            </ul>
        </div>
        <div class="ph-card">
            <div class="ph-bg">2</div>
            <div class="ph-n">Phase 02 · Planned</div>
            <div class="ph-t">Sovereignty</div>
            <div class="ph-y">2026 — 2029</div>
            <ul class="ph-items">
                <li>ARDEN microwave backhaul — Tier 1</li>
                <li>UHF point-to-point links (TK-890)</li>
                <li>Simulcast across full network</li>
                <li>Kraken DF at tower sites</li>
                <li>DVSwitch — DMR / P25 / YSF</li>
                <li>URI201 controller standard</li>
                <li>Zero commercial ISP dependency</li>
            </ul>
        </div>
        <div class="ph-card">
            <div class="ph-bg">3</div>
            <div class="ph-n">Phase 03 · Long-term</div>
            <div class="ph-t">Full Mesh</div>
            <div class="ph-y">2029+</div>
            <ul class="ph-items">
                <li>Full ARDEN mesh — all sites</li>
                <li>LoRaWAN sensor fabric</li>
                <li>NooElec APRS — deepest fallback</li>
                <li>MTEARS e-com integration</li>
                <li>GPS simulcast — full network</li>
                <li>Emergency infrastructure ready</li>
            </ul>
        </div>
    </div>
</section>

<?php if (!empty($mapped_sites)):
$js_sites = [];
foreach ($mapped_sites as $s) {
    $js_sites[] = [
        'lat'    => (float)$s['lat'],
        'lng'    => (float)$s['lng'],
        'name'   => $s['name'],
        'call'   => $s['sys_callsign'] ?? '',
        'freq'   => $s['primary_tx'] ? $s['primary_tx'].($s['primary_pl'] ? ' PL'.$s['primary_pl'] : '') : '',
        'status' => $s['status'] ?? 'planned',
        'asl'    => $s['hub_asl'] ?? '',
        'loc'    => trim(($s['city'] ? $s['city'].', ' : '').($s['state'] ?? 'TN')),
    ];
}
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
(function(){
    const map = L.map('ttn-map',{center:[35.9,-86.5],zoom:7});
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',{
        attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors © <a href="https://carto.com/">CARTO</a>',
        subdomains:'abcd',maxZoom:19
    }).addTo(map);
    const colors={live:'#00e676',building:'#ffab00',planned:'#6b8899',offline:'#ff1744'};
    const sites=<?= json_encode(array_values($js_sites)) ?>;
    sites.forEach(s=>{
        const color=colors[s.status]||colors.planned;
        const icon=L.divIcon({
            html:`<svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                <circle cx="9" cy="9" r="7" fill="${color}" fill-opacity="0.25" stroke="${color}" stroke-width="1.5"/>
                <circle cx="9" cy="9" r="3" fill="${color}"/></svg>`,
            className:'',iconSize:[18,18],iconAnchor:[9,9],popupAnchor:[0,-12]
        });
        const popup=`<div class="pop-call">${s.call||s.name}</div>
            <div>${s.name}${s.loc?' · '+s.loc:''}</div>
            ${s.freq?`<div class="pop-freq">${s.freq}</div>`:''}
            ${s.asl?`<div>AllStar ${s.asl}</div>`:''}
            <div class="pop-status pop-${s.status}">${s.status.toUpperCase()}</div>`;
        L.marker([s.lat,s.lng],{icon}).bindPopup(popup).addTo(map);
    });
    if(sites.length>1){
        map.fitBounds(L.latLngBounds(sites.map(s=>[s.lat,s.lng])),{padding:[40,40]});
    }
})();
</script>
<?php endif; ?>

<?php require_once TTN_INCLUDES . '/footer.php'; ?>
