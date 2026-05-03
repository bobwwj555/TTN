<?php
require_once '/home/obdswlpx/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_require_role('operator');

$adm_title = 'Media Library';
$adm_page  = 'media';
$msg = $err = '';

$upload_dir      = '/home/obdswlpx/dev.ttn.radio/assets/img/';
$upload_url_base = s('site_url','https://dev.ttn.radio') . '/assets/img/';
$allowed_ext     = ['jpg','jpeg','png','gif','webp','svg'];
$allowed_types   = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
$max_size        = 8 * 1024 * 1024; // 8MB

if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

// ── HANDLE POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ttn_csrf_verify($_POST['csrf_token'] ?? '');
    $pa = $_POST['post_action'] ?? '';

    // Upload
    if ($pa === 'upload') {
        $files = $_FILES['media_files'] ?? null;
        $uploaded = 0;
        $errors   = [];

        if ($files && is_array($files['name'])) {
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    $errors[] = $files['name'][$i] . ': upload error ' . $files['error'][$i];
                    continue;
                }
                if ($files['size'][$i] > $max_size) {
                    $errors[] = $files['name'][$i] . ': exceeds 8MB limit';
                    continue;
                }
                if (!in_array($files['type'][$i], $allowed_types)) {
                    $errors[] = $files['name'][$i] . ': type not allowed';
                    continue;
                }
                $orig = pathinfo($files['name'][$i], PATHINFO_FILENAME);
                $ext  = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                // Sanitize filename
                $safe = preg_replace('/[^a-z0-9_\-]/', '_', strtolower($orig));
                $safe = trim($safe, '_');
                $fname = $safe . '.' . $ext;
                // Avoid collisions
                $n = 1;
                while (file_exists($upload_dir . $fname)) {
                    $fname = $safe . '_' . $n . '.' . $ext;
                    $n++;
                }
                if (move_uploaded_file($files['tmp_name'][$i], $upload_dir . $fname)) {
                    $uploaded++;
                } else {
                    $errors[] = $files['name'][$i] . ': could not save file';
                }
            }
        }
        if ($uploaded) $msg = "$uploaded file(s) uploaded successfully.";
        if ($errors)   $err = implode('<br>', $errors);
    }

    // Delete
    if ($pa === 'delete' && ttn_has_role('admin')) {
        $fname = basename($_POST['filename'] ?? '');
        $fpath = $upload_dir . $fname;
        if ($fname && file_exists($fpath) && is_file($fpath)) {
            unlink($fpath);
            $msg = "$fname deleted.";
        } else {
            $err = 'File not found.';
        }
    }

    // Rename
    if ($pa === 'rename' && ttn_has_role('admin')) {
        $old_name = basename($_POST['old_name'] ?? '');
        $new_name = preg_replace('/[^a-z0-9_\-\.]/', '_', strtolower(basename($_POST['new_name'] ?? '')));
        $old_path = $upload_dir . $old_name;
        $new_path = $upload_dir . $new_name;
        if ($old_name && $new_name && file_exists($old_path)) {
            if (file_exists($new_path)) {
                $err = 'A file with that name already exists.';
            } else {
                rename($old_path, $new_path);
                $msg = "Renamed to $new_name.";
            }
        } else {
            $err = 'File not found.';
        }
    }

    // Set as image setting
    if ($pa === 'set_setting') {
        $setting_key = preg_replace('/[^a-z0-9_]/', '', $_POST['setting_key'] ?? '');
        $fname       = basename($_POST['filename'] ?? '');
        if ($setting_key && $fname) {
            $url = '/assets/img/' . $fname;
            db_execute("INSERT INTO site_settings (setting_key, setting_val) VALUES (?,?)
                        ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)",
                [$setting_key, $url]);
            $msg = "Setting '$setting_key' updated to $fname.";
        }
    }
}

// ── LOAD FILES ────────────────────────────────────────────────
$files = [];
if (is_dir($upload_dir)) {
    foreach (scandir($upload_dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $fp  = $upload_dir . $f;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext) || !is_file($fp)) continue;
        $files[] = [
            'name'     => $f,
            'url'      => '/assets/img/' . $f,
            'size'     => filesize($fp),
            'modified' => filemtime($fp),
            'ext'      => $ext,
        ];
    }
    usort($files, fn($a,$b) => $b['modified'] - $a['modified']); // newest first
}

// Load image settings for quick-assign
$img_settings = db_rows("SELECT setting_key, setting_val FROM site_settings WHERE setting_key LIKE 'img_%' ORDER BY setting_key");

require_once TTN_INCLUDES . '/admin_head.php';
require_once TTN_INCLUDES . '/admin_nav.php';

function fmt_bytes(int $b): string {
    if ($b >= 1048576) return round($b/1048576,1) . ' MB';
    if ($b >= 1024)    return round($b/1024,1)    . ' KB';
    return $b . ' B';
}
?>
<style>
.media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin-top:1rem}
.media-card{background:var(--panel2);border:1px solid var(--border2);display:flex;flex-direction:column;overflow:hidden;transition:border-color 0.15s;min-width:0;max-width:100%}
.media-card:hover{border-color:var(--green)}
.media-thumb{height:140px;background:var(--bg);display:flex;align-items:center;justify-content:center;overflow:hidden;cursor:pointer;position:relative}
.media-thumb img{max-width:100%;max-height:100%;object-fit:contain}
.media-thumb .copy-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.7);display:none;align-items:center;justify-content:center;font-family:var(--mono);font-size:0.65rem;color:var(--green);text-align:center;padding:0.5rem}
.media-thumb:hover .copy-overlay{display:flex}
.media-info{padding:0.6rem 0.7rem;flex:1;display:flex;flex-direction:column;gap:0.3rem}
.media-fname{font-family:var(--mono);font-size:0.6rem;color:var(--t1);word-break:break-all;line-height:1.4;overflow:hidden;max-height:2.8em}
.media-meta{font-family:var(--mono);font-size:0.55rem;color:var(--t3)}
.media-actions{display:flex;gap:0.3rem;flex-wrap:wrap;margin-top:0.3rem}
.upload-zone{border:2px dashed var(--border2);padding:2rem;text-align:center;font-family:var(--mono);font-size:0.7rem;color:var(--t3);cursor:pointer;transition:border-color 0.15s;margin-bottom:1rem}
.upload-zone:hover,.upload-zone.drag{border-color:var(--green);color:var(--green)}
.media-empty{font-family:var(--mono);font-size:0.7rem;color:var(--t3);padding:2rem;text-align:center}
.toast{position:fixed;bottom:1.5rem;right:1.5rem;background:var(--panel2);border:1px solid var(--green);color:var(--green);font-family:var(--mono);font-size:0.7rem;padding:0.7rem 1.2rem;z-index:999;display:none}
</style>

<div class="adm-main">
<div class="adm-topbar">
    <div class="adm-topbar-title">Media Library (<?= count($files) ?> files)</div>
</div>
<div class="adm-body">

<?php if($msg): ?><div class="msg-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="msg-err"><?= $err ?></div><?php endif; ?>

<!-- ── UPLOAD ── -->
<form method="post" enctype="multipart/form-data" id="uploadForm">
    <?= ttn_csrf_field() ?>
    <input type="hidden" name="post_action" value="upload">
    <div class="upload-zone" id="uploadZone" onclick="document.getElementById('mediaInput').click()">
        <div>▲ Click to upload or drag & drop images here</div>
        <div style="margin-top:0.4rem;font-size:0.6rem">JPG · PNG · GIF · WebP · SVG · Max 8MB each · Multiple files OK</div>
        <input type="file" name="media_files[]" id="mediaInput" accept="image/*" multiple style="display:none" onchange="document.getElementById('uploadForm').submit()">
    </div>
</form>

<!-- ── MEDIA GRID ── -->
<?php if (empty($files)): ?>
<div class="media-empty">No images uploaded yet. Drop some files above.</div>
<?php else: ?>
<div class="media-grid">
<?php foreach ($files as $f): ?>
<div class="media-card">
    <div class="media-thumb" onclick="copyUrl('<?= htmlspecialchars($f['url']) ?>')">
        <img src="<?= htmlspecialchars($f['url']) ?>" alt="<?= htmlspecialchars($f['name']) ?>" loading="lazy">
        <div class="copy-overlay">📋 Click to copy URL</div>
    </div>
    <div class="media-info">
        <div class="media-fname"><?= htmlspecialchars($f['name']) ?></div>
        <div class="media-meta"><?= fmt_bytes($f['size']) ?> · <?= strtoupper($f['ext']) ?> · <?= date('M j Y', $f['modified']) ?></div>
        <div class="media-actions">
            <button type="button" class="btn btn-secondary btn-sm" onclick="copyUrl('<?= htmlspecialchars($f['url']) ?>')">Copy URL</button>
            <?php if (ttn_has_role('admin')): ?>
            <button type="button" class="btn btn-secondary btn-sm" onclick="showRename('<?= htmlspecialchars($f['name']) ?>')">Rename</button>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete <?= htmlspecialchars($f['name']) ?>?')">
                <?= ttn_csrf_field() ?>
                <input type="hidden" name="post_action" value="delete">
                <input type="hidden" name="filename" value="<?= htmlspecialchars($f['name']) ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
            <?php endif; ?>
        </div>
        <?php if (!empty($img_settings) && ttn_has_role('admin')): ?>
        <form method="post" style="margin-top:0.4rem;display:flex;gap:0.3rem">
            <?= ttn_csrf_field() ?>
            <input type="hidden" name="post_action" value="set_setting">
            <input type="hidden" name="filename" value="<?= htmlspecialchars($f['name']) ?>">
            <select name="setting_key" style="font-size:0.6rem;font-family:var(--mono);flex:1;background:var(--bg);color:var(--t1);border:1px solid var(--border2);padding:0.2rem 0.3rem">
                <option value="">— Use as setting —</option>
                <?php foreach ($img_settings as $is): ?>
                <option value="<?= htmlspecialchars($is['setting_key']) ?>"><?= htmlspecialchars($is['setting_key']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Set</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

</div>
</div>

<!-- ── RENAME MODAL ── -->
<div id="renameModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:100;align-items:center;justify-content:center">
    <div style="background:var(--panel2);border:1px solid var(--border2);padding:1.5rem;width:360px">
        <div style="font-family:var(--mono);font-size:0.7rem;color:var(--t2);margin-bottom:1rem">Rename File</div>
        <form method="post">
            <?= ttn_csrf_field() ?>
            <input type="hidden" name="post_action" value="rename">
            <input type="hidden" name="old_name" id="renameOld">
            <div class="field" style="margin-bottom:1rem">
                <label>New filename</label>
                <input type="text" name="new_name" id="renameNew" style="width:100%">
            </div>
            <div style="display:flex;gap:0.5rem">
                <button type="submit" class="btn btn-primary">Rename</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('renameModal').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div class="toast" id="toast">✓ URL copied to clipboard</div>

<script>
function copyUrl(url) {
    var full = window.location.origin + url;
    navigator.clipboard.writeText(full).then(function() {
        var t = document.getElementById('toast');
        t.style.display = 'block';
        setTimeout(function(){ t.style.display = 'none'; }, 2000);
    }).catch(function() {
        prompt('Copy this URL:', window.location.origin + url);
    });
}

function showRename(fname) {
    document.getElementById('renameOld').value = fname;
    document.getElementById('renameNew').value = fname;
    var m = document.getElementById('renameModal');
    m.style.display = 'flex';
}

// Drag and drop
var zone = document.getElementById('uploadZone');
zone.addEventListener('dragover', function(e){ e.preventDefault(); zone.classList.add('drag'); });
zone.addEventListener('dragleave', function(){ zone.classList.remove('drag'); });
zone.addEventListener('drop', function(e){
    e.preventDefault();
    zone.classList.remove('drag');
    var input = document.getElementById('mediaInput');
    input.files = e.dataTransfer.files;
    document.getElementById('uploadForm').submit();
});
</script>
</div>
</body>
</html>
