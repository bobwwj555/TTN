<?php
require_once '/home/obdswlpx/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_require_role('operator');

$adm_title = 'Build Log';
$adm_page  = 'buildlog';
$my_id     = $_SESSION['operator_id'] ?? 0;
$is_admin  = ttn_has_role('admin');
$msg = $err = '';
$action  = $_GET['action'] ?? 'list';
$edit_id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ttn_csrf_verify($_POST['csrf_token'] ?? '');
    $pa = $_POST['post_action'] ?? '';

    if ($pa === 'save') {
        $post_id    = (int)($_POST['log_id'] ?? 0);
        $site_id    = (int)($_POST['site_id'] ?? 0) ?: null;
        $entry_date = trim($_POST['entry_date'] ?? date('Y-m-d'));
        $entry_type = trim($_POST['entry_type'] ?? 'update');
        $title      = trim($_POST['title']      ?? '');
        $body       = trim($_POST['body']       ?? '');
        $is_public  = isset($_POST['is_public']) ? 1 : 0;

        if (!$title) { $err = 'Title is required.'; }
        else {
            if ($post_id) {
                $existing = db_row("SELECT operator_id FROM buildlog WHERE id=?", [$post_id]);
                if ($existing && ($is_admin || $existing['operator_id'] == $my_id)) {
                    db_execute("UPDATE buildlog SET site_id=?,entry_date=?,entry_type=?,title=?,body=?,is_public=? WHERE id=?",
                        [$site_id,$entry_date,$entry_type,$title,$body,$is_public,$post_id]);
                    $msg = 'Entry updated.';
                } else { $err = 'Permission denied.'; }
            } else {
                db_insert('buildlog', [
                    'site_id'     => $site_id,
                    'operator_id' => $my_id,
                    'entry_date'  => $entry_date,
                    'entry_type'  => $entry_type,
                    'title'       => $title,
                    'body'        => $body,
                    'is_public'   => $is_public,
                ]);
                $msg = 'Entry added.';
            }
            $action = 'list';
        }
    }

    if ($pa === 'delete' && $is_admin) {
        $did = (int)$_POST['log_id'];
        db_execute("DELETE FROM buildlog WHERE id=?", [$did]);
        $msg = 'Entry deleted.';
        $action = 'list';
    }

    if ($pa === 'toggle_public') {
        $did = (int)$_POST['log_id'];
        db_execute("UPDATE buildlog SET is_public = NOT is_public WHERE id=?", [$did]);
        $action = 'list';
    }
}

$edit_entry = null;
if ($action === 'edit' && $edit_id) {
    $edit_entry = db_row("SELECT * FROM buildlog WHERE id=?", [$edit_id]);
    if (!$edit_entry) $action = 'list';
}
if ($action === 'new') {
    $edit_entry = [];
}

$filter_site = (int)($_GET['site'] ?? 0);
$page        = max(1, (int)($_GET['p'] ?? 1));
$per_page    = 25;
$offset      = ($page - 1) * $per_page;

$where_clauses = [];
$where_params  = [];
if (!$is_admin) { $where_clauses[] = 'b.operator_id = ?'; $where_params[] = $my_id; }
if ($filter_site) { $where_clauses[] = 'b.site_id = ?'; $where_params[] = $filter_site; }
$where_sql = $where_clauses ? 'WHERE '.implode(' AND ', $where_clauses) : '';

$total   = db_count("SELECT COUNT(*) FROM buildlog b $where_sql", $where_params);
$pages   = max(1, ceil($total / $per_page));
$entries = db_rows("
    SELECT b.*, o.callsign AS op_call, s.name AS site_name
    FROM buildlog b
    JOIN operators o ON o.id = b.operator_id
    LEFT JOIN sites s ON s.id = b.site_id
    $where_sql
    ORDER BY b.entry_date DESC, b.id DESC
    LIMIT $per_page OFFSET $offset
", $where_params);

$all_sites = db_rows("SELECT id, name FROM sites ORDER BY name");
$entry_types = ['update','install','repair','survey','planning','milestone','other'];
$site_url = s('site_url', 'https://dev.ttn.radio');

require_once TTN_INCLUDES . '/admin_head.php';
require_once TTN_INCLUDES . '/admin_nav.php';
?>
<div class="adm-main">
<div class="adm-topbar">
    <div class="adm-topbar-title">Build Log</div>
    <a href="?action=new" class="btn btn-primary btn-sm">+ New Entry</a>
</div>
<div class="adm-body">

<?php if($msg): ?><div class="msg-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="msg-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if ($action === 'edit' || $action === 'new'): ?>
<!-- EDIT / NEW FORM -->
<div class="panel">
    <div class="panel-hd"><?= $edit_id ? 'Edit Entry' : 'New Entry' ?> <a href="buildlog.php" class="btn btn-secondary btn-sm">← Back</a></div>
    <div class="panel-body">
    <form method="post">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="save">
        <input type="hidden" name="log_id" value="<?= $edit_id ?>">
        <div class="field-row3">
            <div class="field">
                <label>Site</label>
                <select name="site_id">
                    <option value="">— General / No Site —</option>
                    <?php foreach ($all_sites as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= ($edit_entry['site_id']??0)==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Date</label>
                <input type="date" name="entry_date" value="<?= htmlspecialchars($edit_entry['entry_date'] ?? date('Y-m-d')) ?>" required>
            </div>
            <div class="field">
                <label>Type</label>
                <select name="entry_type">
                    <?php foreach ($entry_types as $t): ?>
                    <option value="<?= $t ?>" <?= ($edit_entry['entry_type']??'')===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field">
            <label>Title *</label>
            <input type="text" name="title" value="<?= htmlspecialchars($edit_entry['title'] ?? '') ?>" required placeholder="Installed duplexer at Piedmont">
        </div>
        <div class="field">
            <label>Body</label>
            <textarea name="body" rows="8"><?= htmlspecialchars($edit_entry['body'] ?? '') ?></textarea>
        </div>
        <div class="check-row">
            <input type="checkbox" name="is_public" id="is_public" <?= ($edit_entry['is_public']??1)?'checked':'' ?>>
            <label for="is_public">Public (visible on site)</label>
        </div>
        <div style="display:flex;gap:0.7rem;margin-top:1rem">
            <button type="submit" class="btn btn-primary">Save Entry</button>
            <a href="buildlog.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
    </div>
</div>

<?php else: ?>
<!-- FILTER + LIST -->
<div style="display:flex;gap:0.5rem;margin-bottom:1rem;flex-wrap:wrap">
    <a href="buildlog.php" class="btn btn-sm <?= !$filter_site?'btn-primary':'btn-secondary' ?>">All Sites</a>
    <?php foreach ($all_sites as $s): ?>
    <a href="?site=<?= $s['id'] ?>" class="btn btn-sm <?= $filter_site==$s['id']?'btn-primary':'btn-secondary' ?>"><?= htmlspecialchars($s['name']) ?></a>
    <?php endforeach; ?>
</div>

<div class="panel">
    <div class="panel-hd">Entries (<?= $total ?>)</div>
    <table class="adm-tbl">
        <thead><tr><th>Date</th><th>Site</th><th>Type</th><th>Title</th><th>By</th><th>Public</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($entries as $log): ?>
        <tr>
            <td class="mono muted"><?= htmlspecialchars($log['entry_date']) ?></td>
            <td class="mono"><?= htmlspecialchars($log['site_name'] ?? '—') ?></td>
            <td><span style="font-family:var(--mono);font-size:0.58rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--amber)"><?= htmlspecialchars($log['entry_type']) ?></span></td>
            <td><?= htmlspecialchars($log['title']) ?></td>
            <td class="mono muted"><?= htmlspecialchars($log['op_call']) ?></td>
            <td>
                <form method="post" style="display:inline">
                    <?= ttn_csrf_field() ?>
                    <input type="hidden" name="post_action" value="toggle_public">
                    <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="color:<?= $log['is_public']?'var(--green)':'var(--t3)' ?>;background:none;border:none;cursor:pointer">
                        <?= $log['is_public'] ? '✓' : '—' ?>
                    </button>
                </form>
            </td>
            <td>
                <div class="actions">
                    <?php if ($is_admin || $log['operator_id'] == $my_id): ?>
                    <a href="?action=edit&id=<?= $log['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                    <?php endif; ?>
                    <?php if ($is_admin): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this entry?')">
                        <?= ttn_csrf_field() ?>
                        <input type="hidden" name="post_action" value="delete">
                        <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Del</button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($entries)): ?><tr><td colspan="7" class="muted">No entries.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php if ($pages > 1): ?>
    <div style="padding:0.8rem 1rem;display:flex;gap:0.4rem;flex-wrap:wrap">
        <?php for ($i=1;$i<=$pages;$i++): ?>
        <a href="?site=<?= $filter_site ?>&p=<?= $i ?>" class="btn btn-sm <?= $i==$page?'btn-primary':'btn-secondary' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

</div>
</div>
</div>
</body>
</html>
