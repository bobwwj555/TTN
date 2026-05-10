<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_require_role('viewer');

$adm_title = 'Roadmap';
$adm_page  = 'roadmap';
$is_admin  = ttn_has_role('admin');
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ttn_csrf_verify($_POST['csrf_token'] ?? '');
    $pa = $_POST['post_action'] ?? '';

    if ($pa === 'save') {
        $rid    = (int)($_POST['item_id'] ?? 0);
        $phase  = (int)($_POST['phase']      ?? 1);
        $sort   = (int)($_POST['sort_order'] ?? 0);
        $title  = trim($_POST['title']       ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $type   = trim($_POST['item_type']   ?? 'milestone');
        $status = trim($_POST['status']      ?? 'planned');
        if (!$title) { $err = 'Title required.'; }
        else {
            $data = ['phase'=>$phase,'sort_order'=>$sort,'title'=>$title,'description'=>$desc,'item_type'=>$type,'status'=>$status];
            if ($rid) {
                $sets = implode(',', array_map(fn($k)=>"`$k`=?", array_keys($data)));
                db_execute("UPDATE roadmap_items SET $sets WHERE id=?", [...array_values($data),$rid]);
            } else {
                db_insert('roadmap_items', $data);
            }
            $msg = 'Saved.';
        }
    }

    if ($pa === 'delete' && $is_admin) {
        db_execute("DELETE FROM roadmap_items WHERE id=?", [(int)$_POST['item_id']]);
        $msg = 'Deleted.';
    }
}

$items = db_rows("SELECT * FROM roadmap_items ORDER BY phase, sort_order, id");
$by_phase = [];
foreach ($items as $item) $by_phase[$item['phase']][] = $item;

$statuses  = ['planned','in_progress','done','cancelled'];
$types     = ['milestone','task','goal','note'];

require_once TTN_INCLUDES . '/admin_head.php';
require_once TTN_INCLUDES . '/admin_nav.php';
?>
<div class="adm-main">
<div class="adm-topbar">
    <div class="adm-topbar-title">Roadmap</div>
    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('newItemForm').style.display=document.getElementById('newItemForm').style.display==='none'?'block':'none'">+ Add Item</button>
</div>
<div class="adm-body">

<?php if($msg): ?><div class="msg-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="msg-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- NEW ITEM FORM -->
<div id="newItemForm" style="display:none" class="panel" style="margin-bottom:1.5rem">
    <div class="panel-hd">New Item</div>
    <div class="panel-body">
    <form method="post">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="save">
        <input type="hidden" name="item_id" value="0">
        <div class="field-row3">
            <div class="field"><label>Phase</label>
                <select name="phase"><option value="1">Phase 1</option><option value="2">Phase 2</option><option value="3">Phase 3</option></select>
            </div>
            <div class="field"><label>Status</label>
                <select name="status"><?php foreach($statuses as $s): ?><option value="<?=$s?>"><?=ucfirst(str_replace('_',' ',$s))?></option><?php endforeach; ?></select>
            </div>
            <div class="field"><label>Type</label>
                <select name="item_type"><?php foreach($types as $t): ?><option value="<?=$t?>"><?=ucfirst($t)?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div class="field-row">
            <div class="field"><label>Title *</label><input type="text" name="title" required placeholder="ARDC Phase 1 grant submitted"></div>
            <div class="field"><label>Sort Order</label><input type="number" name="sort_order" value="0" min="0"></div>
        </div>
        <div class="field"><label>Description</label><input type="text" name="description" placeholder="$55,567 requested for tower/solar/repeater hardware"></div>
        <div style="display:flex;gap:0.7rem;margin-top:0.8rem">
            <button type="submit" class="btn btn-primary">Add Item</button>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('newItemForm').style.display='none'">Cancel</button>
        </div>
    </form>
    </div>
</div>

<!-- ITEMS BY PHASE -->
<?php foreach ([1,2,3] as $phase): ?>
<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-hd">Phase <?= $phase ?> (<?= count($by_phase[$phase] ?? []) ?> items)</div>
    <table class="adm-tbl">
        <thead><tr><th>Sort</th><th>Status</th><th>Title</th><th>Description</th><th>Type</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($by_phase[$phase] ?? [] as $item): ?>
        <tr id="rm-row-<?= $item['id'] ?>">
            <td class="mono muted"><?= $item['sort_order'] ?></td>
            <td>
                <?php $sc = match($item['status']) { 'done'=>'var(--green)', 'in_progress'=>'var(--amber)', default=>'var(--t3)' }; ?>
                <span style="font-family:var(--mono);font-size:0.58rem;text-transform:uppercase;letter-spacing:0.08em;color:<?= $sc ?>"><?= str_replace('_',' ',$item['status']) ?></span>
            </td>
            <td><?= htmlspecialchars($item['title']) ?></td>
            <td class="muted" style="font-size:0.72rem"><?= htmlspecialchars($item['description'] ?? '') ?></td>
            <td class="mono muted"><?= htmlspecialchars($item['item_type']) ?></td>
            <td>
                <div class="actions">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleEdit(<?= $item['id'] ?>)">Edit</button>
                    <?php if ($is_admin): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete?')">
                        <?= ttn_csrf_field() ?>
                        <input type="hidden" name="post_action" value="delete">
                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Del</button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <!-- Inline edit row -->
        <tr id="rm-edit-<?= $item['id'] ?>" style="display:none;background:var(--panel2)">
            <td colspan="6" style="padding:0.8rem 1rem">
            <form method="post" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end">
                <?= ttn_csrf_field() ?>
                <input type="hidden" name="post_action" value="save">
                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                <div class="field" style="margin:0"><label>Phase</label>
                    <select name="phase"><?php foreach([1,2,3] as $p): ?><option value="<?=$p?>" <?=$item['phase']==$p?'selected':''?>>Phase <?=$p?></option><?php endforeach; ?></select>
                </div>
                <div class="field" style="margin:0"><label>Status</label>
                    <select name="status"><?php foreach($statuses as $s): ?><option value="<?=$s?>" <?=$item['status']===$s?'selected':''?>><?=ucfirst(str_replace('_',' ',$s))?></option><?php endforeach; ?></select>
                </div>
                <div class="field" style="margin:0"><label>Type</label>
                    <select name="item_type"><?php foreach($types as $t): ?><option value="<?=$t?>" <?=$item['item_type']===$t?'selected':''?>><?=ucfirst($t)?></option><?php endforeach; ?></select>
                </div>
                <div class="field" style="margin:0;min-width:200px"><label>Title</label><input type="text" name="title" value="<?= htmlspecialchars($item['title']) ?>" required></div>
                <div class="field" style="margin:0;min-width:200px"><label>Description</label><input type="text" name="description" value="<?= htmlspecialchars($item['description']??'') ?>"></div>
                <div class="field" style="margin:0;width:60px"><label>Sort</label><input type="number" name="sort_order" value="<?= $item['sort_order'] ?>"></div>
                <div style="align-self:flex-end;display:flex;gap:0.3rem;margin-bottom:0.1rem">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleEdit(<?= $item['id'] ?>)">Cancel</button>
                </div>
            </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($by_phase[$phase])): ?><tr><td colspan="6" class="muted">No items yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php endforeach; ?>

</div>
</div>
</div>
</body>
</html>
<script>
function toggleEdit(id) {
    ['rm-row-','rm-edit-'].forEach((prefix,i) => {
        const el = document.getElementById(prefix+id);
        el.style.display = el.style.display === 'none' ? 'table-row' : 'none';
    });
}
</script>
