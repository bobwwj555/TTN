<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';

$page_title   = 'Build Log';
$page_section = 'buildlog';

$filter_site = (int)($_GET['site'] ?? 0);
$filter_type = trim($_GET['type'] ?? '');
$page        = max(1, (int)($_GET['p'] ?? 1));
$per_page    = 20;
$offset      = ($page - 1) * $per_page;

$where  = ['b.is_public = 1'];
$params = [];
if ($filter_site) { $where[] = 'b.site_id = ?'; $params[] = $filter_site; }
if ($filter_type) { $where[] = 'b.entry_type = ?'; $params[] = $filter_type; }
$where_sql = implode(' AND ', $where);

$total = db_count("SELECT COUNT(*) FROM buildlog b WHERE $where_sql", $params);
$pages = max(1, ceil($total / $per_page));

$entries = db_rows("
    SELECT b.*, o.callsign AS op_call, o.display_name AS op_name, s.name AS site_name
    FROM buildlog b
    JOIN operators o ON o.id = b.operator_id
    LEFT JOIN sites s ON s.id = b.site_id
    WHERE $where_sql
    ORDER BY b.entry_date DESC, b.id DESC
    LIMIT $per_page OFFSET $offset
", $params);

$all_sites = db_rows("SELECT id, name FROM sites WHERE is_public=1 ORDER BY name");
$types     = db_rows("SELECT DISTINCT entry_type FROM buildlog WHERE is_public=1 ORDER BY entry_type");

$extra_head = '<style>
.bl-wrap{display:grid;grid-template-columns:220px 1fr;min-height:calc(100vh - 46px)}
.bl-sidebar{background:var(--panel);border-right:1px solid var(--border2);padding:1.5rem 0}
.bl-sidebar-hd{font-family:var(--mono);font-size:0.55rem;color:var(--t3);letter-spacing:0.15em;text-transform:uppercase;padding:0 1.2rem 0.5rem}
.bl-sidebar .filter-btn{display:block;font-family:var(--mono);font-size:0.65rem;color:var(--t2);padding:0.45rem 1.2rem;border-left:2px solid transparent;text-decoration:none;transition:all 0.12s}
.bl-sidebar .filter-btn:hover{color:var(--t1);border-left-color:var(--border2);background:var(--panel2)}
.bl-sidebar .filter-btn.active{color:var(--green);border-left-color:var(--green);background:var(--gglow)}
.bl-main{padding:2rem 2.5rem}
.bl-entries{display:flex;flex-direction:column;gap:1px;background:var(--border2);border:1px solid var(--border2)}
.bl-entry{background:var(--panel);padding:1.2rem 1.5rem}
.bl-entry:hover{background:var(--panel2)}
.ble-top{display:flex;align-items:center;gap:0.8rem;flex-wrap:wrap;margin-bottom:0.5rem}
.ble-date{font-family:var(--mono);font-size:0.62rem;color:var(--t3)}
.ble-site{font-family:var(--mono);font-size:0.62rem;color:var(--amber)}
.ble-type{font-family:var(--mono);font-size:0.55rem;letter-spacing:0.1em;text-transform:uppercase;color:var(--t3);border:1px solid var(--border2);padding:0.1rem 0.4rem}
.ble-title{font-family:var(--display);font-weight:700;font-size:0.95rem;color:var(--t1);margin-bottom:0.4rem}
.ble-body{font-size:0.82rem;color:var(--t2);line-height:1.7}
.ble-by{font-family:var(--mono);font-size:0.58rem;color:var(--t3);margin-top:0.5rem}
.bl-pagination{display:flex;gap:0.5rem;margin-top:1.5rem;flex-wrap:wrap}
.bl-pag-btn{font-family:var(--mono);font-size:0.62rem;padding:0.4rem 0.8rem;border:1px solid var(--border2);background:transparent;color:var(--t2);cursor:pointer;text-decoration:none;transition:all 0.12s}
.bl-pag-btn:hover,.bl-pag-btn.active{border-color:var(--green);color:var(--green)}
.bl-empty{padding:3rem;text-align:center;font-family:var(--mono);font-size:0.75rem;color:var(--t3)}
@media(max-width:700px){.bl-wrap{grid-template-columns:1fr}.bl-sidebar{border-right:none;border-bottom:1px solid var(--border2)}.bl-main{padding:1.5rem 5vw}}
</style>';

require_once TTN_INCLUDES . '/header.php';

$site_url = s('site_url', 'https://dev.ttn.radio');
function bl_url(array $params): string {
    global $site_url;
    $q = array_filter($params, fn($v) => $v !== '' && $v !== 0);
    return $site_url . '/buildlog/?' . http_build_query($q);
}
?>
<main style="padding-top:46px">
<div class="bl-wrap">
    <nav class="bl-sidebar">
        <div class="bl-sidebar-hd">Sites</div>
        <a href="<?= bl_url(['type'=>$filter_type]) ?>" class="filter-btn <?= !$filter_site?'active':'' ?>">All Sites</a>
        <?php foreach ($all_sites as $s): ?>
        <a href="<?= bl_url(['site'=>$s['id'],'type'=>$filter_type]) ?>" class="filter-btn <?= $filter_site==$s['id']?'active':'' ?>"><?= htmlspecialchars($s['name']) ?></a>
        <?php endforeach; ?>

        <?php if (!empty($types)): ?>
        <div class="bl-sidebar-hd" style="margin-top:1rem">Type</div>
        <a href="<?= bl_url(['site'=>$filter_site]) ?>" class="filter-btn <?= !$filter_type?'active':'' ?>">All Types</a>
        <?php foreach ($types as $t): ?>
        <a href="<?= bl_url(['site'=>$filter_site,'type'=>$t['entry_type']]) ?>" class="filter-btn <?= $filter_type===$t['entry_type']?'active':'' ?>"><?= htmlspecialchars(ucfirst($t['entry_type'])) ?></a>
        <?php endforeach; ?>
        <?php endif; ?>
    </nav>

    <div class="bl-main">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:0.5rem">
            <div>
                <h1 style="font-family:var(--display);font-weight:700;font-size:1.4rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--t1)">Build Log</h1>
                <div style="font-family:var(--mono);font-size:0.62rem;color:var(--t3);margin-top:0.2rem"><?= $total ?> entries<?= $filter_site ? ' · '.htmlspecialchars($all_sites[array_search($filter_site, array_column($all_sites,'id'))]['name'] ?? '') : '' ?></div>
            </div>
        </div>

        <?php if (!empty($entries)): ?>
        <div class="bl-entries">
        <?php foreach ($entries as $e): ?>
        <div class="bl-entry">
            <div class="ble-top">
                <span class="ble-date"><?= htmlspecialchars($e['entry_date']) ?></span>
                <?php if ($e['site_name']): ?><span class="ble-site"><?= htmlspecialchars($e['site_name']) ?></span><?php endif; ?>
                <span class="ble-type"><?= htmlspecialchars($e['entry_type']) ?></span>
            </div>
            <div class="ble-title"><?= htmlspecialchars($e['title']) ?></div>
            <?php if ($e['body']): ?><div class="ble-body"><?= nl2br(htmlspecialchars($e['body'])) ?></div><?php endif; ?>
            <div class="ble-by">— <?= htmlspecialchars($e['op_call']) ?><?= $e['op_name'] ? ' · '.$e['op_name'] : '' ?></div>
        </div>
        <?php endforeach; ?>
        </div>

        <?php if ($pages > 1): ?>
        <div class="bl-pagination">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a href="<?= bl_url(['site'=>$filter_site,'type'=>$filter_type,'p'=>$i]) ?>" class="bl-pag-btn <?= $i==$page?'active':'' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="bl-empty">No build log entries yet.</div>
        <?php endif; ?>
    </div>
</div>
</main>
<?php require_once TTN_INCLUDES . '/footer.php'; ?>
