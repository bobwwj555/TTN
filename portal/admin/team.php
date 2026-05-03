<?php
require_once '/home/obdswlpx/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_require_role('operator');

$adm_title = 'Team';
$adm_page  = 'team';
$my_id     = $_SESSION['operator_id'] ?? 0;
$is_admin  = ttn_has_role('admin');
$msg = $err = '';
$action  = $_GET['action'] ?? 'list';
$edit_id = (int)($_GET['id'] ?? 0);

if (!$is_admin && $edit_id && $edit_id !== $my_id) {
    $err = 'You can only edit your own profile.';
    $action = 'list';
}

// ── POST HANDLERS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ttn_csrf_verify($_POST['csrf_token'] ?? '');
    $pa = $_POST['post_action'] ?? '';

    // ── SAVE PROFILE ─────────────────────────────────────────
    if ($pa === 'save_profile') {
        $pid = (int)$_POST['op_id'];
        if (!$is_admin && $pid !== $my_id) { $err = 'Permission denied.'; }
        else {
            $photo_url = trim($_POST['photo_url'] ?? '') ?: null;
            if (!empty($_FILES['photo_file']['name']) && $_FILES['photo_file']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
                $op_call = db_row("SELECT callsign FROM operators WHERE id=?", [$pid]);
                if (in_array($_FILES['photo_file']['type'], $allowed) && $_FILES['photo_file']['size'] < 3*1024*1024) {
                    $ext   = strtolower(pathinfo($_FILES['photo_file']['name'], PATHINFO_EXTENSION));
                    $fname = 'op_' . strtolower(preg_replace('/[^a-z0-9]/','', $op_call['callsign'] ?? 'op')) . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['photo_file']['tmp_name'], '/home/obdswlpx/dev.ttn.radio/assets/img/'.$fname))
                        $photo_url = '/assets/img/' . $fname;
                }
            }
            $common = [
                'display_name' => trim($_POST['display_name'] ?? ''),
                'email'        => trim($_POST['email']        ?? '') ?: null,
                'phone'        => trim($_POST['phone']        ?? '') ?: null,
                'bio'          => trim($_POST['bio']          ?? ''),
                'qrz_url'      => trim($_POST['qrz_url']      ?? '') ?: null,
                'photo_url'    => $photo_url,
                'city'         => trim($_POST['city']         ?? '') ?: null,
                'state'        => trim($_POST['state']        ?? '') ?: null,
                'is_public'    => isset($_POST['is_public']) ? 1 : 0,
            ];
            if ($is_admin) {
                $common['role']       = trim($_POST['role'] ?? 'operator');
                $common['is_active']  = isset($_POST['is_active']) ? 1 : 0;
                $common['sort_order'] = (int)($_POST['sort_order'] ?? 0);
            }
            $sets = implode(',', array_map(fn($k) => "`$k`=?", array_keys($common)));
            db_execute("UPDATE operators SET $sets WHERE id=?", [...array_values($common), $pid]);
            $msg = 'Profile updated.';
            $action = 'edit'; $edit_id = $pid;
        }
    }

    // ── SAVE RADIO ID ─────────────────────────────────────────
    if ($pa === 'save_radio_id') {
        $op_id = (int)$_POST['rid_op_id'];
        if (!$is_admin && $op_id !== $my_id) { $err = 'Permission denied.'; }
        else {
            $rid = (int)($_POST['radio_id_row'] ?? 0);
            $data = [
                'operator_id' => $op_id,
                'mode'        => trim($_POST['rid_mode']  ?? 'DMR'),
                'radio_id'    => trim($_POST['rid_value'] ?? ''),
                'notes'       => trim($_POST['rid_notes'] ?? '') ?: null,
            ];
            if ($rid) {
                $sets = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
                db_execute("UPDATE operator_radio_ids SET $sets WHERE id=?", [...array_values($data), $rid]);
            } else {
                db_insert('operator_radio_ids', $data);
            }
            $msg = 'Radio ID saved.';
            $action = 'edit'; $edit_id = $op_id;
        }
    }

    // ── DELETE RADIO ID ──────────────────────────────────────
    if ($pa === 'delete_radio_id') {
        $rid = (int)$_POST['radio_id_row'];
        $row = db_row("SELECT operator_id FROM operator_radio_ids WHERE id=?", [$rid]);
        if (!$row || (!$is_admin && $row['operator_id'] !== $my_id)) { $err = 'Permission denied.'; }
        else { db_execute("DELETE FROM operator_radio_ids WHERE id=?", [$rid]); $msg = 'Radio ID removed.'; }
        $action = 'edit'; $edit_id = $row['operator_id'] ?? $my_id;
    }

    // ── SAVE SITE CREW ────────────────────────────────────────
    if ($pa === 'save_crew') {
        $scid    = (int)($_POST['crew_id']   ?? 0);
        $op_id   = (int)$_POST['crew_op_id'];
        $site_id = (int)$_POST['crew_site_id'];
        $can     = $is_admin;
        if (!$can) {
            $mgr = db_row("SELECT id FROM site_crew WHERE operator_id=? AND site_id=? AND can_nominate_crew=1 AND approved=1", [$my_id, $site_id]);
            $can = (bool)$mgr;
        }
        if (!$can) { $err = 'Permission denied.'; }
        else {
            $data = [
                'operator_id'            => $op_id,
                'site_id'                => $site_id,
                'role'                   => trim($_POST['crew_role']               ?? 'operator'),
                'can_edit_site'          => isset($_POST['can_edit_site'])         ? 1 : 0,
                'can_edit_systems'       => isset($_POST['can_edit_systems'])      ? 1 : 0,
                'can_post_buildlog'      => isset($_POST['can_post_buildlog'])     ? 1 : 0,
                'can_manage_assets'      => isset($_POST['can_manage_assets'])     ? 1 : 0,
                'can_nominate_crew'      => isset($_POST['can_nominate_crew'])     ? 1 : 0,
                'notify_buildlog'        => isset($_POST['notify_buildlog'])       ? 1 : 0,
                'notify_scheduled_work'  => isset($_POST['notify_scheduled_work']) ? 1 : 0,
                'notify_telemetry_alarm' => isset($_POST['notify_telemetry_alarm'])? 1 : 0,
                'notify_system_status'   => isset($_POST['notify_system_status'])  ? 1 : 0,
                'notify_email'           => isset($_POST['notify_email'])          ? 1 : 0,
                'notify_portal'          => isset($_POST['notify_portal'])         ? 1 : 0,
                'approved'               => $is_admin ? 1 : 0,
                'approved_by'            => $is_admin ? $my_id : null,
                'approved_at'            => $is_admin ? date('Y-m-d H:i:s') : null,
                'nominated_by'           => !$is_admin ? $my_id : null,
                'nomination_note'        => trim($_POST['nomination_note'] ?? '') ?: null,
            ];
            if ($scid) {
                $sets = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
                db_execute("UPDATE site_crew SET $sets WHERE id=?", [...array_values($data), $scid]);
                $msg = 'Site assignment updated.';
            } else {
                $exists = db_row("SELECT id FROM site_crew WHERE operator_id=? AND site_id=?", [$op_id, $site_id]);
                if ($exists) { $err = 'Already assigned to that site.'; }
                else {
                    db_insert('site_crew', $data);
                    $msg = $is_admin ? 'Site assignment added.' : 'Nomination submitted — pending admin approval.';
                }
            }
            $action = 'edit'; $edit_id = $op_id;
        }
    }

    // ── APPROVE CREW ─────────────────────────────────────────
    if ($pa === 'approve_crew' && $is_admin) {
        db_execute("UPDATE site_crew SET approved=1, approved_by=?, approved_at=NOW() WHERE id=?", [$my_id, (int)$_POST['crew_id']]);
        $msg = 'Approved.';
        $action = 'edit'; $edit_id = (int)$_POST['crew_op_id'];
    }

    // ── REMOVE CREW ──────────────────────────────────────────
    if ($pa === 'remove_crew') {
        $scid = (int)$_POST['crew_id'];
        $sc   = db_row("SELECT operator_id, site_id FROM site_crew WHERE id=?", [$scid]);
        $can  = $is_admin;
        if (!$can && $sc) {
            $mgr = db_row("SELECT id FROM site_crew WHERE operator_id=? AND site_id=? AND can_nominate_crew=1 AND approved=1", [$my_id, $sc['site_id']]);
            $can = (bool)$mgr;
        }
        if (!$can) { $err = 'Permission denied.'; }
        else { db_execute("DELETE FROM site_crew WHERE id=?", [$scid]); $msg = 'Site assignment removed.'; }
        $action = 'edit'; $edit_id = $sc['operator_id'] ?? $my_id;
    }
}

// ── LOAD ──────────────────────────────────────────────────────
$edit_op        = null;
$edit_crew      = [];
$edit_radio_ids = [];
if ($action === 'edit') {
    $eid     = $edit_id ?: $my_id;
    $edit_op = db_row("SELECT * FROM operators WHERE id=?", [$eid]);
    if (!$edit_op) { $action = 'list'; }
    else {
        $edit_radio_ids = db_rows("SELECT * FROM operator_radio_ids WHERE operator_id=? ORDER BY mode, id", [$eid]);
        $edit_crew      = db_rows("
            SELECT sc.*, si.name AS site_name
            FROM site_crew sc
            JOIN sites si ON si.id = sc.site_id
            WHERE sc.operator_id = ?
            ORDER BY sc.approved DESC, si.name
        ", [$eid]);
    }
}

$operators   = db_rows("SELECT o.*, COUNT(sc.id) AS site_count, SUM(CASE WHEN sc.approved=0 THEN 1 ELSE 0 END) AS pending_count FROM operators o LEFT JOIN site_crew sc ON sc.operator_id=o.id GROUP BY o.id ORDER BY o.sort_order, o.callsign");
$all_sites   = db_rows("SELECT id, name FROM sites ORDER BY name");
$crew_roles  = ['site_manager','operator','builder','alternate','observer'];
$perms       = ['can_edit_site'=>'Edit site','can_edit_systems'=>'Edit systems','can_post_buildlog'=>'Post build log','can_manage_assets'=>'Manage assets','can_nominate_crew'=>'Nominate crew'];
$notifs      = ['notify_buildlog'=>'Build log','notify_scheduled_work'=>'Scheduled work','notify_telemetry_alarm'=>'Telemetry alarms','notify_system_status'=>'System status','notify_email'=>'via Email','notify_portal'=>'via Portal'];

// Pending nominations
$pending = $is_admin ? db_rows("
    SELECT sc.*, o.callsign, o.display_name, si.name AS site_name, nom.callsign AS nominated_by_call
    FROM site_crew sc
    JOIN operators o ON o.id=sc.operator_id
    JOIN sites si ON si.id=sc.site_id
    LEFT JOIN operators nom ON nom.id=sc.nominated_by
    WHERE sc.approved=0 ORDER BY sc.created_at
") : [];

require_once TTN_INCLUDES . '/admin_head.php';
require_once TTN_INCLUDES . '/admin_nav.php';
?>
<div class="adm-main">
<div class="adm-topbar">
    <div class="adm-topbar-title">
        <?php if ($edit_op): ?>
        <a href="team.php" style="color:var(--t3);font-size:0.72rem;text-decoration:none">Team</a> / <?= htmlspecialchars($edit_op['callsign']) ?>
        <?php else: ?>Team<?php endif; ?>
    </div>
    <a href="?action=edit&id=<?= $my_id ?>" class="btn btn-secondary btn-sm">My Profile</a>
</div>
<div class="adm-body">

<?php if($msg): ?><div class="msg-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="msg-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if (!empty($pending)): ?>
<div class="panel" style="margin-bottom:1.5rem;border-color:var(--amber)">
    <div class="panel-hd" style="color:var(--amber)">⚠ Pending Nominations (<?= count($pending) ?>)</div>
    <table class="adm-tbl">
        <thead><tr><th>Operator</th><th>Site</th><th>Role</th><th>Nominated By</th><th>Note</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pending as $p): ?>
        <tr>
            <td class="mono"><?= htmlspecialchars($p['callsign']) ?></td>
            <td><?= htmlspecialchars($p['site_name']) ?></td>
            <td style="font-family:var(--mono);font-size:0.6rem;color:var(--amber);text-transform:uppercase"><?= $p['role'] ?></td>
            <td class="mono muted"><?= htmlspecialchars($p['nominated_by_call'] ?? '—') ?></td>
            <td class="muted"><?= htmlspecialchars($p['nomination_note'] ?? '') ?></td>
            <td>
                <form method="post" style="display:inline">
                    <?= ttn_csrf_field() ?>
                    <input type="hidden" name="post_action" value="approve_crew">
                    <input type="hidden" name="crew_id" value="<?= $p['id'] ?>">
                    <input type="hidden" name="crew_op_id" value="<?= $p['operator_id'] ?>">
                    <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                </form>
                <form method="post" style="display:inline">
                    <?= ttn_csrf_field() ?>
                    <input type="hidden" name="post_action" value="remove_crew">
                    <input type="hidden" name="crew_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Deny?')">Deny</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($action === 'edit' && $edit_op): ?>

<!-- PROFILE -->
<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-hd">Profile — <?= htmlspecialchars($edit_op['callsign']) ?></div>
    <div class="panel-body">
    <form method="post" enctype="multipart/form-data">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="save_profile">
        <input type="hidden" name="op_id" value="<?= $edit_op['id'] ?>">
        <div class="field-row">
            <div class="field"><label>Callsign (read-only)</label><input type="text" value="<?= htmlspecialchars($edit_op['callsign']) ?>" disabled style="opacity:0.5"></div>
            <div class="field"><label>Display Name</label><input type="text" name="display_name" value="<?= htmlspecialchars($edit_op['display_name']??'') ?>"></div>
        </div>
        <div class="field-row3">
            <div class="field"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($edit_op['email']??'') ?>"></div>
            <div class="field"><label>Phone</label><input type="text" name="phone" value="<?= htmlspecialchars($edit_op['phone']??'') ?>"></div>
            <div class="field"><label>QRZ URL</label><input type="url" name="qrz_url" value="<?= htmlspecialchars($edit_op['qrz_url']??'') ?>"></div>
        </div>
        <div class="field-row">
            <div class="field"><label>City</label><input type="text" name="city" value="<?= htmlspecialchars($edit_op['city']??'') ?>"></div>
            <div class="field"><label>State</label><input type="text" name="state" value="<?= htmlspecialchars($edit_op['state']??'') ?>" maxlength="2"></div>
        </div>
        <div class="field"><label>Bio (public team page)</label><textarea name="bio" rows="4"><?= htmlspecialchars($edit_op['bio']??'') ?></textarea></div>
        <div class="field-row">
            <div class="field"><label>Photo URL</label>
                <input type="text" name="photo_url" value="<?= htmlspecialchars($edit_op['photo_url']??'') ?>">
                <?php if (!empty($edit_op['photo_url'])): ?><div style="margin-top:0.4rem"><img src="<?= htmlspecialchars($edit_op['photo_url']) ?>" style="height:60px;width:60px;object-fit:cover;border:1px solid var(--border2)"></div><?php endif; ?>
            </div>
            <div class="field"><label>Upload Photo</label><input type="file" name="photo_file" accept="image/*" style="color:var(--t2);font-size:0.75rem"></div>
        </div>
        <?php if ($is_admin): ?>
        <div class="field-row3">
            <div class="field"><label>Global Role</label>
                <select name="role"><?php foreach(['viewer','operator','admin'] as $r): ?><option value="<?=$r?>" <?=$edit_op['role']===$r?'selected':''?>><?=ucfirst($r)?></option><?php endforeach; ?></select>
            </div>
            <div class="field"><label>Sort Order</label><input type="number" name="sort_order" value="<?= (int)($edit_op['sort_order']??0) ?>" min="0" max="99"></div>
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin:0.8rem 0">
            <div class="check-row" style="margin:0"><input type="checkbox" name="is_public" id="is_public" <?= ($edit_op['is_public']??1)?'checked':'' ?>><label for="is_public">Visible on public team page</label></div>
            <?php if ($is_admin): ?><div class="check-row" style="margin:0"><input type="checkbox" name="is_active" id="is_active" <?= ($edit_op['is_active']??1)?'checked':'' ?>><label for="is_active">Active (can log in)</label></div><?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary">Save Profile</button>
    </form>
    </div>
</div>

<!-- RADIO IDs -->
<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-hd">Radio IDs (DMR / P25 / NXDN)</div>
    <table class="adm-tbl" style="margin-bottom:0">
        <thead><tr><th>Mode</th><th>ID</th><th>Notes</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($edit_radio_ids as $rid): ?>
        <tr>
            <form method="post">
                <?= ttn_csrf_field() ?>
                <input type="hidden" name="post_action" value="save_radio_id">
                <input type="hidden" name="rid_op_id" value="<?= $edit_op['id'] ?>">
                <input type="hidden" name="radio_id_row" value="<?= $rid['id'] ?>">
                <td><select name="rid_mode" style="font-size:0.7rem"><?php foreach(['DMR','P25','NXDN','other'] as $m): ?><option value="<?=$m?>" <?=$rid['mode']===$m?'selected':''?>><?=$m?></option><?php endforeach; ?></select></td>
                <td><input type="text" name="rid_value" value="<?= htmlspecialchars($rid['radio_id']) ?>" style="width:100px;font-family:var(--mono)"></td>
                <td><input type="text" name="rid_notes" value="<?= htmlspecialchars($rid['notes']??'') ?>" style="width:180px;font-size:0.75rem" placeholder="Portable, mobile..."></td>
                <td style="display:flex;gap:0.3rem">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="if(confirm('Remove?')){this.closest('form').querySelector('[name=post_action]').value='delete_radio_id';this.closest('form').submit()}">Del</button>
                </td>
            </form>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($edit_radio_ids)): ?><tr><td colspan="4" class="muted">No radio IDs yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <div style="padding:0.7rem 1rem;border-top:1px solid var(--border2)">
    <form method="post" style="display:flex;gap:0.5rem;align-items:flex-end;flex-wrap:wrap">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="save_radio_id">
        <input type="hidden" name="rid_op_id" value="<?= $edit_op['id'] ?>">
        <div class="field" style="margin:0"><label>Mode</label><select name="rid_mode"><?php foreach(['DMR','P25','NXDN','other'] as $m): ?><option><?=$m?></option><?php endforeach; ?></select></div>
        <div class="field" style="margin:0"><label>ID Number</label><input type="text" name="rid_value" placeholder="3120123" required style="width:100px"></div>
        <div class="field" style="margin:0"><label>Notes</label><input type="text" name="rid_notes" placeholder="Portable HT" style="width:150px"></div>
        <button type="submit" class="btn btn-primary btn-sm" style="align-self:flex-end;margin-bottom:0.1rem">Add ID</button>
    </form>
    </div>
</div>

<!-- SITE ASSIGNMENTS -->
<div class="panel">
    <div class="panel-hd">
        Site Assignments (<?= count($edit_crew) ?>)
        <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('newCrewForm').style.display=document.getElementById('newCrewForm').style.display==='none'?'block':'none'">+ Add Site</button>
    </div>

    <?php foreach ($edit_crew as $sc): ?>
    <div style="border-bottom:1px solid var(--border2)">
        <div style="padding:0.7rem 1rem;display:flex;align-items:center;justify-content:space-between;background:<?= $sc['approved']?'var(--panel2)':'rgba(251,191,36,0.04)' ?>">
            <div>
                <span style="font-family:var(--mono);font-size:0.75rem;color:var(--t1)"><?= htmlspecialchars($sc['site_name']) ?></span>
                <span style="font-family:var(--mono);font-size:0.6rem;color:var(--amber);margin-left:0.6rem;text-transform:uppercase"><?= $sc['role'] ?></span>
                <?php if (!$sc['approved']): ?><span style="font-family:var(--mono);font-size:0.55rem;color:var(--amber);margin-left:0.4rem;border:1px solid var(--amber);padding:0.05rem 0.3rem">PENDING</span><?php endif; ?>
            </div>
            <div style="display:flex;gap:0.4rem">
                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleCrew(<?= $sc['id'] ?>)">Edit ▾</button>
                <form method="post" style="display:inline" onsubmit="return confirm('Remove?')">
                    <?= ttn_csrf_field() ?>
                    <input type="hidden" name="post_action" value="remove_crew">
                    <input type="hidden" name="crew_id" value="<?= $sc['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                </form>
            </div>
        </div>
        <div id="crew-<?= $sc['id'] ?>" style="display:none;padding:1rem;border-top:1px solid var(--border)">
        <form method="post">
            <?= ttn_csrf_field() ?>
            <input type="hidden" name="post_action" value="save_crew">
            <input type="hidden" name="crew_id" value="<?= $sc['id'] ?>">
            <input type="hidden" name="crew_op_id" value="<?= $edit_op['id'] ?>">
            <input type="hidden" name="crew_site_id" value="<?= $sc['site_id'] ?>">
            <div class="field" style="margin-bottom:1rem"><label>Role at this site</label>
                <select name="crew_role"><?php foreach($crew_roles as $r): ?><option value="<?=$r?>" <?=$sc['role']===$r?'selected':''?>><?=ucfirst(str_replace('_',' ',$r))?></option><?php endforeach; ?></select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">
                <div>
                    <div style="font-family:var(--mono);font-size:0.57rem;color:var(--t3);letter-spacing:0.1em;text-transform:uppercase;margin-bottom:0.5rem">Permissions</div>
                    <?php foreach ($perms as $pk => $pl): ?>
                    <div class="check-row" style="margin-bottom:0.3rem"><input type="checkbox" name="<?=$pk?>" id="<?=$pk?>_<?=$sc['id']?>" <?=$sc[$pk]?'checked':''?>><label for="<?=$pk?>_<?=$sc['id']?>"><?=$pl?></label></div>
                    <?php endforeach; ?>
                </div>
                <div>
                    <div style="font-family:var(--mono);font-size:0.57rem;color:var(--t3);letter-spacing:0.1em;text-transform:uppercase;margin-bottom:0.5rem">Notifications</div>
                    <?php foreach ($notifs as $nk => $nl): ?>
                    <div class="check-row" style="margin-bottom:0.3rem"><input type="checkbox" name="<?=$nk?>" id="<?=$nk?>_<?=$sc['id']?>" <?=$sc[$nk]?'checked':''?>><label for="<?=$nk?>_<?=$sc['id']?>"><?=$nl?></label></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:0.8rem">Save Assignment</button>
        </form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($edit_crew)): ?><div style="padding:1rem;font-family:var(--mono);font-size:0.65rem;color:var(--t3)">Not assigned to any sites yet.</div><?php endif; ?>

    <!-- New crew assignment -->
    <div id="newCrewForm" style="display:none;padding:1rem;border-top:1px solid var(--border2);background:var(--panel2)">
    <form method="post">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="save_crew">
        <input type="hidden" name="crew_op_id" value="<?= $edit_op['id'] ?>">
        <div class="field-row" style="margin-bottom:0.8rem">
            <div class="field"><label>Site *</label>
                <select name="crew_site_id"><option value="">— select —</option><?php foreach($all_sites as $si): ?><option value="<?=$si['id']?>"><?=htmlspecialchars($si['name'])?></option><?php endforeach; ?></select>
            </div>
            <div class="field"><label>Role</label>
                <select name="crew_role"><?php foreach($crew_roles as $r): ?><option value="<?=$r?>"><?=ucfirst(str_replace('_',' ',$r))?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:0.8rem">
            <div>
                <div style="font-family:var(--mono);font-size:0.57rem;color:var(--t3);letter-spacing:0.1em;text-transform:uppercase;margin-bottom:0.5rem">Permissions</div>
                <?php foreach ($perms as $pk => $pl): ?>
                <div class="check-row" style="margin-bottom:0.3rem"><input type="checkbox" name="<?=$pk?>" id="new_<?=$pk?>" <?=in_array($pk,['can_post_buildlog'])?'checked':''?>><label for="new_<?=$pk?>"><?=$pl?></label></div>
                <?php endforeach; ?>
            </div>
            <div>
                <div style="font-family:var(--mono);font-size:0.57rem;color:var(--t3);letter-spacing:0.1em;text-transform:uppercase;margin-bottom:0.5rem">Notifications</div>
                <?php foreach ($notifs as $nk => $nl): ?>
                <div class="check-row" style="margin-bottom:0.3rem"><input type="checkbox" name="<?=$nk?>" id="new_<?=$nk?>" <?=in_array($nk,['notify_buildlog','notify_email','notify_portal'])?'checked':''?>><label for="new_<?=$nk?>"><?=$nl?></label></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if (!$is_admin): ?>
        <div class="field" style="margin-bottom:0.8rem"><label>Reason for nomination</label><input type="text" name="nomination_note" placeholder="Site builder for Phase 1"></div>
        <?php endif; ?>
        <div style="display:flex;gap:0.5rem">
            <button type="submit" class="btn btn-primary btn-sm"><?= $is_admin ? 'Add Assignment' : 'Submit Nomination' ?></button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('newCrewForm').style.display='none'">Cancel</button>
        </div>
    </form>
    </div>
</div>

<?php else: ?>
<!-- OPERATOR LIST -->
<div class="panel">
    <div class="panel-hd">All Operators (<?= count($operators) ?>)</div>
    <table class="adm-tbl">
        <thead><tr><th>Call</th><th>Name</th><th>Location</th><th>Role</th><th>Sites</th><th>Public</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($operators as $op): ?>
        <tr>
            <td class="mono"><?= htmlspecialchars($op['callsign']) ?></td>
            <td><?= htmlspecialchars($op['display_name']??'—') ?></td>
            <td class="muted" style="font-size:0.72rem"><?= htmlspecialchars(trim(($op['city']??'').($op['state']?', '.$op['state']:'')))?:'—' ?></td>
            <td><span style="font-family:var(--mono);font-size:0.58rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--amber)"><?= $op['role'] ?></span></td>
            <td class="mono muted"><?= $op['site_count'] ?><?php if($op['pending_count']>0): ?> <span style="color:var(--amber);font-size:0.58rem">(<?=$op['pending_count']?> pend)</span><?php endif; ?></td>
            <td><?= $op['is_public']?'<span style="color:var(--green)">✓</span>':'<span style="color:var(--t3)">—</span>' ?></td>
            <td><?= $op['is_active']?'<span style="color:var(--green)">✓</span>':'<span style="color:var(--red)">✗</span>' ?></td>
            <td>
                <?php if ($is_admin || $op['id']==$my_id): ?>
                <a href="?action=edit&id=<?= $op['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                <?php endif; ?>
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
<script>
function toggleCrew(id) {
    const el = document.getElementById('crew-'+id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>
