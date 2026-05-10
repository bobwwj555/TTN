<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_require_role('viewer');

$adm_title = 'Sites';
$adm_page  = 'sites';
$is_admin  = ttn_has_role('admin');
$my_op_id  = $_SESSION['operator_id'] ?? 0;
$msg = $err = '';
$action  = $_GET['action'] ?? 'list';
$edit_id = (int)($_GET['id'] ?? 0);

// Determine which sites this operator can edit
// Admins can edit all. Others only sites where they have can_edit_site=1 in site_crew
function can_edit_site(int $site_id, bool $is_admin, int $op_id): bool {
    if ($is_admin) return true;
    $row = db_row("SELECT can_edit_site FROM site_crew WHERE operator_id=? AND site_id=? AND approved=1", [$op_id, $site_id]);
    return $row && $row['can_edit_site'];
}

function can_edit_systems(int $site_id, bool $is_admin, int $op_id): bool {
    if ($is_admin) return true;
    $row = db_row("SELECT can_edit_systems FROM site_crew WHERE operator_id=? AND site_id=? AND approved=1", [$op_id, $site_id]);
    return $row && $row['can_edit_systems'];
}

// ── POST HANDLERS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ttn_csrf_verify($_POST['csrf_token'] ?? '');
    $pa = $_POST['post_action'] ?? '';

    // ── CREATE SITE ──────────────────────────────────────────
    if ($pa === 'create_site' && $is_admin) {
        $name = trim($_POST['name'] ?? '');
        if (!$name) {
            $err = 'Site name is required.'; $action = 'new_site';
        } else {
            $new_id = db_insert('sites', [
                'name'          => $name,
                'city'          => trim($_POST['city']          ?? ''),
                'state'         => trim($_POST['state']         ?? 'TN'),
                'county'        => trim($_POST['county']        ?? '') ?: null,
                'phase'         => (int)($_POST['phase']        ?? 1),
                'status'        => trim($_POST['status']        ?? 'planned'),
                'power_primary' => trim($_POST['power_primary'] ?? '') ?: null,
                'is_public'     => isset($_POST['is_public']) ? 1 : 0,
            ]);
            $msg = "Site '$name' created.";
            $action = 'edit'; $edit_id = $new_id;
        }
    }

    // ── SAVE SITE ────────────────────────────────────────────
    if ($pa === 'save_site') {
        $sid = (int)$_POST['site_id'];
        if (!can_edit_site($sid, $is_admin, $my_op_id)) {
            $err = 'Permission denied.';
        } else {
            // Handle site photo upload
            $photo_url = trim($_POST['photo_url'] ?? '') ?: null;
            if (!empty($_FILES['site_photo']['name']) && $_FILES['site_photo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '/var/www/html/uploads/';
                $allowed    = ['image/jpeg','image/png','image/webp'];
                if (in_array($_FILES['site_photo']['type'], $allowed) && $_FILES['site_photo']['size'] < 5*1024*1024) {
                    $ext   = strtolower(pathinfo($_FILES['site_photo']['name'], PATHINFO_EXTENSION));
                    $fname = 'site_' . preg_replace('/[^a-z0-9]/','', strtolower($_POST['site_name_slug'] ?? 'site')) . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['site_photo']['tmp_name'], $upload_dir . $fname)) {
                        $photo_url = '/uploads/' . $fname;
                    }
                }
            }
            db_execute("UPDATE sites SET
                name=?,city=?,state=?,county=?,lat=?,lng=?,
                elevation_ft=?,tower_height_ft=?,tower_type=?,
                power_primary=?,power_backup=?,battery_ah=?,solar_watts=?,
                camera_ftp_path=?,weather_station=?,
                photo_url=?,coverage_url=?,notes=?,
                status=?,phase=?,is_public=?
                WHERE id=?", [
                trim($_POST['name']            ?? ''),
                trim($_POST['city']            ?? ''),
                trim($_POST['state']           ?? 'TN'),
                trim($_POST['county']          ?? '') ?: null,
                (float)($_POST['lat']          ?? 0) ?: null,
                (float)($_POST['lng']          ?? 0) ?: null,
                (int)($_POST['elevation_ft']   ?? 0) ?: null,
                (int)($_POST['tower_height_ft']?? 0) ?: null,
                trim($_POST['tower_type']      ?? '') ?: null,
                trim($_POST['power_primary']   ?? '') ?: null,
                trim($_POST['power_backup']    ?? '') ?: null,
                (int)($_POST['battery_ah']     ?? 0) ?: null,
                (int)($_POST['solar_watts']    ?? 0) ?: null,
                trim($_POST['camera_ftp_path'] ?? '') ?: null,
                trim($_POST['weather_station'] ?? '') ?: null,
                $photo_url,
                trim($_POST['coverage_url']    ?? '') ?: null,
                trim($_POST['notes']           ?? ''),
                trim($_POST['status']          ?? 'planned'),
                (int)($_POST['phase']          ?? 1),
                isset($_POST['is_public']) ? 1 : 0,
                $sid,
            ]);
            $msg = 'Site updated.'; $action = 'edit'; $edit_id = $sid;
            require_once TTN_INCLUDES . '/node-refresh.php'; ttn_node_refresh($sid);
        }
    }

    // ── CREATE SYSTEM ────────────────────────────────────────
    if ($pa === 'create_system') {
        $sid = (int)$_POST['site_id'];
        if (!can_edit_systems($sid, $is_admin, $my_op_id)) {
            $err = 'Permission denied.';
        } else {
            $sys_id = db_insert('systems', [
                'site_id'     => $sid,
                'callsign'    => strtoupper(trim($_POST['callsign']    ?? '')),
                'system_type' => trim($_POST['system_type'] ?? 'repeater'),
                'status'      => trim($_POST['sys_status']  ?? 'planned'),
                'label'       => trim($_POST['label']       ?? '') ?: null,
                'freq_tx'     => (float)($_POST['freq_tx']  ?? 0) ?: null,
                'freq_rx'     => (float)($_POST['freq_rx']  ?? 0) ?: null,
                'band'        => trim($_POST['band']        ?? '') ?: null,
                'access_type' => trim($_POST['access_type'] ?? 'CTCSS'),
                'access_code' => trim($_POST['access_code'] ?? '') ?: null,
                'sort_order'  => (int)(db_value("SELECT COALESCE(MAX(sort_order)+1,0) FROM systems WHERE site_id=?", [$sid]) ?? 0),
                'is_public'   => isset($_POST['sys_is_public']) ? 1 : 0,
            ]);
            // Seed FM mode by default
            db_insert('sys_modes', ['system_id'=>$sys_id,'mode'=>'FM','bandwidth_khz'=>25.0,'fcc_emission'=>'16K0F3E','is_primary'=>1]);
            $msg = 'System created.'; $action = 'edit'; $edit_id = $sid;
        }
    }

    // ── SAVE SYSTEM ──────────────────────────────────────────
    if ($pa === 'save_system') {
        $sys_id = (int)$_POST['system_id'];
        $sys    = db_row("SELECT site_id FROM systems WHERE id=?", [$sys_id]);
        if (!$sys || !can_edit_systems($sys['site_id'], $is_admin, $my_op_id)) {
            $err = 'Permission denied.';
        } else {
            // Handle system photo upload
            $photo_url = trim($_POST['sys_photo_url'] ?? '') ?: null;
            if (!empty($_FILES['sys_photo']['name']) && $_FILES['sys_photo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '/var/www/html/uploads/';
                $allowed    = ['image/jpeg','image/png','image/webp'];
                if (in_array($_FILES['sys_photo']['type'], $allowed) && $_FILES['sys_photo']['size'] < 5*1024*1024) {
                    $ext   = strtolower(pathinfo($_FILES['sys_photo']['name'], PATHINFO_EXTENSION));
                    $fname = 'sys_' . preg_replace('/[^a-z0-9]/','', strtolower($_POST['sys_callsign_slug'] ?? 'sys')) . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['sys_photo']['tmp_name'], $upload_dir . $fname)) {
                        $photo_url = '/uploads/' . $fname;
                    }
                }
            }
            db_execute("UPDATE systems SET
                callsign=?,system_type=?,status=?,label=?,
                freq_tx=?,freq_rx=?,band=?,access_type=?,access_code=?,
                antenna_make=?,antenna_model=?,antenna_gain_db=?,antenna_pattern=?,
                feedline_make=?,feedline_length_ft=?,feedline_loss_db=?,
                tx_power_watts=?,duplexer_loss_db=?,erp_watts=?,
                intermod_notes=?,photo_url=?,notes=?,
                sort_order=?,is_public=?
                WHERE id=?", [
                strtoupper(trim($_POST['callsign']       ?? '')),
                trim($_POST['system_type']               ?? 'repeater'),
                trim($_POST['sys_status']                ?? 'planned'),
                trim($_POST['label']                     ?? '') ?: null,
                (float)($_POST['freq_tx']                ?? 0) ?: null,
                (float)($_POST['freq_rx']                ?? 0) ?: null,
                trim($_POST['band']                      ?? '') ?: null,
                trim($_POST['access_type']               ?? 'CTCSS'),
                trim($_POST['access_code']               ?? '') ?: null,
                trim($_POST['antenna_make']              ?? '') ?: null,
                trim($_POST['antenna_model']             ?? '') ?: null,
                (float)($_POST['antenna_gain_db']        ?? 0) ?: null,
                trim($_POST['antenna_pattern']           ?? 'omni'),
                trim($_POST['feedline_make']             ?? '') ?: null,
                (int)($_POST['feedline_length_ft']       ?? 0) ?: null,
                (float)($_POST['feedline_loss_db']       ?? 0) ?: null,
                (int)($_POST['tx_power_watts']           ?? 0) ?: null,
                (float)($_POST['duplexer_loss_db']       ?? 0) ?: null,
                (int)($_POST['erp_watts']                ?? 0) ?: null,
                trim($_POST['intermod_notes']            ?? '') ?: null,
                $photo_url,
                trim($_POST['sys_notes']                 ?? ''),
                (int)($_POST['sort_order']               ?? 0),
                isset($_POST['sys_is_public']) ? 1 : 0,
                $sys_id,
            ]);
            // Sync modes — delete existing, re-insert checked ones
            db_execute("DELETE FROM sys_modes WHERE system_id=?", [$sys_id]);
            $selected_modes = $_POST['modes'] ?? [];
            $valid_modes = ['FM','DMR','M17','Fusion','DStar','P25','NXDN','Hub','other'];
            foreach ($selected_modes as $i => $mval) {
                if (!in_array($mval, $valid_modes)) continue;
                $bw  = $mval === 'FM' ? 25.0 : ($mval === 'DMR' ? 12.5 : null);
                $fcc = $mval === 'FM' ? '16K0F3E' : ($mval === 'DMR' ? '7K60FXD' : ($mval === 'Hub' ? null : null));
                db_insert('sys_modes', [
                    'system_id'     => $sys_id,
                    'mode'          => $mval,
                    'bandwidth_khz' => $bw,
                    'fcc_emission'  => $fcc,
                    'is_primary'    => ($i === 0) ? 1 : 0,
                ]);
            }
            $msg = 'System updated.'; $action = 'edit'; $edit_id = $sys['site_id'];
        }
    }

    // ── MOVE SYSTEM ──────────────────────────────────────────────
    if ($pa === 'move_system' && $is_admin) {
        $sys_id      = (int)$_POST['system_id'];
        $new_site_id = (int)$_POST['new_site_id'];
        if (!$sys_id || !$new_site_id) {
            $err = 'Select a destination site.';
        } else {
            $new_site = db_row("SELECT id, name FROM sites WHERE id=?", [$new_site_id]);
            if (!$new_site) {
                $err = 'Destination site not found.';
            } else {
                db_execute("UPDATE systems SET site_id=? WHERE id=?", [$new_site_id, $sys_id]);
                $msg = 'System moved to ' . $new_site['name'] . '.';
                $action = 'edit'; $edit_id = $new_site_id;
            }
        }
    }

    // ── DELETE SYSTEM ────────────────────────────────────────
    if ($pa === 'delete_system') {
        $sys_id = (int)$_POST['system_id'];
        $sys    = db_row("SELECT site_id FROM systems WHERE id=?", [$sys_id]);
        if (!$sys || !can_edit_systems($sys['site_id'], $is_admin, $my_op_id)) {
            $err = 'Permission denied.';
        } else {
            db_execute("DELETE FROM sys_modes WHERE system_id=?", [$sys_id]);
            db_execute("DELETE FROM sys_asl WHERE system_id=?", [$sys_id]);
            try { db_execute("DELETE FROM sys_echolink WHERE system_id=?", [$sys_id]); } catch (Exception $e) {}
            try { db_execute("DELETE FROM sys_dmr WHERE system_id=?", [$sys_id]); } catch (Exception $e) {}
            db_execute("DELETE FROM systems WHERE id=?", [$sys_id]);
            $msg = 'System deleted.'; $action = 'edit'; $edit_id = $sys['site_id'];
        }
    }

    // ── SAVE MODE ────────────────────────────────────────────
    if ($pa === 'save_mode') {
        $sys_id = (int)$_POST['mode_system_id'];
        $sys    = db_row("SELECT site_id FROM systems WHERE id=?", [$sys_id]);
        if (!$sys || !can_edit_systems($sys['site_id'], $is_admin, $my_op_id)) {
            $err = 'Permission denied.';
        } else {
            $mid = (int)($_POST['mode_id'] ?? 0);
            $data = [
                'system_id'     => $sys_id,
                'mode'          => trim($_POST['mode']          ?? 'FM'),
                'bandwidth_khz' => (float)($_POST['bandwidth_khz'] ?? 25) ?: null,
                'fcc_emission'  => trim($_POST['fcc_emission']  ?? '') ?: null,
                'is_primary'    => isset($_POST['is_primary']) ? 1 : 0,
                'notes'         => trim($_POST['mode_notes']    ?? '') ?: null,
            ];
            if ($mid) {
                $sets = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
                db_execute("UPDATE sys_modes SET $sets WHERE id=?", [...array_values($data), $mid]);
            } else {
                db_insert('sys_modes', $data);
            }
            $msg = 'Mode saved.'; $action = 'edit'; $edit_id = $sys['site_id'];
        }
    }

    // ── DELETE MODE ──────────────────────────────────────────
    if ($pa === 'delete_mode') {
        $mid    = (int)$_POST['mode_id'];
        $mode   = db_row("SELECT sm.id, s.site_id FROM sys_modes sm JOIN systems s ON s.id=sm.system_id WHERE sm.id=?", [$mid]);
        if (!$mode || !can_edit_systems($mode['site_id'], $is_admin, $my_op_id)) {
            $err = 'Permission denied.';
        } else {
            db_execute("DELETE FROM sys_modes WHERE id=?", [$mid]);
            $msg = 'Mode removed.'; $action = 'edit'; $edit_id = $mode['site_id'];
        }
    }

    // ── SAVE ASL ─────────────────────────────────────────────
    if ($pa === 'save_asl') {
        $sys_id = (int)$_POST['asl_system_id'];
        $sys    = db_row("SELECT site_id FROM systems WHERE id=?", [$sys_id]);
        if (!$sys || !can_edit_systems($sys['site_id'], $is_admin, $my_op_id)) {
            $err = 'Permission denied.';
        } else {
            $aid    = (int)($_POST['asl_id'] ?? 0);
            $asl_no = trim($_POST['asl_number'] ?? '');
            $is_hub = isset($_POST['is_hub']) ? 1 : 0;
            $label  = trim($_POST['asl_label'] ?? '') ?: null;
            $srv_id = (int)($_POST['server_id'] ?? 0) ?: null;
            if ($aid) {
                db_execute("UPDATE sys_asl SET asl_number=?,is_hub=?,label=?,server_id=? WHERE id=?", [$asl_no,$is_hub,$label,$srv_id,$aid]);
            } else {
                db_insert('sys_asl', ['system_id'=>$sys_id,'asl_number'=>$asl_no,'is_hub'=>$is_hub,'label'=>$label,'server_id'=>$srv_id]);
            }
            $msg = 'ASL node saved.'; $action = 'edit'; $edit_id = $sys['site_id'];
        }
    }

    // ── DELETE ASL ───────────────────────────────────────────
    if ($pa === 'delete_asl') {
        $aid  = (int)$_POST['asl_id'];
        $arow = db_row("SELECT sa.id, s.site_id FROM sys_asl sa JOIN systems s ON s.id=sa.system_id WHERE sa.id=?", [$aid]);
        if (!$arow || !can_edit_systems($arow['site_id'], $is_admin, $my_op_id)) {
            $err = 'Permission denied.';
        } else {
            db_execute("DELETE FROM sys_asl WHERE id=?", [$aid]);
            $msg = 'ASL node removed.'; $action = 'edit'; $edit_id = $arow['site_id'];
        }
    }

    // ── SAVE INTERFACE ───────────────────────────────────────
    if ($pa === 'save_interface') {
        $sys_id = (int)$_POST['iface_system_id'];
        $sys    = db_row("SELECT site_id FROM systems WHERE id=?", [$sys_id]);
        if (!$sys || !can_edit_systems($sys['site_id'], $is_admin, $my_op_id)) {
            $err = 'Permission denied.';
        } else {
            $iid  = (int)($_POST['iface_id'] ?? 0);
            $data = [
                'system_id'      => $sys_id,
                'label'          => trim($_POST['iface_label']   ?? ''),
                'url'            => trim($_POST['iface_url']     ?? ''),
                'interface_type' => $_POST['iface_type']         ?? 'custom',
                'is_public'      => isset($_POST['iface_public']) ? 1 : 0,
                'sort_order'     => (int)($_POST['iface_sort']   ?? 0),
            ];
            if (!$data['url']) { $err = 'URL required.'; }
            else {
                if ($iid) {
                    $sets = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
                    db_execute("UPDATE sys_interfaces SET $sets WHERE id=?", [...array_values($data), $iid]);
                } else {
                    db_insert('sys_interfaces', $data);
                }
                $msg = 'Interface saved.'; $action = 'edit'; $edit_id = $sys['site_id'];
                require_once TTN_INCLUDES . '/node-refresh.php'; ttn_node_refresh($sys['site_id']);
            }
        }
    }

    // ── DELETE INTERFACE ─────────────────────────────────────
    if ($pa === 'delete_interface') {
        $iid  = (int)$_POST['iface_id'];
        $irow = db_row("SELECT si.id, s.site_id FROM sys_interfaces si JOIN systems s ON s.id=si.system_id WHERE si.id=?", [$iid]);
        if (!$irow || !can_edit_systems($irow['site_id'], $is_admin, $my_op_id)) {
            $err = 'Permission denied.';
        } else {
            db_execute("DELETE FROM sys_interfaces WHERE id=?", [$iid]);
            $msg = 'Interface removed.'; $action = 'edit'; $edit_id = $irow['site_id'];
        }
    }

    // ── SAVE SERA ────────────────────────────────────────────
    if ($pa === 'save_sera') {
        $sys_id = (int)$_POST['sera_system_id'];
        $sys    = db_row("SELECT site_id FROM systems WHERE id=?", [$sys_id]);
        if (!$sys || !can_edit_systems($sys['site_id'], $is_admin, $my_op_id)) {
            $err = 'Permission denied.';
        } else {
            $srid = (int)($_POST['sera_id_pk'] ?? 0);
            $data = [
                'system_id'       => $sys_id,
                'sera_id'         => trim($_POST['sera_id']         ?? ''),
                'sera_guid'       => trim($_POST['sera_guid']       ?? '') ?: null,
                'status'          => $_POST['sera_status']          ?? 'pending',
                'coordinated_at'  => $_POST['coordinated_at']       ?: null,
                'expires_at'      => $_POST['expires_at']           ?: null,
                'recertified_at'  => $_POST['recertified_at']       ?: null,
                'publish_journal' => isset($_POST['publish_journal']) ? 1 : 0,
                'trustee_call'    => trim($_POST['trustee_call']    ?? '') ?: null,
                'alt_contact'     => trim($_POST['alt_contact']     ?? '') ?: null,
                'alt_call'        => trim($_POST['alt_call']        ?? '') ?: null,
                'alt_phone'       => trim($_POST['alt_phone']       ?? '') ?: null,
                'alt_email'       => trim($_POST['alt_email']       ?? '') ?: null,
                'features'        => trim($_POST['features']        ?? '') ?: null,
                'notes'           => trim($_POST['sera_notes']      ?? '') ?: null,
            ];
            if ($srid) {
                $sets = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
                db_execute("UPDATE sera_records SET $sets WHERE id=?", [...array_values($data), $srid]);
            } else {
                db_insert('sera_records', $data);
            }
            $msg = 'SERA record saved.'; $action = 'edit'; $edit_id = $sys['site_id'];
        }
    }

    // ── DELETE SERA ──────────────────────────────────────────
    if ($pa === 'delete_sera') {
        $srid = (int)$_POST['sera_id_pk'];
        $srow = db_row("SELECT sr.id, s.site_id FROM sera_records sr JOIN systems s ON s.id=sr.system_id WHERE sr.id=?", [$srid]);
        if (!$srow || !can_edit_systems($srow['site_id'], $is_admin, $my_op_id)) {
            $err = 'Permission denied.';
        } else {
            db_execute("DELETE FROM sera_records WHERE id=?", [$srid]);
            $msg = 'SERA record deleted.'; $action = 'edit'; $edit_id = $srow['site_id'];
        }
    }
}

// ── LOAD DATA ─────────────────────────────────────────────────
$edit_site    = null;
$edit_systems = [];
if (($action === 'edit' || $action === 'system') && $edit_id) {
    $edit_site = db_row("SELECT * FROM sites WHERE id=?", [$edit_id]);
    if (!$edit_site) { $action = 'list'; $edit_id = 0; }
    else {
        $edit_systems = db_rows("SELECT * FROM systems WHERE site_id=? ORDER BY sort_order, id", [$edit_id]);
        foreach ($edit_systems as &$sys) {
            $sys['modes']      = db_rows("SELECT * FROM sys_modes WHERE system_id=? ORDER BY is_primary DESC, mode", [$sys['id']]);
            $sys['asls']       = db_rows("SELECT sa.*, srv.hostname FROM sys_asl sa LEFT JOIN asl_servers srv ON srv.id=sa.server_id WHERE sa.system_id=? ORDER BY sa.is_hub DESC, sa.asl_number", [$sys['id']]);
            $sys['interfaces'] = db_rows("SELECT * FROM sys_interfaces WHERE system_id=? ORDER BY sort_order, label", [$sys['id']]);
            try { $sys['sera'] = db_rows("SELECT * FROM sera_records WHERE system_id=? ORDER BY coordinated_at DESC", [$sys['id']]); } catch (Exception $e) { $sys['sera'] = []; }
        }
        unset($sys);
    }
}

$edit_system = null;
if ($action === 'system' && isset($_GET['sys'])) {
    $sys_id = (int)$_GET['sys'];
    foreach ($edit_systems as $s) {
        if ($s['id'] === $sys_id) { $edit_system = $s; break; }
    }
}

$sites      = db_rows("SELECT s.*, COUNT(sys.id) AS system_count FROM sites s LEFT JOIN systems sys ON sys.site_id=s.id GROUP BY s.id ORDER BY FIELD(s.status,'live','building','planned','offline'), s.phase, s.name");
$asl_servers = db_rows("SELECT * FROM asl_servers ORDER BY site_id, hostname");

require_once TTN_INCLUDES . '/admin_head.php';
require_once TTN_INCLUDES . '/admin_nav.php';
?>
<div class="adm-main">
<div class="adm-topbar">
    <div class="adm-topbar-title">
        <?php if ($edit_site): ?>
        <a href="<?= s('site_url') ?>/admin/sites.php" style="color:var(--t3);text-decoration:none;font-size:0.75rem">Sites</a>
        <span style="color:var(--t3)"> / </span><?= htmlspecialchars($edit_site['name']) ?>
        <?php else: ?>
        Sites
        <?php endif; ?>
    </div>
    <?php if ($is_admin && $action === 'list'): ?>
    <a href="?action=new_site" class="btn btn-primary btn-sm">+ Add Site</a>
    <?php endif; ?>
    <?php if ($edit_site && can_edit_systems($edit_id, $is_admin, $my_op_id)): ?>
    <a href="?action=system&id=<?= $edit_id ?>&sys=new" class="btn btn-primary btn-sm">+ Add System</a>
    <?php endif; ?>
</div>
<div class="adm-body">

<?php if($msg): ?><div class="msg-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="msg-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if ($action === 'new_site' && $is_admin): ?>
<!-- ── NEW SITE ── -->
<div class="panel">
    <div class="panel-hd">New Site <a href="sites.php" class="btn btn-secondary btn-sm">← Back</a></div>
    <div class="panel-body">
    <form method="post">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="create_site">
        <div class="field-row3">
            <div class="field"><label>Site Name *</label><input type="text" name="name" placeholder="EastTN_HUB" required></div>
            <div class="field"><label>City</label><input type="text" name="city" placeholder="New Market"></div>
            <div class="field"><label>State</label><input type="text" name="state" value="TN" maxlength="2"></div>
        </div>
        <div class="field-row3">
            <div class="field"><label>Phase</label>
                <select name="phase"><?php foreach([1,2,3] as $p): ?><option value="<?=$p?>">Phase <?=$p?></option><?php endforeach; ?></select>
            </div>
            <div class="field"><label>Status</label>
                <select name="status"><?php foreach(['planned','building','live','offline'] as $st): ?><option value="<?=$st?>"><?=ucfirst($st)?></option><?php endforeach; ?></select>
            </div>
            <div class="field"><label>Power Primary</label><input type="text" name="power_primary" placeholder="Solar, Grid, Generator"></div>
        </div>
        <div class="check-row">
            <input type="checkbox" name="is_public" id="is_public" checked>
            <label for="is_public">Public (visible on network page)</label>
        </div>
        <div style="display:flex;gap:0.7rem;margin-top:1rem">
            <button type="submit" class="btn btn-primary">Create Site</button>
            <a href="sites.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
    </div>
</div>

<?php elseif (($action === 'edit' || $action === 'system') && $edit_site): ?>
<!-- ── EDIT SITE ── -->
<?php if (can_edit_site($edit_id, $is_admin, $my_op_id)): ?>
<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-hd">Site Details</div>
    <div class="panel-body">
    <form method="post" enctype="multipart/form-data">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="save_site">
        <input type="hidden" name="site_id" value="<?= $edit_site['id'] ?>">
        <input type="hidden" name="site_name_slug" value="<?= preg_replace('/[^a-z0-9]/','',strtolower($edit_site['name'])) ?>">
        <div class="field-row3">
            <div class="field"><label>Site Name</label><input type="text" name="name" value="<?= htmlspecialchars($edit_site['name']) ?>" required></div>
            <div class="field"><label>City</label><input type="text" name="city" value="<?= htmlspecialchars($edit_site['city']??'') ?>"></div>
            <div class="field"><label>State</label><input type="text" name="state" value="<?= htmlspecialchars($edit_site['state']??'TN') ?>" maxlength="2"></div>
        </div>
        <div class="field-row3">
            <div class="field"><label>County</label><input type="text" name="county" value="<?= htmlspecialchars($edit_site['county']??'') ?>"></div>
            <div class="field"><label>Latitude</label><input type="text" name="lat" value="<?= htmlspecialchars($edit_site['lat']??'') ?>"></div>
            <div class="field"><label>Longitude</label><input type="text" name="lng" value="<?= htmlspecialchars($edit_site['lng']??'') ?>"></div>
        </div>
        <div class="field-row3">
            <div class="field"><label>Elevation ft (GAMSL)</label><input type="number" name="elevation_ft" value="<?= $edit_site['elevation_ft']??'' ?>"></div>
            <div class="field"><label>Tower Height ft (AHAG)</label><input type="number" name="tower_height_ft" value="<?= $edit_site['tower_height_ft']??'' ?>"></div>
            <div class="field"><label>Tower Type</label><input type="text" name="tower_type" value="<?= htmlspecialchars($edit_site['tower_type']??'') ?>" placeholder="Rohn 25"></div>
        </div>
        <div class="field-row3">
            <div class="field"><label>Power Primary</label><input type="text" name="power_primary" value="<?= htmlspecialchars($edit_site['power_primary']??'') ?>" placeholder="Solar"></div>
            <div class="field"><label>Power Backup</label><input type="text" name="power_backup" value="<?= htmlspecialchars($edit_site['power_backup']??'') ?>" placeholder="Grid"></div>
            <div class="field"><label>Battery Ah</label><input type="number" name="battery_ah" value="<?= $edit_site['battery_ah']??'' ?>"></div>
        </div>
        <div class="field-row3">
            <div class="field"><label>Solar Watts</label><input type="number" name="solar_watts" value="<?= $edit_site['solar_watts']??'' ?>"></div>
            <div class="field"><label>Phase</label>
                <select name="phase"><?php foreach([1,2,3] as $p): ?><option value="<?=$p?>" <?=$edit_site['phase']==$p?'selected':''?>>Phase <?=$p?></option><?php endforeach; ?></select>
            </div>
            <div class="field"><label>Status</label>
                <select name="status"><?php foreach(['live','building','planned','offline'] as $st): ?><option value="<?=$st?>" <?=($edit_site['status']??'')===$st?'selected':''?>><?=ucfirst($st)?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div class="field-row">
            <div class="field"><label>Camera FTP Path</label><input type="text" name="camera_ftp_path" value="<?= htmlspecialchars($edit_site['camera_ftp_path']??'') ?>" placeholder="/path/to/latest.jpg"></div>
            <div class="field"><label>Weather Station ID</label><input type="text" name="weather_station" value="<?= htmlspecialchars($edit_site['weather_station']??'') ?>"></div>
        </div>
        <div class="field-row">
            <div class="field">
                <label>Photo URL</label>
                <input type="text" name="photo_url" value="<?= htmlspecialchars($edit_site['photo_url']??'') ?>">
                <?php if (!empty($edit_site['photo_url'])): ?>
                <div style="margin-top:0.4rem"><img src="<?= htmlspecialchars($edit_site['photo_url']) ?>" style="height:60px;object-fit:cover;border:1px solid var(--border2)"></div>
                <?php endif; ?>
            </div>
            <div class="field">
                <label>Upload Site Photo</label>
                <input type="file" name="site_photo" accept="image/*" style="color:var(--t2);font-size:0.75rem">
            </div>
        </div>
        <div class="field"><label>Coverage Map URL</label><input type="text" name="coverage_url" value="<?= htmlspecialchars($edit_site['coverage_url']??'') ?>"></div>
        <div class="field"><label>Notes (internal)</label><textarea name="notes" rows="3"><?= htmlspecialchars($edit_site['notes']??'') ?></textarea></div>
        <div class="check-row">
            <input type="checkbox" name="is_public" id="is_public" <?= $edit_site['is_public']?'checked':'' ?>>
            <label for="is_public">Public</label>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:1rem">Save Site</button>
    </form>
    </div>
</div>
<?php endif; ?>

<!-- ── SYSTEMS ── -->
<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-hd">
        Systems (<?= count($edit_systems) ?>)
        <?php if (can_edit_systems($edit_id, $is_admin, $my_op_id)): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('newSystemForm').style.display=document.getElementById('newSystemForm').style.display==='none'?'block':'none'">+ Add System</button>
        <?php endif; ?>
    </div>

    <?php foreach ($edit_systems as $sys): ?>
    <div style="border-bottom:1px solid var(--border2)">
        <!-- System header -->
        <div style="padding:0.8rem 1rem;display:flex;align-items:center;justify-content:space-between;background:var(--panel2)">
            <div>
                <span style="font-family:var(--mono);font-size:0.8rem;color:var(--t1)"><?= htmlspecialchars($sys['label'] ?: $sys['callsign']) ?></span>
                <span style="font-family:var(--mono);font-size:0.65rem;color:var(--amber);margin-left:0.6rem"><?= htmlspecialchars($sys['callsign']) ?></span>
                <?php if ($sys['freq_tx']): ?>
                <span style="font-family:var(--mono);font-size:0.7rem;color:var(--t2);margin-left:0.6rem"><?= $sys['freq_tx'] ?><?= $sys['freq_rx'] ? '/'.$sys['freq_rx'] : '' ?></span>
                <?php endif; ?>
                <span style="font-family:var(--mono);font-size:0.55rem;color:var(--t3);margin-left:0.5rem;text-transform:uppercase;letter-spacing:0.08em;border:1px solid var(--border2);padding:0.1rem 0.3rem"><?= $sys['system_type'] ?></span>
            </div>
            <div style="display:flex;gap:0.5rem;align-items:center">
                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleSys(<?= $sys['id'] ?>)">Edit ▾</button>
                <?php if ($is_admin): ?>
                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleMove(<?= $sys['id'] ?>)">Move →</button>
                <?php endif; ?>
                <?php if (can_edit_systems($edit_id, $is_admin, $my_op_id)): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this system and all its ASL nodes and modes?')">
                    <?= ttn_csrf_field() ?>
                    <input type="hidden" name="post_action" value="delete_system">
                    <input type="hidden" name="system_id" value="<?= $sys['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Del</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Move system form (collapsed by default) -->
        <?php if ($is_admin): ?>
        <div id="move-<?= $sys['id'] ?>" style="display:none;padding:0.8rem 1rem;border-top:1px solid var(--border);background:var(--panel2)">
            <form method="post" style="display:flex;gap:0.5rem;align-items:flex-end;flex-wrap:wrap">
                <?= ttn_csrf_field() ?>
                <input type="hidden" name="post_action" value="move_system">
                <input type="hidden" name="system_id" value="<?= $sys['id'] ?>">
                <div class="field" style="margin:0">
                    <label>Move to Site</label>
                    <select name="new_site_id">
                        <option value="">— select site —</option>
                        <?php foreach ($sites as $si): ?>
                        <?php if ($si['id'] == $edit_id) continue; ?>
                        <option value="<?= $si['id'] ?>"><?= htmlspecialchars($si['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="align-self:flex-end;margin-bottom:0.1rem" onclick="return confirm('Move this system to the selected site?')">Move</button>
                <button type="button" class="btn btn-secondary btn-sm" style="align-self:flex-end;margin-bottom:0.1rem" onclick="toggleMove(<?= $sys['id'] ?>)">Cancel</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- System edit form (collapsed by default) -->
        <div id="sys-<?= $sys['id'] ?>" style="display:none;padding:1rem;border-top:1px solid var(--border)">
        <form method="post" enctype="multipart/form-data">
            <?= ttn_csrf_field() ?>
            <input type="hidden" name="post_action" value="save_system">
            <input type="hidden" name="system_id" value="<?= $sys['id'] ?>">
            <input type="hidden" name="sys_callsign_slug" value="<?= preg_replace('/[^a-z0-9]/','',strtolower($sys['callsign'])) ?>">
            <div class="field-row3">
                <div class="field"><label>Callsign (Trustee)</label><input type="text" name="callsign" value="<?= htmlspecialchars($sys['callsign']) ?>" style="text-transform:uppercase"></div>
                <div class="field"><label>System Type</label>
                    <select name="system_type"><?php foreach(['repeater','remote_base','link','hub','beacon','other'] as $t): ?><option value="<?=$t?>" <?=$sys['system_type']===$t?'selected':''?>><?=ucfirst(str_replace('_',' ',$t))?></option><?php endforeach; ?></select>
                </div>
                <div class="field"><label>Status</label>
                    <select name="sys_status"><?php foreach(['live','building','planned','offline'] as $st): ?><option value="<?=$st?>" <?=($sys['status']??'')===$st?'selected':''?>><?=ucfirst($st)?></option><?php endforeach; ?></select>
                </div>
            </div>
            <div class="field-row3">
                <div class="field"><label>TX Freq (MHz)</label><input type="text" name="freq_tx" value="<?= htmlspecialchars($sys['freq_tx']??'') ?>" placeholder="53.870"></div>
                <div class="field"><label>RX Freq (MHz)</label><input type="text" name="freq_rx" value="<?= htmlspecialchars($sys['freq_rx']??'') ?>" placeholder="52.870"></div>
                <div class="field"><label>Band</label>
                    <select name="band"><?php foreach(['6m','2m','10m','70cm','440','HF','other'] as $b): ?><option value="<?=$b?>" <?=($sys['band']??'')===$b?'selected':''?>><?=$b?></option><?php endforeach; ?></select>
                </div>
            </div>
            <div class="field-row3">
                <div class="field"><label>Access Type</label>
                    <select name="access_type"><?php foreach(['CTCSS','DCS','none','carrier'] as $at): ?><option value="<?=$at?>" <?=($sys['access_type']??'')===$at?'selected':''?>><?=$at?></option><?php endforeach; ?></select>
                </div>
                <div class="field"><label>Access Code (PL/DCS)</label><input type="text" name="access_code" value="<?= htmlspecialchars($sys['access_code']??'') ?>" placeholder="118.8"></div>
                <div class="field"><label>Label</label><input type="text" name="label" value="<?= htmlspecialchars($sys['label']??'') ?>" placeholder="Primary 6m Repeater"></div>
            </div>
            <div class="field-row3">
                <div class="field"><label>TX Power (W)</label><input type="number" name="tx_power_watts" value="<?= $sys['tx_power_watts']??'' ?>"></div>
                <div class="field"><label>Duplexer Loss (dB)</label><input type="text" name="duplexer_loss_db" value="<?= $sys['duplexer_loss_db']??'' ?>"></div>
                <div class="field"><label>ERP (W)</label><input type="number" name="erp_watts" value="<?= $sys['erp_watts']??'' ?>"></div>
            </div>
            <div class="field-row3">
                <div class="field"><label>Antenna Make</label><input type="text" name="antenna_make" value="<?= htmlspecialchars($sys['antenna_make']??'') ?>"></div>
                <div class="field"><label>Antenna Model</label><input type="text" name="antenna_model" value="<?= htmlspecialchars($sys['antenna_model']??'') ?>"></div>
                <div class="field"><label>Antenna Gain (dB)</label><input type="text" name="antenna_gain_db" value="<?= $sys['antenna_gain_db']??'' ?>"></div>
            </div>
            <div class="field-row3">
                <div class="field"><label>Feedline Make</label><input type="text" name="feedline_make" value="<?= htmlspecialchars($sys['feedline_make']??'') ?>" placeholder="Andrew LDF 7/8&quot;"></div>
                <div class="field"><label>Feedline Length (ft)</label><input type="number" name="feedline_length_ft" value="<?= $sys['feedline_length_ft']??'' ?>"></div>
                <div class="field"><label>Feedline Loss (dB)</label><input type="text" name="feedline_loss_db" value="<?= $sys['feedline_loss_db']??'' ?>"></div>
            </div>
            <div class="field-row">
                <div class="field"><label>Sort Order</label><input type="number" name="sort_order" value="<?= $sys['sort_order']??0 ?>" min="0"></div>
            </div>
            <div class="field"><label>Intermod Notes</label><textarea name="intermod_notes" rows="2"><?= htmlspecialchars($sys['intermod_notes']??'') ?></textarea></div>
            <div class="field"><label>Notes (internal)</label><textarea name="sys_notes" rows="2"><?= htmlspecialchars($sys['notes']??'') ?></textarea></div>
            <div class="field-row">
                <div class="field">
                    <label>Photo URL</label>
                    <input type="text" name="sys_photo_url" value="<?= htmlspecialchars($sys['photo_url']??'') ?>">
                </div>
                <div class="field">
                    <label>Upload Photo</label>
                    <input type="file" name="sys_photo" accept="image/*" style="color:var(--t2);font-size:0.75rem">
                </div>
            </div>
            <div class="check-row">
                <input type="checkbox" name="sys_is_public" id="sys_pub_<?=$sys['id']?>" <?= ($sys['is_public']??1)?'checked':'' ?>>
                <label for="sys_pub_<?=$sys['id']?>">Public</label>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:0.8rem">Save System</button>
        </form>

        <!-- Modes — inline checkboxes, saved with system -->
        <div style="margin-top:0.8rem">
            <div style="font-family:var(--mono);font-size:0.6rem;color:var(--t3);letter-spacing:0.1em;text-transform:uppercase;margin-bottom:0.5rem">Modes (select all that apply)</div>
            <?php
            $active_modes = array_column($sys['modes'], 'mode');
            $all_modes = ['FM','DMR','M17','Fusion','DStar','P25','NXDN','other'];
            ?>
            <?php if ($sys['system_type'] === 'hub'): ?>
            <div style="font-family:var(--mono);font-size:0.65rem;color:var(--t3)">Hub node — ASL only, no RF mode required.</div>
            <input type="hidden" name="modes[]" value="Hub">
            <?php else: ?>
            <div style="display:flex;gap:0.8rem;flex-wrap:wrap">
            <?php foreach ($all_modes as $mopt): ?>
            <div class="check-row" style="margin:0">
                <input type="checkbox" name="modes[]" value="<?= $mopt ?>"
                       id="mode_<?= $sys['id'] ?>_<?= $mopt ?>"
                       <?= in_array($mopt, $active_modes) ? 'checked' : '' ?>>
                <label for="mode_<?= $sys['id'] ?>_<?= $mopt ?>"><?= $mopt ?></label>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ASL Nodes -->
        <div style="margin-top:1.2rem">
            <div style="font-family:var(--mono);font-size:0.6rem;color:var(--t3);letter-spacing:0.1em;text-transform:uppercase;margin-bottom:0.5rem">AllStar Nodes</div>
            <table class="adm-tbl" style="margin-bottom:0.7rem">
                <thead><tr><th>ASL Node</th><th>Label</th><th>Hub</th><th>Server</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($sys['asls'] as $a): ?>
                <tr>
                    <form method="post">
                        <?= ttn_csrf_field() ?>
                        <input type="hidden" name="post_action" value="save_asl">
                        <input type="hidden" name="asl_system_id" value="<?= $sys['id'] ?>">
                        <input type="hidden" name="asl_id" value="<?= $a['id'] ?>">
                        <td><input type="text" name="asl_number" value="<?= htmlspecialchars($a['asl_number']) ?>" style="width:80px;font-family:var(--mono);font-size:0.75rem;color:var(--amber)"></td>
                        <td><input type="text" name="asl_label" value="<?= htmlspecialchars($a['label']??'') ?>" style="width:110px;font-size:0.75rem"></td>
                        <td style="text-align:center"><input type="checkbox" name="is_hub" <?= $a['is_hub'] ? 'checked' : '' ?>></td>
                        <td>
                            <select name="server_id" style="font-size:0.7rem">
                                <option value="">— none —</option>
                                <?php foreach ($asl_servers as $srv): ?>
                                <option value="<?= $srv['id'] ?>" <?= ($a['server_id']??0)==$srv['id']?'selected':'' ?>><?= htmlspecialchars($srv['hostname']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td style="display:flex;gap:0.3rem">
                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="if(confirm('Remove?'))this.closest('form').innerHTML='<input type=hidden name=post_action value=delete_asl><input type=hidden name=asl_id value=<?= $a['id'] ?>>';this.closest('form').submit()">Del</button>
                        </td>
                    </form>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($sys['asls'])): ?><tr><td colspan="5" class="muted">No ASL nodes.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <!-- Add ASL -->
            <form method="post" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end">
                <?= ttn_csrf_field() ?>
                <input type="hidden" name="post_action" value="save_asl">
                <input type="hidden" name="asl_system_id" value="<?= $sys['id'] ?>">
                <div class="field" style="margin:0"><label>ASL Number</label><input type="text" name="asl_number" placeholder="450330" required style="width:90px"></div>
                <div class="field" style="margin:0"><label>Label</label><input type="text" name="asl_label" placeholder="Hub" style="width:110px"></div>
                <div class="field" style="margin:0">
                    <label>Server</label>
                    <select name="server_id">
                        <option value="">— none —</option>
                        <?php foreach ($asl_servers as $srv): ?>
                        <option value="<?= $srv['id'] ?>"><?= htmlspecialchars($srv['hostname']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="check-row" style="align-self:flex-end;padding-bottom:0.9rem">
                    <input type="checkbox" name="is_hub" id="ah_<?=$sys['id']?>">
                    <label for="ah_<?=$sys['id']?>">Hub</label>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="align-self:flex-end;margin-bottom:0.1rem">Add ASL</button>
            </form>
        </div>

        <!-- Interfaces -->
        <div style="margin-top:1.2rem">
            <div style="font-family:var(--mono);font-size:0.6rem;color:var(--t3);letter-spacing:0.1em;text-transform:uppercase;margin-bottom:0.5rem">Interface Links</div>
            <table class="adm-tbl" style="margin-bottom:0.7rem">
                <thead><tr><th>Label</th><th>Type</th><th>URL</th><th>Public</th><th>Sort</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($sys['interfaces'] as $iface): ?>
                <tr>
                    <form method="post">
                        <?= ttn_csrf_field() ?>
                        <input type="hidden" name="post_action" value="save_interface">
                        <input type="hidden" name="iface_system_id" value="<?= $sys['id'] ?>">
                        <input type="hidden" name="iface_id" value="<?= $iface['id'] ?>">
                        <td><input type="text" name="iface_label" value="<?= htmlspecialchars($iface['label']) ?>" style="width:90px;font-size:0.75rem"></td>
                        <td>
                            <select name="iface_type" style="font-size:0.7rem">
                                <?php foreach(['supermon','allmon3','allscan','allscanx','stream','camera','custom'] as $t): ?>
                                <option value="<?=$t?>" <?=$iface['interface_type']===$t?'selected':''?>><?=ucfirst($t)?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" name="iface_url" value="<?= htmlspecialchars($iface['url']) ?>" style="width:200px;font-size:0.72rem;font-family:var(--mono)"></td>
                        <td style="text-align:center"><input type="checkbox" name="iface_public" <?= $iface['is_public'] ? 'checked' : '' ?>></td>
                        <td><input type="number" name="iface_sort" value="<?= $iface['sort_order'] ?>" style="width:45px;font-size:0.72rem"></td>
                        <td style="display:flex;gap:0.3rem">
                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="if(confirm('Remove?'))this.closest('form').innerHTML='<input type=hidden name=post_action value=delete_interface><input type=hidden name=iface_id value=<?= $iface['id'] ?>>';this.closest('form').submit()">Del</button>
                        </td>
                    </form>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($sys['interfaces'])): ?><tr><td colspan="6" class="muted">No interfaces yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <!-- Add Interface -->
            <form method="post" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end">
                <?= ttn_csrf_field() ?>
                <input type="hidden" name="post_action" value="save_interface">
                <input type="hidden" name="iface_system_id" value="<?= $sys['id'] ?>">
                <div class="field" style="margin:0"><label>Label</label><input type="text" name="iface_label" placeholder="Supermon" style="width:90px"></div>
                <div class="field" style="margin:0">
                    <label>Type</label>
                    <select name="iface_type" style="font-size:0.7rem">
                        <?php foreach(['supermon','allmon3','allscan','allscanx','stream','camera','custom'] as $t): ?>
                        <option value="<?=$t?>"><?=ucfirst($t)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="margin:0"><label>URL</label><input type="text" name="iface_url" placeholder="https://tn.w4bww.net/supermon" required style="width:220px;font-family:var(--mono);font-size:0.72rem"></div>
                <div class="check-row" style="align-self:flex-end;padding-bottom:0.9rem">
                    <input type="checkbox" name="iface_public" id="ip_<?=$sys['id']?>" checked>
                    <label for="ip_<?=$sys['id']?>">Public</label>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="align-self:flex-end;margin-bottom:0.1rem">Add Interface</button>
            </form>
        </div>

        <!-- SERA Coordination -->
        <div style="margin-top:1.2rem">
            <div style="font-family:var(--mono);font-size:0.6rem;color:var(--t3);letter-spacing:0.1em;text-transform:uppercase;margin-bottom:0.5rem">SERA Frequency Coordination — TTN Sovereign Record</div>
            <?php foreach ($sys['sera'] as $sr): ?>
            <form method="post" style="background:var(--panel2);border:1px solid var(--border2);padding:1rem;margin-bottom:0.8rem">
                <?= ttn_csrf_field() ?>
                <input type="hidden" name="post_action" value="save_sera">
                <input type="hidden" name="sera_system_id" value="<?= $sys['id'] ?>">
                <input type="hidden" name="sera_id_pk" value="<?= $sr['id'] ?>">
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0.5rem;margin-bottom:0.5rem">
                    <div class="field" style="margin:0"><label>SERA ID</label><input type="text" name="sera_id" value="<?= htmlspecialchars($sr['sera_id']) ?>" style="width:100%"></div>
                    <div class="field" style="margin:0"><label>GUID</label><input type="text" name="sera_guid" value="<?= htmlspecialchars($sr['sera_guid']??'') ?>" style="width:100%;font-size:0.65rem"></div>
                    <div class="field" style="margin:0"><label>Status</label>
                        <select name="sera_status">
                            <?php foreach(['coordinated','pending','expired','denied','recertified'] as $st): ?>
                            <option value="<?=$st?>" <?=$sr['status']===$st?'selected':''?>><?=ucfirst($st)?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="check-row" style="align-self:flex-end;padding-bottom:0.9rem">
                        <input type="checkbox" name="publish_journal" id="pj_<?=$sr['id']?>" <?=$sr['publish_journal']?'checked':''?>>
                        <label for="pj_<?=$sr['id']?>">Publish in SERA Journal</label>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.5rem;margin-bottom:0.5rem">
                    <div class="field" style="margin:0"><label>Coordinated</label><input type="date" name="coordinated_at" value="<?= $sr['coordinated_at'] ?>" style="width:100%"></div>
                    <div class="field" style="margin:0"><label>Recertify By</label><input type="date" name="expires_at" value="<?= $sr['expires_at'] ?>" style="width:100%"></div>
                    <div class="field" style="margin:0"><label>Last Recertified</label><input type="date" name="recertified_at" value="<?= $sr['recertified_at']??'' ?>" style="width:100%"></div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0.5rem;margin-bottom:0.5rem">
                    <div class="field" style="margin:0"><label>Trustee</label><input type="text" name="trustee_call" value="<?= htmlspecialchars($sr['trustee_call']??'') ?>" style="width:100%"></div>
                    <div class="field" style="margin:0"><label>Alt Callsign</label><input type="text" name="alt_call" value="<?= htmlspecialchars($sr['alt_call']??'') ?>" style="width:100%"></div>
                    <div class="field" style="margin:0"><label>Alt Phone</label><input type="text" name="alt_phone" value="<?= htmlspecialchars($sr['alt_phone']??'') ?>" style="width:100%"></div>
                    <div class="field" style="margin:0"><label>Alt Email</label><input type="text" name="alt_email" value="<?= htmlspecialchars($sr['alt_email']??'') ?>" style="width:100%"></div>
                </div>
                <div class="field" style="margin:0 0 0.5rem"><label>Features (as coordinated by SERA)</label><input type="text" name="features" value="<?= htmlspecialchars($sr['features']??'') ?>" style="width:100%"></div>
                <div class="field" style="margin:0 0 0.5rem"><label>Notes</label><textarea name="sera_notes" rows="2" style="width:100%"><?= htmlspecialchars($sr['notes']??'') ?></textarea></div>
                <div style="display:flex;gap:0.5rem;align-items:center">
                    <button type="submit" class="btn btn-primary btn-sm">Save SERA</button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="if(confirm('Delete this SERA record?'))this.closest('form').innerHTML='<?= ttn_csrf_field() ?><input type=hidden name=post_action value=delete_sera><input type=hidden name=sera_id_pk value=<?= $sr['id'] ?>>';this.closest('form').submit()">Delete</button>
                    <?php if ($sr['expires_at'] && strtotime($sr['expires_at']) < strtotime('+60 days')): ?>
                    <span style="font-family:var(--mono);font-size:0.58rem;color:var(--red);margin-left:0.5rem">⚠ Recertify by <?= $sr['expires_at'] ?></span>
                    <?php endif; ?>
                </div>
            </form>
            <?php endforeach; ?>
            <?php if (empty($sys['sera'])): ?>
            <form method="post" style="background:var(--panel2);border:1px solid var(--border2);padding:1rem">
                <?= ttn_csrf_field() ?>
                <input type="hidden" name="post_action" value="save_sera">
                <input type="hidden" name="sera_system_id" value="<?= $sys['id'] ?>">
                <input type="hidden" name="sera_id_pk" value="0">
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.5rem;margin-bottom:0.5rem">
                    <div class="field" style="margin:0"><label>SERA ID</label><input type="text" name="sera_id" placeholder="7188" style="width:100%"></div>
                    <div class="field" style="margin:0"><label>GUID</label><input type="text" name="sera_guid" placeholder="6c2cfb81-..." style="width:100%;font-size:0.65rem"></div>
                    <div class="field" style="margin:0"><label>Status</label>
                        <select name="sera_status">
                            <?php foreach(['coordinated','pending','expired','denied','recertified'] as $st): ?>
                            <option value="<?=$st?>"><?=ucfirst($st)?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.5rem;margin-bottom:0.5rem">
                    <div class="field" style="margin:0"><label>Coordinated</label><input type="date" name="coordinated_at" style="width:100%"></div>
                    <div class="field" style="margin:0"><label>Recertify By</label><input type="date" name="expires_at" style="width:100%"></div>
                </div>
                <div class="field" style="margin:0 0 0.5rem"><label>Trustee</label><input type="text" name="trustee_call" placeholder="W4BWW" style="width:100%"></div>
                <div class="field" style="margin:0 0 0.5rem"><label>Features</label><input type="text" name="features" placeholder="Emergency Power,Solar,Linked,Echolink" style="width:100%"></div>
                <button type="submit" class="btn btn-primary btn-sm">Add SERA Record</button>
            </form>
            <?php endif; ?>
        </div>

        </div><!-- end sys edit -->

    </div>
    <?php endforeach; ?>

    <!-- New system form (hidden) -->
    <?php if (can_edit_systems($edit_id, $is_admin, $my_op_id)): ?>
    <div id="newSystemForm" style="display:none;padding:1rem;border-top:1px solid var(--border2)">
    <form method="post">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="create_system">
        <input type="hidden" name="site_id" value="<?= $edit_id ?>">
        <div class="field-row3">
            <div class="field"><label>Callsign (Trustee) *</label><input type="text" name="callsign" placeholder="W4BWW" required style="text-transform:uppercase"></div>
            <div class="field"><label>System Type</label>
                <select name="system_type"><?php foreach(['repeater','remote_base','link','hub','beacon','other'] as $t): ?><option value="<?=$t?>"><?=ucfirst(str_replace('_',' ',$t))?></option><?php endforeach; ?></select>
            </div>
            <div class="field"><label>Status</label>
                <select name="sys_status"><?php foreach(['planned','building','live','offline'] as $st): ?><option value="<?=$st?>"><?=ucfirst($st)?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div class="field-row3">
            <div class="field"><label>TX Freq (MHz)</label><input type="text" name="freq_tx" placeholder="53.870"></div>
            <div class="field"><label>RX Freq (MHz)</label><input type="text" name="freq_rx" placeholder="52.870"></div>
            <div class="field"><label>Band</label>
                <select name="band"><?php foreach(['6m','2m','10m','70cm','440','HF','other'] as $b): ?><option><?=$b?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div class="field-row">
            <div class="field"><label>Access Code (PL)</label><input type="text" name="access_code" placeholder="118.8"></div>
            <div class="field"><label>Label</label><input type="text" name="label" placeholder="Primary 6m Repeater"></div>
        </div>
        <div class="check-row">
            <input type="checkbox" name="sys_is_public" id="new_sys_pub" checked>
            <label for="new_sys_pub">Public</label>
        </div>
        <div style="display:flex;gap:0.7rem;margin-top:0.8rem">
            <button type="submit" class="btn btn-primary">Create System</button>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('newSystemForm').style.display='none'">Cancel</button>
        </div>
    </form>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ── SITE LIST ── -->
<div class="panel">
    <div class="panel-hd">All Sites (<?= count($sites) ?>)</div>
    <table class="adm-tbl">
        <thead><tr><th>Name</th><th>Location</th><th>Status</th><th>Phase</th><th>Systems</th><th>Tower</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sites as $si):
            $sc = 'var(--t3)';
            if ($si['status'] === 'live')     $sc = 'var(--green)';
            if ($si['status'] === 'building') $sc = 'var(--amber)';
        ?>
        <tr>
            <td class="mono"><?= htmlspecialchars($si['name']) ?></td>
            <td class="muted"><?= htmlspecialchars($si['city'] ? $si['city'].', '.$si['state'] : $si['state']) ?></td>
            <td><span style="font-family:var(--mono);font-size:0.6rem;text-transform:uppercase;letter-spacing:0.08em;color:<?= $sc ?>"><?= $si['status'] ?></span></td>
            <td class="mono muted"><?= $si['phase'] ?></td>
            <td class="mono muted"><?= $si['system_count'] ?></td>
            <td class="mono muted"><?= $si['tower_height_ft'] ? $si['tower_height_ft'].'ft' : '—' ?></td>
            <td>
                <div class="actions">
                    <?php if (can_edit_site((int)$si['id'], $is_admin, $my_op_id) || can_edit_systems((int)$si['id'], $is_admin, $my_op_id)): ?>
                    <a href="?action=edit&id=<?= $si['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
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

<script>
function toggleSys(id) {
    const el = document.getElementById('sys-' + id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function toggleMove(id) {
    const el = document.getElementById('move-' + id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>
</div>
</body>
</html>
