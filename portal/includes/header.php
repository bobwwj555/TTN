<?php
/**
 * TTN Shared Header
 * LOCATION: /var/www/html/includes/header.php
 */
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_session_start();
$_site_url    = s('site_url',    'https://dev.ttn.radio');
$_hub_url     = s('hub_url',     'https://hub.ttn.radio');
$_hub_node    = s('hub_node',    '65392');
$_hub_freq    = s('hub_freq',    '53.870');
$_allscan_url = $_hub_url . '/allscan';
$_org_call    = s('org_callsign','W4BWW');
$_img_logo    = s('img_logo',    '/assets/img/Diamond_and_Small_Coil.jpeg');
$_meta_desc   = s('meta_description', 'TTN — volunteer-built RF network for technical hams.');
$_nav = [
    ['Network',      $_site_url . '/network/'],
    ['Sites',        $_site_url . '/sites/'],
    ['Team',         $_site_url . '/team/'],
    ['Roadmap',      $_site_url . '/roadmap/'],
    ['Docs',         $_site_url . '/docs/'],
    ['AllScan', $_allscan_url, '_blank'],
    ['Donate',       $_site_url . '/donate/'],
];
$page_title = $page_title ?? 'TTN';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title) ?> · TTN</title>
<meta name="description" content="<?= htmlspecialchars($_meta_desc) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Oxanium:wght@200;300;400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $_site_url ?>/ttn.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' fill='%23111'/><text x='16' y='24' font-size='20' font-weight='bold' text-anchor='middle' fill='%23ffab00' font-family='monospace'>T</text></svg>">
<?php if (!empty($extra_head)) echo $extra_head; ?>
</head>
<body>
<div class="cw-toast" id="cwToast">▶ CW · <?= htmlspecialchars($_org_call) ?> DE TTN ···- ·-· ···</div>
<header class="tb">
    <a href="<?= $_site_url ?>/" class="tb-logo" id="ttnLogo" title="<?= htmlspecialchars($_org_call) ?> DE TTN">
        <?php if ($_img_logo): ?>
        <img src="<?= htmlspecialchars($_img_logo) ?>" alt="TTN" style="height:30px;width:30px;object-fit:contain;vertical-align:middle;margin-right:0.5rem;border-radius:2px">
        <?php endif; ?>TTN
    </a>
    <nav class="tb-nav" aria-label="Main navigation">
        <?php foreach ($_nav as $item): ?>
        <a href="<?= htmlspecialchars($item[1]) ?>"<?= isset($item[2]) ? ' target="'.$item[2].'"' : '' ?>><?= $item[0] ?></a>
        <?php endforeach; ?>
    </nav>
    <button class="tb-menu-btn" id="tb-menu-btn" aria-label="Open navigation menu" aria-expanded="false" aria-controls="tb-nav-mobile">☰</button>
    <div class="tb-right">
        <div class="tb-tag">
            <span id="hdr-conn">— NODES</span>
            TTN NETWORK
        </div>
        <div class="tb-freq"><?= htmlspecialchars($_hub_freq) ?></div>
        <a href="<?= $_site_url ?>/admin/login.php" class="tb-admin-link" title="Operator Login">⚙</a>
    </div>
</header>

<?php if (defined('TTN_ENV') && TTN_ENV === 'development'): ?>
<div style="position:fixed;top:46px;left:0;right:0;z-index:498;background:#ffab00;color:#000;font-family:var(--mono);font-size:0.65rem;font-weight:700;letter-spacing:0.15em;text-align:center;padding:0.2rem;text-transform:uppercase">
⚠ DEV ENVIRONMENT — ttn_dev DB — NOT PRODUCTION
</div>
<?php endif; ?>
<nav class="tb-nav-mobile" id="tb-nav-mobile" aria-label="Mobile navigation">
    <?php foreach ($_nav as $item): ?>
    <a href="<?= htmlspecialchars($item[1]) ?>"<?= isset($item[2]) ? ' target="'.$item[2].'"' : '' ?>><?= $item[0] ?></a>
    <?php endforeach; ?>
</nav>
<script>
const TTN_STATUS_URL = '<?= s('ami_proxy_url','https://tn.w4bww.net/ttn-status.php') ?>';
const TTN_HUB_NODE   = '<?= htmlspecialchars($_hub_node) ?>';
const TTN_SITE_URL   = '<?= htmlspecialchars($_site_url) ?>';
</script>
<script src="<?= $_site_url ?>/ttn.js" defer></script>
