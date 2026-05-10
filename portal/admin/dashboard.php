<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_require_role('viewer');

$adm_title = 'Dashboard';
$adm_page  = 'dashboard';
$op        = ttn_current_operator();
$is_admin  = ttn_has_role('admin');

$site_count  = db_count("SELECT COUNT(*) FROM sites");
$live_count  = db_count("SELECT COUNT(*) FROM sites WHERE status='live'");
$build_count = db_count("SELECT COUNT(*) FROM sites WHERE status='building'");
$log_count   = db_count("SELECT COUNT(*) FROM buildlog");
$op_count    = db_count("SELECT COUNT(*) FROM operators WHERE is_active=1");
$asset_count = db_count("SELECT COUNT(*) FROM assets");

$recent_logs = db_rows("
    SELECT b.*, o.callsign AS op_call, s.name AS site_name
    FROM buildlog b
    JOIN operators o ON o.id = b.operator_id
    LEFT JOIN sites s ON s.id = b.site_id
    ORDER BY b.entry_date DESC, b.id DESC LIMIT 8
");

$site_url = s('site_url', 'https://dev.ttn.radio');

require_once TTN_INCLUDES . '/admin_head.php';
require_once TTN_INCLUDES . '/admin_nav.php';
?>
<div class="adm-main">
<div class="adm-topbar">
    <div class="adm-topbar-title">Dashboard</div>
    <div style="font-family:var(--mono);font-size:0.6rem;color:var(--t3)"><?= date('Y-m-d H:i') ?> · <?= htmlspecialchars($op['callsign'] ?? '') ?></div>
</div>
<div class="adm-body">

<!-- STAT CARDS -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:1px;background:var(--border2);border:1px solid var(--border2);margin-bottom:2rem">
<?php foreach ([
    ['Sites Total', $site_count,  'var(--t1)'],
    ['Live',        $live_count,  'var(--green)'],
    ['Building',    $build_count, 'var(--amber)'],
    ['Log Entries', $log_count,   'var(--t1)'],
    ['Operators',   $op_count,    'var(--t1)'],
    ['Assets',      $asset_count, 'var(--t1)'],
] as [$label, $val, $color]): ?>
<div style="background:var(--panel);padding:1.2rem;text-align:center">
    <div style="font-family:var(--display);font-weight:800;font-size:2rem;color:<?= $color ?>;line-height:1"><?= $val ?></div>
    <div style="font-family:var(--mono);font-size:0.52rem;color:var(--t3);letter-spacing:0.12em;text-transform:uppercase;margin-top:0.2rem"><?= $label ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- QUICK ACTIONS -->
<div class="panel" style="margin-bottom:2rem">
    <div class="panel-hd">Quick Actions</div>
    <div class="panel-body" style="display:flex;gap:0.7rem;flex-wrap:wrap">
        <a href="<?= $site_url ?>/admin/buildlog.php?action=new" class="btn btn-primary">+ Build Log Entry</a>
        <a href="<?= $site_url ?>/admin/sites.php" class="btn btn-secondary">Edit Sites</a>
        <a href="<?= $site_url ?>/admin/team.php" class="btn btn-secondary">Edit Team</a>
        <a href="<?= $site_url ?>/admin/pages.php" class="btn btn-secondary">Edit Docs</a>
        <a href="<?= $site_url ?>/admin/roadmap.php" class="btn btn-secondary">Edit Roadmap</a>
        <?php if ($is_admin): ?>
        <a href="<?= $site_url ?>/admin/settings.php" class="btn btn-secondary">Settings</a>
        <?php endif; ?>
        <a href="<?= $site_url ?>/" target="_blank" class="btn btn-secondary">View Site ↗</a>
    </div>
</div>

<!-- RECENT BUILD LOG -->
<div class="panel">
    <div class="panel-hd">
        Recent Build Log
        <a href="<?= $site_url ?>/admin/buildlog.php" style="font-family:var(--mono);font-size:0.58rem;color:var(--green);text-decoration:none;letter-spacing:0.08em;text-transform:uppercase">View All ›</a>
    </div>
    <table class="adm-tbl">
        <thead><tr><th>Date</th><th>Site</th><th>Type</th><th>Title</th><th>By</th><th>Public</th></tr></thead>
        <tbody>
        <?php foreach ($recent_logs as $log): ?>
        <tr>
            <td class="mono muted"><?= htmlspecialchars($log['entry_date']) ?></td>
            <td class="mono"><?= htmlspecialchars($log['site_name'] ?? '—') ?></td>
            <td><span style="font-family:var(--mono);font-size:0.58rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--amber)"><?= htmlspecialchars($log['entry_type']) ?></span></td>
            <td><?= htmlspecialchars($log['title']) ?></td>
            <td class="mono muted"><?= htmlspecialchars($log['op_call']) ?></td>
            <td><?= $log['is_public'] ? '<span style="color:var(--green)">✓</span>' : '<span style="color:var(--t3)">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recent_logs)): ?><tr><td colspan="6" class="muted">No entries yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

</div>
</div>
</div>
</body>
</html>
