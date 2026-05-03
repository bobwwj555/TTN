<?php
require_once '/home/obdswlpx/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_require_role('admin');

$adm_title = 'Settings';
$adm_page  = 'settings';
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ttn_csrf_verify($_POST['csrf_token'] ?? '');
    $pa = $_POST['post_action'] ?? '';

    // ── SAVE TEXT SETTINGS ───────────────────────────────────
    if ($pa === 'save_settings') {
        $updates = $_POST['setting'] ?? [];
        $count   = 0;
        foreach ($updates as $key => $val) {
            // Sanitize key — only lowercase letters, numbers, underscores
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower($key));
            if (!$key) continue;
            // key FIRST, value SECOND — this is the correct order
            db_execute("INSERT INTO site_settings (setting_key, setting_val) VALUES (?,?)
                        ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)",
                [$key, trim($val)]);
            $count++;
        }
        $msg = "$count settings saved.";
    }

    // ── UPLOAD IMAGE ─────────────────────────────────────────
    if ($pa === 'upload_image') {
        $setting_key = preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['img_setting_key'] ?? ''));
        $file        = $_FILES['img_file'] ?? null;
        if (!$setting_key) {
            $err = 'Select a setting key for this image.';
        } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $err = 'Upload error: ' . ($file['error'] ?? 'no file');
        } else {
            $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
            if (!in_array($file['type'], $allowed)) {
                $err = 'Invalid image type.';
            } elseif ($file['size'] > 5*1024*1024) {
                $err = 'Image too large (max 5MB).';
            } else {
                $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $fname   = $setting_key . '_' . time() . '.' . $ext;
                $dest    = '/home/obdswlpx/dev.ttn.radio/assets/img/' . $fname;
                $new_url = '/assets/img/' . $fname;
                if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    // key FIRST, value SECOND
                    db_execute("INSERT INTO site_settings (setting_key, setting_val) VALUES (?,?)
                                ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)",
                        [$setting_key, $new_url]);
                    $msg = "Image uploaded and $setting_key updated.";
                } else {
                    $err = 'Could not save file. Check directory permissions.';
                }
            }
        }
    }
}

// Load current settings — only clean keys
$settings = db_rows("SELECT setting_key, setting_val FROM site_settings
    WHERE setting_key REGEXP '^[a-z][a-z0-9_]*$'
    ORDER BY setting_key");

$setting_groups = [
    'site'   => ['site_url','hub_url','hub_node','hub_freq','ami_proxy_url','site_donate_url','meta_description'],
    'org'    => ['org_name','org_callsign','org_ein','org_nonprofit','org_state'],
    'contact'=> ['contact_name','contact_email','contact_phone','contact_address'],
    'social' => ['social_facebook','social_github','paypal_url'],
    'images' => ['img_logo','img_logo_full','img_hero','img_hero_bg','img_coverage'],
];

// Build map for display
$setting_map = [];
foreach ($settings as $s) $setting_map[$s['setting_key']] = $s['setting_val'];

// All image keys for upload form
$img_keys = ['img_logo','img_logo_full','img_hero','img_hero_bg','img_coverage'];

require_once TTN_INCLUDES . '/admin_head.php';
require_once TTN_INCLUDES . '/admin_nav.php';
?>
<div class="adm-main">
<div class="adm-topbar">
    <div class="adm-topbar-title">Settings</div>
</div>
<div class="adm-body">

<?php if($msg): ?><div class="msg-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="msg-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<form method="post">
<?= ttn_csrf_field() ?>
<input type="hidden" name="post_action" value="save_settings">

<?php foreach ($setting_groups as $group_name => $keys): ?>
<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-hd"><?= ucfirst($group_name) ?></div>
    <div class="panel-body">
    <?php foreach ($keys as $key):
        $val = $setting_map[$key] ?? '';
        $is_img = in_array($key, $img_keys);
    ?>
    <div class="field">
        <label><?= htmlspecialchars($key) ?></label>
        <?php if ($is_img): ?>
        <input type="text" name="setting[<?= htmlspecialchars($key) ?>]" value="<?= htmlspecialchars($val) ?>">
        <?php if ($val): ?>
        <div style="margin-top:0.4rem"><img src="<?= htmlspecialchars($val) ?>" style="height:50px;border:1px solid var(--border2)" onerror="this.style.display='none'"></div>
        <?php endif; ?>
        <?php else: ?>
        <input type="text" name="setting[<?= htmlspecialchars($key) ?>]" value="<?= htmlspecialchars($val) ?>">
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<button type="submit" class="btn btn-primary">Save All Settings</button>
</form>

<!-- Image Upload -->
<div class="panel" style="margin-top:2rem">
    <div class="panel-hd">Upload Image</div>
    <div class="panel-body">
    <form method="post" enctype="multipart/form-data">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="upload_image">
        <div class="field-row">
            <div class="field">
                <label>Setting Key</label>
                <select name="img_setting_key">
                    <?php foreach ($img_keys as $ik): ?>
                    <option value="<?= $ik ?>"><?= $ik ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Image File</label>
                <input type="file" name="img_file" accept="image/*" style="color:var(--t2);font-size:0.75rem">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Upload</button>
    </form>
    </div>
</div>

</div>
</div>
</div>
</body>
</html>
