<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
$page_title = 'Listen Live';
$org_name   = s('org_name',     'Tennessee Technological Network');
$callsign   = s('org_callsign', 'W4BWW');
$hub_node   = s('hub_node',     '65392');

$extra_head = '<style>
.listen-wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60vh;padding:4rem 2rem;text-align:center}
.listen-eyebrow{font-family:var(--mono);font-size:0.58rem;color:var(--t3);letter-spacing:0.2em;text-transform:uppercase;margin-bottom:1rem}
.listen-title{font-family:var(--display);font-weight:800;font-size:clamp(1.8rem,4vw,3rem);text-transform:uppercase;letter-spacing:0.06em;color:var(--t1);margin-bottom:0.4rem}
.listen-sub{font-family:var(--mono);font-size:0.68rem;color:var(--t3);letter-spacing:0.15em;margin-bottom:2.5rem}
.listen-player{width:100%;max-width:540px;margin-bottom:1.2rem}
.listen-player audio{width:100%;accent-color:var(--green)}
.listen-status{font-family:var(--mono);font-size:0.72rem;letter-spacing:0.12em;text-transform:uppercase;margin-bottom:2.5rem;min-height:1.2em}
.listen-status.live{color:var(--green)}
.listen-status.offline{color:var(--t3)}
.listen-links{display:flex;gap:0.8rem;flex-wrap:wrap;justify-content:center}
.listen-links a{font-family:var(--mono);font-size:0.65rem;letter-spacing:0.1em;text-transform:uppercase;color:var(--t3);text-decoration:none;border:1px solid var(--border2);padding:0.5rem 1rem;transition:all 0.15s}
.listen-links a:hover{color:var(--green);border-color:var(--green)}
</style>';
require_once TTN_INCLUDES . '/header.php';
?>

<div class="listen-wrap">
    <div class="listen-eyebrow"><?= htmlspecialchars($org_name) ?> · <?= htmlspecialchars($callsign) ?></div>
    <div class="listen-title">LISTEN LIVE</div>
    <div class="listen-sub">TTN HUB AUDIO · ALLSTARLINK NODE <?= htmlspecialchars($hub_node) ?> · stream.ttn.radio</div>

    <div class="listen-player">
        <audio id="ttn-audio" controls autoplay preload="none">
            <source src="https://stream.ttn.radio/live" type="audio/mpeg">
            Your browser does not support audio streaming.
        </audio>
    </div>

    <div class="listen-status offline" id="stream-status">⟳ CHECKING STREAM...</div>
    <div class="listen-status" id="listener-count"></div>

    <div class="listen-links">
        <a href="https://stream.ttn.radio/live">DIRECT STREAM</a>
        <a href="https://stream.ttn.radio">ICECAST STATUS</a>
        <a href="/">← HOME</a>
    </div>
</div>

<script>
async function checkStream() {
    const el = document.getElementById('stream-status');
    try {
        const r = await fetch('https://stream.ttn.radio/status-json.xsl', {cache:'no-store'});
        const d = await r.json();
        const src = d?.icestats?.source;
        const live = src && (Array.isArray(src) ? src.length > 0 : true);
        if (live) {
            el.textContent = '◉ STREAM LIVE';
            el.className = 'listen-status live';
        } else {
            el.textContent = '○ NO ACTIVE SOURCE — HUB AUDIO PENDING';
            el.className = 'listen-status offline';
        }
    } catch(e) {
        el.textContent = '○ STREAM OFFLINE';
        el.className = 'listen-status offline';
    }
}
checkStream();
setInterval(checkStream, 15000);
</script>

<?php require_once TTN_INCLUDES . '/footer.php'; ?>
