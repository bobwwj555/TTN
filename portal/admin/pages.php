<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_require_role('operator');

$adm_title = 'Pages';
$adm_page  = 'pages';
$my_id     = $_SESSION['operator_id'] ?? 0;
$is_admin  = ttn_has_role('admin');
$msg = $err = '';
$action  = $_GET['action'] ?? 'list';
$edit_id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ttn_csrf_verify($_POST['csrf_token'] ?? '');
    $pa = $_POST['post_action'] ?? '';

    if ($pa === 'save') {
        $post_id   = (int)($_POST['page_id'] ?? 0);
        $slug      = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['slug'] ?? '')));
        $title     = trim($_POST['title']     ?? '');
        $nav_label = trim($_POST['nav_label'] ?? '');
        $nav_group = trim($_POST['nav_group'] ?? '');
        $body      = $_POST['body']           ?? '';
        $is_pub    = isset($_POST['is_published']) ? 1 : 0;
        $sort      = (int)($_POST['sort_order'] ?? 0);

        if (!$slug || !$title) { $err = 'Slug and title are required.'; }
        else {
            $data = [
                'slug'        => $slug,
                'title'       => $title,
                'nav_label'   => $nav_label,
                'nav_group'   => $nav_group ?: null,
                'body'        => $body,
                'is_published'=> $is_pub,
                'sort_order'  => $sort,
                'updated_by'  => $my_id,
            ];
            if ($post_id) {
                $sets = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
                db_execute("UPDATE pages SET $sets WHERE id=?", [...array_values($data), $post_id]);
                $msg = 'Page updated.';
            } else {
                $existing = db_row("SELECT id FROM pages WHERE slug=?", [$slug]);
                if ($existing) { $err = "Slug '$slug' already exists."; }
                else { db_insert('pages', $data); $msg = 'Page created.'; }
            }
            if (!$err) $action = 'list';
        }
    }

    if ($pa === 'toggle_published') {
        $pid = (int)$_POST['page_id'];
        db_execute("UPDATE pages SET is_published = NOT is_published WHERE id=?", [$pid]);
    }

    if ($pa === 'delete' && $is_admin) {
        $pid = (int)$_POST['page_id'];
        db_execute("DELETE FROM pages WHERE id=?", [$pid]);
        $msg = 'Page deleted.';
        $action = 'list';
    }
}

$edit_page = null;
if (($action === 'edit' || $action === 'new') && $edit_id) {
    $edit_page = db_row("SELECT * FROM pages WHERE id=?", [$edit_id]);
    if (!$edit_page) $action = 'list';
}
if ($action === 'new') $edit_page = [];

$pages    = db_rows("SELECT * FROM pages ORDER BY nav_group, sort_order, title");
$nav_groups = ['about'=>'About','network'=>'Network','operators'=>'Operators'];
$site_url = s('site_url', 'https://dev.ttn.radio');

require_once TTN_INCLUDES . '/admin_head.php';
require_once TTN_INCLUDES . '/admin_nav.php';
?>
<style>
.EasyMDEContainer .CodeMirror{background:#12141a;border:1px solid #1f2330;color:#e2e8f0;font-family:'Share Tech Mono',monospace;font-size:0.82rem}
.EasyMDEContainer .CodeMirror-scroll{min-height:400px}
.editor-toolbar{background:#0d0f14;border:1px solid #1f2330;border-bottom:none}
.editor-toolbar a,.editor-toolbar button{color:#a0aec0!important}
.editor-toolbar a:hover,.editor-toolbar a.active{background:#1a1d23!important;color:#39ff14!important;border-color:#2a2e38!important}
.editor-toolbar i.separator{border-color:#2a2e38}
.editor-statusbar{background:#12141a;color:#4a5568;border:1px solid #2a2e38;border-top:none}
.editor-preview{background:#1a1d23;color:#e2e8f0}
.CodeMirror-cursor{border-left-color:#39ff14}
</style>
<div class="adm-main">
<div class="adm-topbar">
    <div class="adm-topbar-title">
        <?php if ($edit_page !== null): ?>
        <a href="pages.php" style="color:var(--t3);font-size:0.72rem;text-decoration:none">Pages</a> /
        <?= $edit_id ? htmlspecialchars($edit_page['title'] ?? 'Edit') : 'New Page' ?>
        <?php else: ?>Pages<?php endif; ?>
    </div>
    <a href="?action=new&id=0" class="btn btn-primary btn-sm">+ New Page</a>
</div>
<div class="adm-body">

<?php if($msg): ?><div class="msg-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="msg-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if ($edit_page !== null): ?>
<div class="panel">
    <div class="panel-hd"><?= $edit_id ? 'Edit Page' : 'New Page' ?></div>
    <div class="panel-body">
    <form method="post" id="pageForm">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="save">
        <input type="hidden" name="page_id" value="<?= $edit_id ?>">

        <div class="field-row3">
            <div class="field">
                <label>Slug (URL: /docs/?page=slug)</label>
                <input type="text" name="slug" value="<?= htmlspecialchars($edit_page['slug'] ?? '') ?>" placeholder="connect" required>
            </div>
            <div class="field">
                <label>Page Title</label>
                <input type="text" name="title" value="<?= htmlspecialchars($edit_page['title'] ?? '') ?>" placeholder="Connect to TTN" required>
            </div>
            <div class="field">
                <label>Nav Label (short)</label>
                <input type="text" name="nav_label" value="<?= htmlspecialchars($edit_page['nav_label'] ?? '') ?>" placeholder="Connect">
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label>Nav Group</label>
                <select name="nav_group">
                    <option value="">— none —</option>
                    <?php foreach ($nav_groups as $gk => $gl): ?>
                    <option value="<?= $gk ?>" <?= ($edit_page['nav_group'] ?? '') === $gk ? 'selected' : '' ?>><?= $gl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Sort Order</label>
                <input type="number" name="sort_order" value="<?= (int)($edit_page['sort_order'] ?? 0) ?>" min="0" max="99" style="width:80px">
            </div>
        </div>

        <div class="field">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.4rem">
                <label style="margin:0">Body (Markdown)</label>
                <button type="button" id="rawToggleBtn" class="btn btn-secondary btn-sm" onclick="toggleRaw()">Raw Mode</button>
            </div>
            <textarea name="body" id="pageBody"><?= htmlspecialchars($edit_page['body'] ?? '') ?></textarea>
            <textarea id="rawBody" style="display:none;width:100%;min-height:400px;background:#1a1d23;color:#e2e8f0;font-family:monospace;font-size:0.82rem;padding:1rem;border:1px solid #2a2e38;box-sizing:border-box;resize:vertical"></textarea>
        </div>

        <div class="check-row">
            <input type="checkbox" name="is_published" id="is_published" <?= ($edit_page['is_published'] ?? 0) ? 'checked' : '' ?>>
            <label for="is_published">Published (visible on docs)</label>
        </div>

        <div style="display:flex;gap:0.7rem;margin-top:1rem">
            <button type="submit" class="btn btn-primary">Save Page</button>
            <a href="pages.php" class="btn btn-secondary">Cancel</a>
            <?php if ($edit_id): ?>
            <a href="<?= $site_url ?>/docs/?page=<?= htmlspecialchars($edit_page['slug'] ?? '') ?>" target="_blank" class="btn btn-secondary">Preview ↗</a>
            <?php endif; ?>
        </div>
    </form>
    </div>
</div>

<?php else: ?>
<div class="panel">
    <div class="panel-hd">All Pages (<?= count($pages) ?>)</div>
    <table class="adm-tbl">
        <thead><tr><th>Slug</th><th>Title</th><th>Group</th><th>Sort</th><th>Nav Label</th><th>Published</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pages as $p): ?>
        <tr>
            <td class="mono"><?= htmlspecialchars($p['slug']) ?></td>
            <td><?= htmlspecialchars($p['title']) ?></td>
            <td class="mono muted"><?= htmlspecialchars($p['nav_group'] ?? '—') ?></td>
            <td class="mono muted"><?= $p['sort_order'] ?></td>
            <td class="muted"><?= htmlspecialchars($p['nav_label'] ?? '') ?></td>
            <td>
                <form method="post" style="display:inline">
                    <?= ttn_csrf_field() ?>
                    <input type="hidden" name="post_action" value="toggle_published">
                    <input type="hidden" name="page_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="background:none;border:none;cursor:pointer;color:<?= $p['is_published']?'var(--green)':'var(--t3)' ?>">
                        <?= $p['is_published'] ? '✓ Published' : '— Draft' ?>
                    </button>
                </form>
            </td>
            <td>
                <div class="actions">
                    <a href="?action=edit&id=<?= $p['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                    <a href="<?= $site_url ?>/docs/?page=<?= htmlspecialchars($p['slug']) ?>" target="_blank" class="btn btn-secondary btn-sm">View</a>
                    <?php if ($is_admin): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this page?')">
                        <?= ttn_csrf_field() ?>
                        <input type="hidden" name="post_action" value="delete">
                        <input type="hidden" name="page_id" value="<?= $p['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Del</button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

</div>
</div>
</div>
</body>
</html>

<script src="https://cdn.jsdelivr.net/npm/easymde@2/dist/easymde.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2/dist/easymde.min.css">
<script>
let easyMDE = null;
let rawMode = false;

<?php if ($edit_page !== null): ?>
easyMDE = new EasyMDE({
    element: document.getElementById('pageBody'),
    spellChecker: false,
    autosave: { enabled: false },
    toolbar: ['bold','italic','heading','|','quote','code','table','|','link','|','preview','guide'],
    minHeight: '400px',
    status: false,
});

function toggleRaw() {
    rawMode = !rawMode;
    const rawArea = document.getElementById('rawBody');
    const btn     = document.getElementById('rawToggleBtn');
    if (rawMode) {
        rawArea.value = easyMDE.value();
        rawArea.style.display = 'block';
        document.querySelector('.EasyMDEContainer').style.display = 'none';
        btn.textContent = 'Editor Mode';
    } else {
        easyMDE.value(rawArea.value);
        rawArea.style.display = 'none';
        document.querySelector('.EasyMDEContainer').style.display = '';
        btn.textContent = 'Raw Mode';
    }
}

document.getElementById('pageForm').addEventListener('submit', function() {
    if (rawMode) {
        easyMDE.value(document.getElementById('rawBody').value);
    }
});
<?php endif; ?>
</script>
