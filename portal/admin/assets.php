<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_require_role('operator');

$adm_title = 'Assets';
$adm_page  = 'assets';
$is_admin  = ttn_has_role('admin');
$my_id     = $_SESSION['operator_id'] ?? 0;
$msg = $err = '';
$action  = $_GET['action'] ?? 'list';
$edit_id = (int)($_GET['id'] ?? 0);
$filter_site = (int)($_GET['site'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ttn_csrf_verify($_POST['csrf_token'] ?? '');
    $pa = $_POST['post_action'] ?? '';

    if ($pa === 'save') {
        $aid     = (int)($_POST['asset_id'] ?? 0);
        $site_id = (int)($_POST['site_id']  ?? 0) ?: null;
        $data = [
            'site_id'      => $site_id,
            'category'     => trim($_POST['category']     ?? ''),
            'make'         => trim($_POST['make']         ?? '') ?: null,
            'model'        => trim($_POST['model']        ?? '') ?: null,
            'serial_number'=> trim($_POST['serial_number']?? '') ?: null,
            'description'  => trim($_POST['description']  ?? ''),
            'condition'    => trim($_POST['condition']     ?? 'good'),
            'location_note'=> trim($_POST['location_note']?? '') ?: null,
            'is_active'    => isset($_POST['is_active']) ? 1 : 0,
            'notes'        => trim($_POST['notes']        ?? ''),
        ];
        if ($aid) {
            $sets = implode(',', array_map(fn($k)=>"`$k`=?", array_keys($data)));
            db_execute("UPDATE assets SET $sets WHERE id=?", [...array_values($data), $aid]);
            $msg = 'Asset updated.';
        } else {
            db_insert('assets', $data);
            $msg = 'Asset added.';
        }
        $action = 'list';
    }

    if ($pa === 'delete' && $is_admin) {
        db_execute("DELETE FROM assets WHERE id=?", [(int)$_POST['asset_id']]);
        $msg = 'Asset deleted.';
        $action = 'list';
    }
}

$edit_asset = null;
if (($action === 'edit' || $action === 'new') && $edit_id) {
    $edit_asset = db_row("SELECT * FROM assets WHERE id=?", [$edit_id]);
    if (!$edit_asset && $action === 'edit') $action = 'list';
}
if ($action === 'new') $edit_asset = [];

$site_cond = $filter_site ? 'WHERE a.site_id=?' : '';
$site_params = $filter_site ? [$filter_site] : [];
$assets = db_rows("
    SELECT a.*, s.name AS site_name
    FROM assets a
    LEFT JOIN sites s ON s.id = a.site_id
    $site_cond
    ORDER BY s.name, a.category, a.make
", $site_params);

$all_sites  = db_rows("SELECT id, name FROM sites ORDER BY name");
$conditions = ['new','good','fair','needs_repair','retired'];
$categories = ['radio','antenna','feedline','power','tower','computer','test_equipment','misc'];

require_once TTN_INCLUDES . '/admin_head.php';
require_once TTN_INCLUDES . '/admin_nav.php';
?>
<div class="adm-main">
<div class="adm-topbar">
    <div class="adm-topbar-title">Assets</div>
    <a href="?action=new&id=0" class="btn btn-primary btn-sm">+ Add Asset</a>
</div>
<div class="adm-body">

<?php if($msg): ?><div class="msg-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="msg-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if ($edit_asset !== null): ?>
<div class="panel">
    <div class="panel-hd"><?= $edit_id ? 'Edit Asset' : 'New Asset' ?> <a href="assets.php" class="btn btn-secondary btn-sm">← Back</a></div>
    <div class="panel-body">
    <form method="post">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="save">
        <input type="hidden" name="asset_id" value="<?= $edit_id ?>">
        <div class="field-row3">
            <div class="field"><label>Site</label>
                <select name="site_id">
                    <option value="">— No Site —</option>
                    <?php foreach ($all_sites as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= ($edit_asset['site_id']??0)==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Category</label>
                <select name="category">
                    <?php foreach ($categories as $c): ?>
                    <option value="<?= $c ?>" <?= ($edit_asset['category']??'')===$c?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$c)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>Condition</label>
                <select name="condition">
                    <?php foreach ($conditions as $c): ?>
                    <option value="<?= $c ?>" <?= ($edit_asset['condition']??'good')===$c?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$c)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field-row3">
            <div class="field"><label>Make</label><input type="text" name="make" value="<?= htmlspecialchars($edit_asset['make']??'') ?>" placeholder="Motorola"></div>
            <div class="field"><label>Model</label><input type="text" name="model" value="<?= htmlspecialchars($edit_asset['model']??'') ?>" placeholder="CDM1250"></div>
            <div class="field"><label>Serial Number</label><input type="text" name="serial_number" value="<?= htmlspecialchars($edit_asset['serial_number']??'') ?>"></div>
        </div>
        <div class="field"><label>Description</label><input type="text" name="description" value="<?= htmlspecialchars($edit_asset['description']??'') ?>" placeholder="VHF mobile radio, programmed for TTN"></div>
        <div class="field"><label>Location Note</label><input type="text" name="location_note" value="<?= htmlspecialchars($edit_asset['location_note']??'') ?>" placeholder="In equipment cabinet at Piedmont"></div>
        <div class="field"><label>Notes</label><textarea name="notes" rows="3"><?= htmlspecialchars($edit_asset['notes']??'') ?></textarea></div>
        <div class="check-row">
            <input type="checkbox" name="is_active" id="is_active" <?= ($edit_asset['is_active']??1)?'checked':'' ?>>
            <label for="is_active">Active / In service</label>
        </div>
        <div style="display:flex;gap:0.7rem;margin-top:1rem">
            <button type="submit" class="btn btn-primary">Save Asset</button>
            <a href="assets.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
    </div>
</div>

<?php else: ?>
<!-- FILTER + LIST -->
<div style="display:flex;gap:0.5rem;margin-bottom:1rem;flex-wrap:wrap">
    <a href="assets.php" class="btn btn-sm <?= !$filter_site?'btn-primary':'btn-secondary' ?>">All Sites</a>
    <?php foreach ($all_sites as $s): ?>
    <a href="?site=<?= $s['id'] ?>" class="btn btn-sm <?= $filter_site==$s['id']?'btn-primary':'btn-secondary' ?>"><?= htmlspecialchars($s['name']) ?></a>
    <?php endforeach; ?>
</div>

<div class="panel">
    <div class="panel-hd">Assets (<?= count($assets) ?>)</div>
    <table class="adm-tbl">
        <thead><tr><th>Site</th><th>Category</th><th>Make / Model</th><th>Serial</th><th>Condition</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($assets as $a): ?>
        <tr style="<?= !$a['is_active']?'opacity:0.5':'' ?>">
            <td class="mono"><?= htmlspecialchars($a['site_name'] ?? '—') ?></td>
            <td><span style="font-family:var(--mono);font-size:0.6rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--amber)"><?= str_replace('_',' ',$a['category']) ?></span></td>
            <td><?= htmlspecialchars(trim(($a['make']??'').' '.($a['model']??''))) ?><small style="display:block;color:var(--t3);font-size:0.68rem"><?= htmlspecialchars($a['description']??'') ?></small></td>
            <td class="mono muted"><?= htmlspecialchars($a['serial_number'] ?? '—') ?></td>
            <td class="muted" style="font-size:0.72rem"><?= str_replace('_',' ',$a['condition']??'') ?></td>
            <td><?= $a['is_active']?'<span style="color:var(--green)">✓</span>':'<span style="color:var(--t3)">—</span>' ?></td>
            <td>
                <div class="actions">
                    <a href="?action=edit&id=<?= $a['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                    <?php if ($is_admin): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete?')">
                        <?= ttn_csrf_field() ?>
                        <input type="hidden" name="post_action" value="delete">
                        <input type="hidden" name="asset_id" value="<?= $a['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Del</button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($assets)): ?><tr><td colspan="7" class="muted">No assets yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

</div>
</div>
</div>
</body>
</html>
