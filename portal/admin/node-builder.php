<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_require_role('admin');

$adm_title = 'Node Page Builder';
$adm_page  = 'network';

// Load existing servers and sites for dropdowns
$servers = db_rows("SELECT * FROM asl_servers WHERE is_active=1 ORDER BY hostname");
$sites   = db_rows("SELECT id, name FROM sites ORDER BY name");

require_once TTN_INCLUDES . '/admin_head.php';
require_once TTN_INCLUDES . '/admin_nav.php';
?>

<div class="adm-main">
<div class="adm-topbar">
    <div class="adm-topbar-title">Node Page Builder</div>
    <div style="font-family:var(--mono);font-size:0.6rem;color:var(--t3)">Generates a ready-to-deploy index.html for any TTN node server</div>
</div>
<div class="adm-body">

<div class="panel">
<div class="panel-hd">Configure Node Page</div>
<div class="panel-body">

<form id="builderForm">

<div style="font-family:var(--mono);font-size:0.58rem;color:var(--green);letter-spacing:0.12em;text-transform:uppercase;margin-bottom:0.8rem">Identity</div>
<div class="field-row">
    <div class="field"><label>Callsign</label><input type="text" id="callsign" value="W4BWW" placeholder="W4BWW"></div>
    <div class="field"><label>Hub Name</label><input type="text" id="hub_name" value="TN-HUB" placeholder="TN-HUB"></div>
    <div class="field"><label>Location</label><input type="text" id="location" value="New Market, TN" placeholder="City, ST"></div>
    <div class="field"><label>Coords</label><input type="text" id="coords" value="" placeholder="36.027°N 83.535°W"></div>
</div>
<div class="field">
    <label>Description</label>
    <textarea id="description" rows="2">TTN node server for the Tennessee Technological Community.</textarea>
</div>

<div style="font-family:var(--mono);font-size:0.58rem;color:var(--green);letter-spacing:0.12em;text-transform:uppercase;margin:1.2rem 0 0.8rem;padding-top:1rem;border-top:1px solid var(--border2)">Node / Server</div>
<div class="field-row">
    <div class="field"><label>Hub Node Number</label><input type="text" id="hub_node" value="450330" placeholder="450330"></div>
    <div class="field"><label>Hub Frequency</label><input type="text" id="hub_freq" value="53.870" placeholder="53.870"></div>
    <div class="field"><label>Hostname</label><input type="text" id="hostname" value="tn.w4bww.net" placeholder="tn.w4bww.net"></div>
</div>
<div class="field">
    <label>Status URL</label>
    <input type="text" id="status_url" value="/ttn-status.php" placeholder="/ttn-status.php">
    <div style="font-size:0.58rem;color:var(--t3);margin-top:0.3rem">Use /ttn-status.php for local AMI · Use https://ttechnological.net/api/node-status.php for DB-backed (no ISP needed)</div>
</div>

<div style="font-family:var(--mono);font-size:0.58rem;color:var(--green);letter-spacing:0.12em;text-transform:uppercase;margin:1.2rem 0 0.8rem;padding-top:1rem;border-top:1px solid var(--border2)">Spec Rows <span style="color:var(--t3);font-weight:400">(label · value · color class: a=amber g=green blank=default)</span></div>
<div id="specsContainer"></div>
<button type="button" class="btn btn-secondary btn-sm" onclick="addRow('specs')">+ Add Spec Row</button>

<div style="font-family:var(--mono);font-size:0.58rem;color:var(--green);letter-spacing:0.12em;text-transform:uppercase;margin:1.2rem 0 0.8rem;padding-top:1rem;border-top:1px solid var(--border2)">Interface Links <span style="color:var(--t3);font-weight:400">(label · URL · description)</span></div>
<div id="ifacesContainer"></div>
<button type="button" class="btn btn-secondary btn-sm" onclick="addRow('ifaces')">+ Add Interface</button>

<div style="font-family:var(--mono);font-size:0.58rem;color:var(--green);letter-spacing:0.12em;text-transform:uppercase;margin:1.2rem 0 0.8rem;padding-top:1rem;border-top:1px solid var(--border2)">Ticker Items</div>
<div id="tickerContainer"></div>
<button type="button" class="btn btn-secondary btn-sm" onclick="addRow('ticker')">+ Add Ticker Item</button>

<div style="font-family:var(--mono);font-size:0.58rem;color:var(--green);letter-spacing:0.12em;text-transform:uppercase;margin:1.2rem 0 0.8rem;padding-top:1rem;border-top:1px solid var(--border2)">Images <span style="color:var(--t3);font-weight:400">(URL · label)</span></div>
<div id="imagesContainer"></div>
<button type="button" class="btn btn-secondary btn-sm" onclick="addRow('images')">+ Add Image</button>

<div style="font-family:var(--mono);font-size:0.58rem;color:var(--green);letter-spacing:0.12em;text-transform:uppercase;margin:1.2rem 0 0.8rem;padding-top:1rem;border-top:1px solid var(--border2)">Footer</div>
<div class="field-row">
    <div class="field"><label>Footer Main</label><input type="text" id="footer_main" value="W4BWW · TTN Tennessee Technological Community · EIN 41-2680033"></div>
    <div class="field"><label>Footer Contact</label><input type="text" id="footer_contact" value="ttn.radio · bobwwj555@gmail.com · 865-202-6696"></div>
</div>
<div class="field"><label>CW Text</label><input type="text" id="cw_text" value="W4BWW DE TTN 73" placeholder="W4BWW DE TTN 73"></div>

<div style="margin-top:1.5rem;display:flex;gap:0.8rem">
    <button type="button" class="btn btn-primary" onclick="generateAndDownload()">⬇ Generate &amp; Download index.html</button>
    <button type="button" class="btn btn-secondary" onclick="previewConfig()">Preview Config</button>
</div>

</form>

<pre id="configPreview" style="display:none;margin-top:1rem;background:var(--panel2);border:1px solid var(--border2);padding:1rem;font-size:0.7rem;overflow-x:auto;color:var(--green)"></pre>

</div>
</div>

</div>
</div>

<script>
// ── DEFAULT DATA ──────────────────────────────────────────────
const DEFAULTS = {
    specs: [
        ['Location',    'New Market, TN · 36.027°N 83.535°W', ''],
        ['Hub Node',    'AllStar 450330',                       'a'],
        ['6m Repeater', '53.870 / 52.870 · PL 118.8',         ''],
        ['Tower',       '120ft Rohn 25 · 360W ERP',            ''],
        ['Power',       'Solar Primary · 48V Battery Backup',  'g'],
        ['Trustee',     'Bobby Whitaker · W4BWW · GROL',       ''],
        ['Network',     'TTN · ttn.radio',                     'g'],
    ],
    ifaces: [
        ['ALLSCAN',    'https://tn.w4bww.net/allscan',   'Primary node interface · Recommended'],
        ['SUPERMON',   'https://tn.w4bww.net/supermon',  'Node monitoring · Status overview'],
        ['ALLMON3',    'https://tn.w4bww.net/allmon3',   'AllStarLink monitor'],
        ['TTN.RADIO',  'https://ttn.radio',               'Main network page · All sites'],
    ],
    ticker: [
        'TTN · TENNESSEE TECHNOLOGICAL COMMUNITY · ttn.radio',
        'NO DUES · NO POLITICS · OPEN TO ALL LICENSED HAMS',
        'ELMER THE ELMERS — PASS IT ON',
    ],
    images: [
        ['https://tn.w4bww.net/images/TN_Cov_w4bww.png', 'Coverage Estimate'],
        ['https://tn.w4bww.net/images/TNTower.jpg',       'Tower Photo'],
    ],
};

// ── ROW BUILDERS ─────────────────────────────────────────────
function makeRow(container, vals, type) {
    const div = document.createElement('div');
    div.style = 'display:flex;gap:0.4rem;margin-bottom:0.4rem;align-items:center';

    if (type === 'specs') {
        div.innerHTML = `
            <input type="text" value="${esc(vals[0])}" placeholder="Label" style="width:130px;font-size:0.72rem">
            <input type="text" value="${esc(vals[1])}" placeholder="Value" style="flex:1;font-size:0.72rem">
            <input type="text" value="${esc(vals[2])}" placeholder="cls" style="width:40px;font-size:0.72rem">
            <button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.remove()">✕</button>`;
    } else if (type === 'ifaces') {
        div.innerHTML = `
            <input type="text" value="${esc(vals[0])}" placeholder="Label" style="width:100px;font-size:0.72rem">
            <input type="text" value="${esc(vals[1])}" placeholder="https://..." style="flex:1;font-family:var(--mono);font-size:0.7rem">
            <input type="text" value="${esc(vals[2])}" placeholder="Description" style="width:200px;font-size:0.72rem">
            <button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.remove()">✕</button>`;
    } else if (type === 'ticker') {
        div.innerHTML = `
            <input type="text" value="${esc(vals[0])}" placeholder="Ticker text" style="flex:1;font-size:0.72rem">
            <button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.remove()">✕</button>`;
    } else if (type === 'images') {
        div.innerHTML = `
            <input type="text" value="${esc(vals[0])}" placeholder="https://..." style="flex:1;font-family:var(--mono);font-size:0.7rem">
            <input type="text" value="${esc(vals[1])}" placeholder="Label" style="width:180px;font-size:0.72rem">
            <button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.remove()">✕</button>`;
    }
    document.getElementById(container + 'Container').appendChild(div);
}

function addRow(type) {
    const empties = { specs: ['','',''], ifaces: ['','',''], ticker: [''], images: ['',''] };
    makeRow(type, empties[type], type);
}

function esc(s) {
    return (s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Init defaults
window.onload = function() {
    DEFAULTS.specs.forEach(r   => makeRow('specs',  r, 'specs'));
    DEFAULTS.ifaces.forEach(r  => makeRow('ifaces', r, 'ifaces'));
    DEFAULTS.ticker.forEach(r  => makeRow('ticker', [r], 'ticker'));
    DEFAULTS.images.forEach(r  => makeRow('images', r, 'images'));
};

// ── DATA COLLECTORS ───────────────────────────────────────────
function getRows(container, type) {
    const rows = [];
    document.getElementById(container + 'Container').querySelectorAll('div').forEach(row => {
        const inputs = row.querySelectorAll('input');
        if (type === 'ticker') {
            if (inputs[0].value.trim()) rows.push(inputs[0].value.trim());
        } else {
            const vals = Array.from(inputs).map(i => i.value.trim());
            if (vals.some(v => v)) rows.push(vals);
        }
    });
    return rows;
}

function jsStr(s) { return "'" + s.replace(/\\/g,'\\\\').replace(/'/g,"\\'") + "'"; }

function buildConfig() {
    const specs   = getRows('specs',  'specs');
    const ifaces  = getRows('ifaces', 'ifaces');
    const ticker  = getRows('ticker', 'ticker');
    const images  = getRows('images', 'images');

    const specsJS   = specs.map(r  => `        [${r.map(jsStr).join(', ')}]`).join(',\n');
    const ifacesJS  = ifaces.map(r => `        [${r.map(jsStr).join(', ')}]`).join(',\n');
    const tickerJS  = ticker.map(t => `        ${jsStr(t)}`).join(',\n');
    const imagesJS  = images.map(r => `        [${r.map(jsStr).join(', ')}]`).join(',\n');

    return `// TTN NODE PAGE CONFIG
// To deploy on a new server — edit ONLY this block.
// Everything else is automatic.
// ══════════════════════════════════════════════════════════════
const NODE_CONFIG = {
    // Identity
    callsign:    ${jsStr(document.getElementById('callsign').value)},
    hub_name:    ${jsStr(document.getElementById('hub_name').value)},
    location:    ${jsStr(document.getElementById('location').value)},
    coords:      ${jsStr(document.getElementById('coords').value)},
    description: ${jsStr(document.getElementById('description').value)},

    // Primary node for status polling
    hub_node:    ${jsStr(document.getElementById('hub_node').value)},
    hub_freq:    ${jsStr(document.getElementById('hub_freq').value)},

    // This server\'s hostname
    hostname:    ${jsStr(document.getElementById('hostname').value)},

    // Status proxy
    status_url:  ${jsStr(document.getElementById('status_url').value)},

    // Node specs table
    specs: [
${specsJS}
    ],

    // Interface links
    interfaces: [
${ifacesJS}
    ],

    // Ticker items
    ticker: [
${tickerJS}
    ],

    // Images
    images: [
${imagesJS}
    ],

    // Footer
    footer_main:    ${jsStr(document.getElementById('footer_main').value)},
    footer_contact: ${jsStr(document.getElementById('footer_contact').value)},

    // CW easter egg text
    cw_text: ${jsStr(document.getElementById('cw_text').value)},
};
// ══════════════════════════════════════════════════════════════
// END CONFIG — do not edit below this line`;
}

function previewConfig() {
    const pre = document.getElementById('configPreview');
    pre.style.display = pre.style.display === 'none' ? 'block' : 'none';
    pre.textContent = buildConfig();
}

function generateAndDownload() {
    const config = buildConfig();
    const hostname = document.getElementById('hostname').value || 'node';
    const filename = hostname.replace(/[^a-z0-9\-\.]/gi,'_') + '-index.html';

    // Template parts are embedded server-side
    const html = TEMPLATE_BEFORE + config + TEMPLATE_AFTER;

    const blob = new Blob([html], {type: 'text/html'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    a.click();
    URL.revokeObjectURL(a.href);
}

// ── EMBEDDED TEMPLATE ────────────────────────────────────────
const TEMPLATE_BEFORE = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n<title>W4BWW TN-HUB \u00b7 Piedmont Node \u00b7 TTN</title>\n<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n<link href=\"https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Oxanium:wght@200;300;400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap\" rel=\"stylesheet\">\n<link rel=\"stylesheet\" href=\"https://ttechnological.net/ttn.css\">\n<style>\n.node-hero{min-height:100vh;padding-top:46px;position:relative;overflow:hidden;display:flex;flex-direction:column}\n.node-hero-bg{position:absolute;inset:0;background:radial-gradient(ellipse 60% 50% at 50% 30%,rgba(255,171,0,0.03) 0%,transparent 60%),radial-gradient(ellipse 40% 40% at 20% 70%,rgba(0,230,118,0.03) 0%,transparent 60%)}\n.node-hero-grid{position:absolute;inset:0;background-image:linear-gradient(var(--border) 1px,transparent 1px),linear-gradient(90deg,var(--border) 1px,transparent 1px);background-size:40px 40px;opacity:0.5}\n.node-main{display:grid;grid-template-columns:1fr 1fr;align-items:start;padding:3rem 5vw 2rem;position:relative;z-index:2;gap:2rem;flex:1}\n.node-id{font-family:var(--mono);font-size:0.65rem;color:var(--amber);letter-spacing:0.25em;text-transform:uppercase;margin-bottom:1rem;display:flex;align-items:center;gap:0.7rem}\n.node-id::before{content:'';display:block;width:24px;height:1px;background:var(--amber);box-shadow:0 0 6px var(--amber)}\n.node-title{font-family:var(--display);font-weight:800;font-size:clamp(2.4rem,4.5vw,4rem);line-height:0.95;text-transform:uppercase;color:var(--t1);margin-bottom:0.5rem}\n.node-title .a{color:var(--amber);display:block;text-shadow:0 0 30px rgba(255,171,0,0.2)}\n.node-title .sub{font-weight:200;font-size:0.4em;letter-spacing:0.2em;color:var(--t3);display:block;margin-top:0.5em}\n.node-desc{font-size:0.88rem;line-height:1.7;color:var(--t2);max-width:440px;margin:1.2rem 0 2rem}\n.iface-panel{background:var(--panel);border:1px solid var(--border2);font-family:var(--mono);margin-bottom:1.5rem}\n.iface-hd{background:var(--panel2);border-bottom:1px solid var(--border2);padding:0.5rem 1rem;font-size:0.6rem;letter-spacing:0.15em;color:var(--t3);text-transform:uppercase;display:flex;justify-content:space-between}\n.iface-hd .g{color:var(--green)}\n.iface-row{display:flex;align-items:center;padding:0.7rem 1rem;border-bottom:1px solid var(--border);transition:background 0.15s;text-decoration:none;gap:1rem}\n.iface-row:hover{background:var(--gglow)}\n.iface-row:last-child{border-bottom:none}\n.iface-name{font-size:0.8rem;color:var(--t1);flex:1;letter-spacing:0.05em}\n.iface-desc{font-size:0.6rem;color:var(--t3);letter-spacing:0.08em;text-transform:uppercase}\n.iface-arr{color:var(--green);font-size:0.8rem}\n.media-grid{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border2);border:1px solid var(--border2);margin-top:1.5rem}\n.media-card{background:var(--panel);padding:0}\n.media-card img{width:100%;height:200px;object-fit:cover;display:block;filter:brightness(0.85) saturate(0.7)}\n.media-card-label{background:var(--panel2);padding:0.5rem 0.8rem;font-family:var(--mono);font-size:0.58rem;color:var(--t3);letter-spacing:0.1em;text-transform:uppercase;border-top:1px solid var(--border2)}\n.specs{background:var(--panel);border:1px solid var(--border2);font-family:var(--mono)}\n.spec-row{display:grid;grid-template-columns:140px 1fr;padding:0.5rem 1rem;border-bottom:1px solid var(--border);font-size:0.68rem}\n.spec-row:last-child{border-bottom:none}\n.spec-k{color:var(--t3);letter-spacing:0.08em;text-transform:uppercase;font-size:0.6rem}\n.spec-v{color:var(--t1)}\n.spec-v.g{color:var(--green)}\n.spec-v.a{color:var(--amber)}\n.live-section{background:var(--bg2);border-top:1px solid var(--border2);border-bottom:1px solid var(--border2);padding:3rem 5vw}\n.connected-grid{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border2);border:1px solid var(--border2)}\n.conn-row{background:var(--panel);padding:0.7rem 1rem;display:flex;align-items:center;gap:0.8rem;font-family:var(--mono);transition:background 0.15s}\n.conn-row:hover{background:var(--panel2)}\n.conn-node{color:var(--amber);font-size:0.75rem;min-width:65px}\n.conn-call{color:var(--t1);font-size:0.72rem;flex:1}\n.conn-state{font-size:0.55rem;letter-spacing:0.1em;text-transform:uppercase;color:var(--green)}\n.conn-empty{background:var(--panel);padding:1.2rem 1rem;font-family:var(--mono);font-size:0.65rem;color:var(--t3);font-style:italic;grid-column:1/-1;text-align:center}\n.live-banner{background:var(--gglow);border:1px solid var(--gdim);padding:0.4rem 1rem;font-family:var(--mono);font-size:0.6rem;color:var(--green);letter-spacing:0.1em;text-transform:uppercase;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center}\n.live-banner.loading{color:var(--t3);background:transparent;border-color:var(--border2)}\n.live-banner.error{color:var(--red);background:rgba(255,23,68,0.04);border-color:rgba(255,23,68,0.2)}\n.live-banner .rb{cursor:pointer;border:1px solid var(--border2);padding:0.1rem 0.5rem;font-size:0.57rem;transition:all 0.15s}\n.live-banner .rb:hover{color:var(--green);border-color:var(--gdim)}\n@media(max-width:800px){\n  .node-main{grid-template-columns:1fr;padding:2rem 1.5rem}\n  .media-grid,.connected-grid{grid-template-columns:1fr}\n  .live-section{padding:2rem 1.5rem}\n}\n</style>\n</head>\n<body>\n\n<!-- TOPBAR -->\n<header class=\"tb\">\n  <div class=\"tb-logo\" id=\"ttnLogo\" title=\"W4BWW DE TTN\"><span class=\"tb-dot\"></span>TTN</div>\n  <nav class=\"tb-nav\">\n    <a href=\"https://ttn.radio\" target=\"_blank\">TTN Home</a>\n    <a href=\"#interfaces\">Interfaces</a>\n    <a href=\"#live\">Live Nodes</a>\n    <a href=\"#specs\">Node Specs</a>\n    <a href=\"https://ttn.radio/donate/\" target=\"_blank\">Donate</a>\n  </nav>\n  <div class=\"tb-right\">\n    <div class=\"tb-tag\"><span id=\"liveCount\">\u2014 CONNECTED</span>NODE <span id=\"hdrNode\"></span></div>\n    <div class=\"tb-freq\" id=\"hdrFreq\"></div>\n  </div>\n</header>\n\n<!-- NODE HERO -->\n<section class=\"node-hero\">\n  <div class=\"node-hero-bg\"></div>\n  <div class=\"node-hero-grid\"></div>\n  <div class=\"node-main\">\n    <div>\n      <div class=\"node-id\" id=\"heroId\"></div>\n      <div class=\"node-title\" id=\"heroTitle\"></div>\n      <p class=\"node-desc\" id=\"heroDesc\"></p>\n\n      <!-- INTERFACE LINKS -->\n      <div class=\"iface-panel\" id=\"interfaces\">\n        <div class=\"iface-hd\"><span class=\"g\">\u25c9 NODE INTERFACES</span><span id=\"ifaceHost\"></span></div>\n        <div id=\"ifaceLinks\"></div>\n      </div>\n    </div>\n\n    <div>\n      <!-- NODE SPECS -->\n      <div class=\"specs\" id=\"specs\">\n        <div class=\"iface-hd\"><span>NODE SPECIFICATIONS</span><span style=\"color:var(--green)\" id=\"specsTitle\"></span></div>\n        <div id=\"specRows\"></div>\n      </div>\n\n      <!-- IMAGES -->\n      <div class=\"media-grid\" id=\"mediaGrid\"></div>\n    </div>\n  </div>\n\n  <!-- TICKER -->\n  <div class=\"ticker\">\n    <div class=\"tick-lbl\" id=\"tickLabel\"></div>\n    <div class=\"tick-scroll\"><div class=\"tick-inner\" id=\"tickInner\"></div></div>\n  </div>\n</section>\n\n<!-- LIVE CONNECTED NODES -->\n<section class=\"live-section\" id=\"live\">\n  <div class=\"sec-label\">Live</div>\n  <h2>Connected Nodes</h2>\n  <div class=\"live-banner loading\" id=\"liveBanner\">\n    <span id=\"liveBannerText\">\u27f3 POLLING NODE SERVER...</span>\n    <span class=\"rb\" onclick=\"fetchNodeStatus()\">\u21bb REFRESH</span>\n  </div>\n  <div class=\"connected-grid\" id=\"connectedGrid\">\n    <div class=\"conn-empty\">Loading live data...</div>\n  </div>\n</section>\n\n<!-- FOOTER -->\n<footer>\n  <span id=\"footerMain\"></span>\n  <span id=\"footerContact\"></span>\n  <span>\n    <a href=\"https://ttn.radio\" target=\"_blank\">TTN Home</a> \u00b7\n    <a href=\"https://www.facebook.com/groups/867375109631931\" target=\"_blank\">Facebook</a> \u00b7\n    <a href=\"https://ttn.radio/donate/\" target=\"_blank\">Donate</a>\n  </span>\n</footer>\n\n<div class=\"cw-toast\" id=\"cwToast\"></div>\n\n<script>\n// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\n";
const TEMPLATE_AFTER  = "// END CONFIG \u2014 do not edit below this line\n// \u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\n\n// \u2500\u2500 RENDER PAGE FROM CONFIG \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\n(function() {\n    const c = NODE_CONFIG;\n\n    document.title = `${c.callsign} ${c.hub_name} \u00b7 ${c.location} \u00b7 TTN`;\n\n    document.getElementById('hdrNode').textContent  = c.hub_node;\n    document.getElementById('hdrFreq').textContent  = c.hub_freq;\n    document.getElementById('heroId').textContent   = `${c.callsign} \u00b7 ${c.hub_name} \u00b7 ${c.location} \u00b7 AllStar ${c.hub_node}`;\n    document.getElementById('heroTitle').innerHTML  = `${c.hub_name}<span class=\"a\">${c.callsign}</span><span class=\"sub\">Tennessee Technological Community \u00b7 TTN ${c.hub_name}</span>`;\n    document.getElementById('heroDesc').textContent = c.description;\n    document.getElementById('ifaceHost').textContent = c.hostname.toUpperCase();\n    document.getElementById('specsTitle').textContent = `${c.callsign} ${c.hub_name}`;\n    document.getElementById('tickLabel').textContent  = `${c.callsign} HUB`;\n    document.getElementById('cwToast').textContent    = `\u25b6 CW \u00b7 ${c.cw_text}`;\n    document.getElementById('footerMain').textContent    = c.footer_main;\n    document.getElementById('footerContact').textContent = c.footer_contact;\n\n    // Interface links\n    document.getElementById('ifaceLinks').innerHTML = c.interfaces.map(([name, url, desc]) =>\n        `<a href=\"${url}\" class=\"iface-row\" target=\"_blank\">\n            <span class=\"iface-name\">${name}</span>\n            <span class=\"iface-desc\">${desc}</span>\n            <span class=\"iface-arr\">\u203a</span>\n        </a>`\n    ).join('');\n\n    // Spec rows\n    document.getElementById('specRows').innerHTML = c.specs.map(([k, v, cls]) =>\n        `<div class=\"spec-row\"><span class=\"spec-k\">${k}</span><span class=\"spec-v ${cls}\">${v}</span></div>`\n    ).join('');\n\n    // Images\n    if (c.images.length) {\n        document.getElementById('mediaGrid').innerHTML = c.images.map(([url, label]) =>\n            `<div class=\"media-card\">\n                <img src=\"${url}\" alt=\"${label}\" loading=\"lazy\">\n                <div class=\"media-card-label\">${label}</div>\n            </div>`\n        ).join('');\n    } else {\n        document.getElementById('mediaGrid').style.display = 'none';\n    }\n\n    // Ticker \u2014 duplicate for seamless loop\n    const items = c.ticker.map((t,i) =>\n        `<span class=\"${i===0||i===c.ticker.length-1?'hi':''}\">${t}</span><span class=\"sep\">|</span>`\n    ).join('');\n    document.getElementById('tickInner').innerHTML = items + items;\n})();\n\n// \u2500\u2500 NODE STATUS POLLING \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\nasync function fetchNodeStatus() {\n    const banner     = document.getElementById('liveBanner');\n    const bannerText = document.getElementById('liveBannerText');\n    const grid       = document.getElementById('connectedGrid');\n    const liveCount  = document.getElementById('liveCount');\n\n    banner.className = 'live-banner loading';\n    bannerText.textContent = '\u27f3 POLLING NODE SERVER...';\n\n    try {\n        const res = await fetch(`${NODE_CONFIG.status_url}?node=${NODE_CONFIG.hub_node}`, {\n            signal: AbortSignal.timeout(20000)\n        });\n        if (!res.ok) throw new Error('HTTP ' + res.status);\n        const data = await res.json();\n        if (!data.ok) throw new Error(data.error || 'Node error');\n\n        const count = data.conn_count ?? 0;\n        liveCount.textContent = `${count} CONNECTED`;\n        banner.className = 'live-banner';\n        bannerText.textContent = `\u25c9 NODE ${NODE_CONFIG.hub_node} ONLINE \u00b7 ${count} CONNECTED \u00b7 ${new Date().toLocaleTimeString()}`;\n\n        const conns = data.connections || [];\n        grid.innerHTML = conns.length\n            ? conns.map(n =>\n                `<div class=\"conn-row\">\n                    <span class=\"conn-node\">${n.node}</span>\n                    <span class=\"conn-call\">${n.callsign || ''}</span>\n                    <span class=\"conn-state\">${n.direction || 'CONNECTED'}</span>\n                </div>`\n              ).join('')\n            : `<div class=\"conn-empty\">Hub online \u00b7 No active connections right now \u00b7 AllStar ${NODE_CONFIG.hub_node}</div>`;\n\n    } catch(e) {\n        banner.className = 'live-banner error';\n        bannerText.textContent = `\u2715 LIVE DATA UNAVAILABLE \u00b7 ${new Date().toLocaleTimeString()}`;\n        grid.innerHTML = `<div class=\"conn-empty\" style=\"color:var(--t3)\">Connect: AllStar ${NODE_CONFIG.hub_node}</div>`;\n    }\n}\n\nfetchNodeStatus();\nsetInterval(fetchNodeStatus, 60000);\n\n// \u2500\u2500 CW EASTER EGG \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\nconst CW = {\n    A:'.-',B:'-...',C:'-.-.',D:'-..',E:'.',F:'..-.',G:'--.',H:'....',\n    I:'..',J:'.---',K:'-.-',L:'.-..',M:'--',N:'-.',O:'---',P:'.--.',\n    Q:'--.-',R:'.-.',S:'...',T:'-',U:'..-',V:'...-',W:'.--',X:'-..-',\n    Y:'-.--',Z:'--..',7:'--...',3:'...--',' ':' '\n};\n\nfunction playCW(text) {\n    try {\n        const ctx = new (window.AudioContext||window.webkitAudioContext)();\n        const WPM = 18, dit = 1.2/WPM;\n        let t = ctx.currentTime + 0.05;\n        const tone = dur => {\n            const o=ctx.createOscillator(), g=ctx.createGain();\n            o.connect(g); g.connect(ctx.destination);\n            o.frequency.value=700; o.type='sine';\n            g.gain.setValueAtTime(0,t);\n            g.gain.linearRampToValueAtTime(0.25,t+0.006);\n            g.gain.setValueAtTime(0.25,t+dur-0.006);\n            g.gain.linearRampToValueAtTime(0,t+dur);\n            o.start(t); o.stop(t+dur); t+=dur;\n        };\n        text.toUpperCase().split('').forEach(ch => {\n            if(ch===' '){t+=dit*7;return;}\n            const code=CW[ch]; if(!code)return;\n            code.split('').forEach(s=>{s==='.'?tone(dit):tone(dit*3);t+=dit;});\n            t+=dit*2;\n        });\n    } catch(e){}\n}\n\nlet cwCooldown = false;\ndocument.getElementById('ttnLogo').addEventListener('click', e => {\n    e.preventDefault();\n    if(cwCooldown) return;\n    cwCooldown = true;\n    setTimeout(()=>cwCooldown=false, 12000);\n    const toast = document.getElementById('cwToast');\n    toast.classList.add('show');\n    setTimeout(()=>toast.classList.remove('show'), 4500);\n    playCW(NODE_CONFIG.cw_text);\n});\n</script>\n</body>\n</html>\n";

</script>

