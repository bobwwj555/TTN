<?php
/**
 * TTN Admin Sidebar Nav
 * LOCATION: /home/obdswlpx/dev.ttn.radio/includes/admin_nav.php
 */
$_site_url = s('site_url', 'https://dev.ttn.radio');
$_op       = ttn_current_operator();
$_is_admin = ttn_has_role('admin');
$adm_page  = $adm_page ?? '';

function _nav_link(string $href, string $label, string $page, string $cur): string {
    $active = $cur === $page ? ' class="active"' : '';
    return "<a href=\"$href\"$active>$label</a>\n";
}
?>
<aside class="adm-sidebar">
    <div class="adm-brand">TTN<span>Admin Panel</span></div>
    <nav class="adm-nav">
        <div class="adm-nav-section">Main</div>
        <?= _nav_link("$_site_url/admin/dashboard.php", 'Dashboard', 'dashboard', $adm_page) ?>
        <?= _nav_link("$_site_url/admin/sites.php",     'Sites',     'sites',     $adm_page) ?>
        <?= _nav_link("$_site_url/admin/buildlog.php",  'Build Log', 'buildlog',  $adm_page) ?>
        <?= _nav_link("$_site_url/admin/network.php",      'Network',      'network',      $adm_page) ?>
        <?= _nav_link("$_site_url/admin/node-builder.php", 'Node Builder', 'node-builder', $adm_page) ?>

        <div class="adm-nav-section">Content</div>
        <?= _nav_link("$_site_url/admin/pages.php",    'Pages',    'pages',    $adm_page) ?>
        <?= _nav_link("$_site_url/admin/roadmap.php",  'Roadmap',  'roadmap',  $adm_page) ?>
        <?= _nav_link("$_site_url/admin/assets.php",   'Assets',   'assets',   $adm_page) ?>
        <?= _nav_link("$_site_url/admin/media.php",    'Media',    'media',    $adm_page) ?>

        <div class="adm-nav-section">People</div>
        <?= _nav_link("$_site_url/admin/team.php",      'Team',      'team',      $adm_page) ?>
        <?php if ($_is_admin): ?>
        <?= _nav_link("$_site_url/admin/operators.php", 'Operators', 'operators', $adm_page) ?>
        <?php endif; ?>

        <?php if ($_is_admin): ?>
        <div class="adm-nav-section">System</div>
        <?= _nav_link("$_site_url/admin/settings.php", 'Settings', 'settings', $adm_page) ?>
        <?= _nav_link("$_site_url/admin/webui-credentials.php", 'Web UI Creds', 'webui-credentials', $adm_page) ?>
        <?= _nav_link("$_site_url/admin/operators.php?action=resets", 'Password Resets', 'resets', $adm_page) ?>
        <?php endif; ?>
    </nav>
    <div class="adm-footer">
        <?php if ($_op): ?>
        <div style="color:var(--t2);margin-bottom:0.4rem"><?= htmlspecialchars($_op['callsign']) ?></div>
        <?php endif; ?>
        <a href="<?= $_site_url ?>/admin/logout.php">Log Out</a> ·
        <a href="<?= $_site_url ?>/" target="_blank">View Site</a>
    </div>
</aside>
