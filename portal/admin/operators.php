<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_require_role('admin');

$adm_title = 'Operators';
$adm_page  = 'operators';
$my_id     = $_SESSION['operator_id'] ?? 0;
$msg = $err = '';
$action  = $_GET['action'] ?? 'list';
$gen_link = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ttn_csrf_verify($_POST['csrf_token'] ?? '');
    $pa = $_POST['post_action'] ?? '';

    // ── CREATE OPERATOR ──────────────────────────────────────
    if ($pa === 'create') {
        $callsign = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($_POST['callsign'] ?? ''))));
        $email    = trim($_POST['email'] ?? '') ?: null;
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'operator';

        if (!$callsign) { $err = 'Callsign is required.'; }
        elseif (strlen($password) < 8) { $err = 'Password must be at least 8 characters.'; }
        elseif (db_row("SELECT id FROM operators WHERE callsign=?", [$callsign])) { $err = "Callsign $callsign already exists."; }
        elseif ($email && db_row("SELECT id FROM operators WHERE email=?", [$email])) { $err = "Email $email is already in use."; }
        else {
            db_insert('operators', [
                'callsign'      => $callsign,
                'display_name'  => trim($_POST['display_name'] ?? '') ?: $callsign,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'role'          => in_array($role, ['admin','operator','viewer']) ? $role : 'operator',
                'is_active'     => 1,
                'is_public'     => isset($_POST['is_public']) ? 1 : 0,
                'sort_order'    => (int)($_POST['sort_order'] ?? 99),
            ]);
            $msg = "Operator $callsign created.";
            $action = 'list';
        }
    }

    // ── RESET PASSWORD ───────────────────────────────────────
    if ($pa === 'reset_password') {
        $oid      = (int)$_POST['op_id'];
        $password = $_POST['new_password'] ?? '';
        if (strlen($password) < 8) { $err = 'Password must be at least 8 characters.'; }
        else {
            db_execute("UPDATE operators SET password_hash=? WHERE id=?",
                [password_hash($password, PASSWORD_BCRYPT), $oid]);
            $msg = 'Password updated.';
        }
        $action = 'list';
    }

    // ── GENERATE RESET LINK ──────────────────────────────────
    if ($pa === 'gen_reset_link') {
        $oid = (int)$_POST['op_id'];
        $op  = db_row("SELECT id, callsign FROM operators WHERE id=?", [$oid]);
        if ($op) {
            db_execute("DELETE FROM password_resets WHERE operator_id=? AND used=0", [$oid]);
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
            db_insert('password_resets', [
                'operator_id' => $oid,
                'token'       => $token,
                'expires_at'  => $expires,
                'used'        => 0,
            ]);
            $site_url = s('site_url', 'https://dev.ttn.radio');
            $gen_link = $site_url . '/admin/reset.php?token=' . $token;
            $msg = 'Reset link for ' . $op['callsign'] . ' (valid 24h):';
        }
        $action = 'list';
    }

    // ── TOGGLE ACTIVE ────────────────────────────────────────
    if ($pa === 'toggle_active') {
        $oid = (int)$_POST['op_id'];
        if ($oid !== $my_id) {
            db_execute("UPDATE operators SET is_active = NOT is_active WHERE id=?", [$oid]);
        }
        $action = 'list';
    }

    // ── DELETE ───────────────────────────────────────────────
    if ($pa === 'delete') {
        $oid = (int)$_POST['op_id'];
        if ($oid === $my_id) { $err = 'Cannot delete yourself.'; }
        else {
            db_execute("DELETE FROM site_crew WHERE operator_id=?", [$oid]);
            db_execute("DELETE FROM operator_radio_ids WHERE operator_id=?", [$oid]);
            db_execute("DELETE FROM operators WHERE id=?", [$oid]);
            $msg = 'Operator deleted.';
        }
        $action = 'list';
    }
}

$operators = db_rows("SELECT o.*, COUNT(sc.id) AS site_count FROM operators o LEFT JOIN site_crew sc ON sc.operator_id=o.id GROUP BY o.id ORDER BY o.sort_order, o.callsign");

// Pending reset tokens
$pending_resets = db_rows("
    SELECT pr.*, o.callsign, o.display_name
    FROM password_resets pr
    JOIN operators o ON o.id = pr.operator_id
    WHERE pr.used = 0 AND pr.expires_at > NOW()
    ORDER BY pr.expires_at DESC
");

require_once TTN_INCLUDES . '/admin_head.php';
require_once TTN_INCLUDES . '/admin_nav.php';
?>
<div class="adm-main">
<div class="adm-topbar">
    <div class="adm-topbar-title">Operators</div>
    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('newOpForm').style.display=document.getElementById('newOpForm').style.display==='none'?'block':'none'">+ Add Operator</button>
</div>
<div class="adm-body">

<?php if($msg): ?><div class="msg-ok"><?= htmlspecialchars($msg) ?><?php if($gen_link): ?><br><a href="<?= htmlspecialchars($gen_link) ?>" style="color:var(--green);word-break:break-all"><?= htmlspecialchars($gen_link) ?></a><?php endif; ?></div><?php endif; ?>
<?php if($err): ?><div class="msg-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- NEW OPERATOR FORM (hidden) -->
<div id="newOpForm" style="display:none" class="panel" style="margin-bottom:1.5rem">
    <div class="panel-hd">New Operator</div>
    <div class="panel-body">
    <form method="post">
        <?= ttn_csrf_field() ?>
        <input type="hidden" name="post_action" value="create">
        <div class="field-row3">
            <div class="field"><label>Callsign *</label><input type="text" name="callsign" required style="text-transform:uppercase" placeholder="W4BWW"></div>
            <div class="field"><label>Display Name</label><input type="text" name="display_name" placeholder="Bobby Whitaker"></div>
            <div class="field"><label>Email</label><input type="email" name="email" placeholder="op@example.com"></div>
        </div>
        <div class="field-row3">
            <div class="field"><label>Password * (min 8)</label><input type="password" name="password" required autocomplete="new-password"></div>
            <div class="field"><label>Role</label>
                <select name="role">
                    <option value="operator">Operator</option>
                    <option value="viewer">Viewer</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="field"><label>Sort Order</label><input type="number" name="sort_order" value="99" min="0" max="99"></div>
        </div>
        <div class="check-row">
            <input type="checkbox" name="is_public" id="new_pub">
            <label for="new_pub">Visible on public team page</label>
        </div>
        <div style="display:flex;gap:0.7rem;margin-top:0.8rem">
            <button type="submit" class="btn btn-primary">Create Operator</button>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('newOpForm').style.display='none'">Cancel</button>
        </div>
    </form>
    </div>
</div>

<!-- OPERATORS TABLE -->
<div class="panel" style="margin-bottom:1.5rem">
    <div class="panel-hd">All Operators (<?= count($operators) ?>)</div>
    <table class="adm-tbl">
        <thead><tr><th>Call</th><th>Name</th><th>Role</th><th>Sites</th><th>Public</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($operators as $op): ?>
        <tr id="op-row-<?= $op['id'] ?>">
            <td class="mono"><?= htmlspecialchars($op['callsign']) ?></td>
            <td><?= htmlspecialchars($op['display_name'] ?? '—') ?></td>
            <td><span style="font-family:var(--mono);font-size:0.58rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--amber)"><?= $op['role'] ?></span></td>
            <td class="mono muted"><?= $op['site_count'] ?></td>
            <td><?= $op['is_public']?'<span style="color:var(--green)">✓</span>':'<span style="color:var(--t3)">—</span>' ?></td>
            <td>
                <form method="post" style="display:inline">
                    <?= ttn_csrf_field() ?>
                    <input type="hidden" name="post_action" value="toggle_active">
                    <input type="hidden" name="op_id" value="<?= $op['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="background:none;border:none;cursor:pointer;color:<?= $op['is_active']?'var(--green)':'var(--red)' ?>">
                        <?= $op['is_active']?'✓':'✗' ?>
                    </button>
                </form>
            </td>
            <td>
                <div class="actions">
                    <a href="<?= s('site_url') ?>/admin/team.php?action=edit&id=<?= $op['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleReset(<?= $op['id'] ?>)">🔗 Reset</button>
                    <?php if ($op['id'] != $my_id): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete <?= htmlspecialchars($op['callsign']) ?>?')">
                        <?= ttn_csrf_field() ?>
                        <input type="hidden" name="post_action" value="delete">
                        <input type="hidden" name="op_id" value="<?= $op['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Del</button>
                    </form>
                    <?php endif; ?>
                </div>
                <!-- Reset panel -->
                <div id="reset-<?= $op['id'] ?>" style="display:none;margin-top:0.5rem;background:var(--panel2);padding:0.7rem;border:1px solid var(--border2)">
                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
                        <!-- Manual reset -->
                        <form method="post" style="display:flex;gap:0.4rem;align-items:flex-end;flex-wrap:wrap">
                            <?= ttn_csrf_field() ?>
                            <input type="hidden" name="post_action" value="reset_password">
                            <input type="hidden" name="op_id" value="<?= $op['id'] ?>">
                            <input type="password" name="new_password" placeholder="New password" style="font-size:0.7rem;padding:0.35rem 0.5rem;width:160px;background:var(--bg2);border:1px solid var(--border2);color:var(--t1);font-family:var(--mono)" minlength="8">
                            <button type="submit" class="btn btn-primary btn-sm">Set</button>
                        </form>
                        <!-- One-time link -->
                        <form method="post" style="display:inline">
                            <?= ttn_csrf_field() ?>
                            <input type="hidden" name="post_action" value="gen_reset_link">
                            <input type="hidden" name="op_id" value="<?= $op['id'] ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">Gen Link</button>
                        </form>
                    </div>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- PENDING RESET TOKENS -->
<?php if (!empty($pending_resets)): ?>
<div class="panel">
    <div class="panel-hd" style="color:var(--amber)">⚠ Active Reset Tokens (<?= count($pending_resets) ?>)</div>
    <table class="adm-tbl">
        <thead><tr><th>Operator</th><th>Expires</th><th>Link</th></tr></thead>
        <tbody>
        <?php foreach ($pending_resets as $pr): ?>
        <tr>
            <td class="mono"><?= htmlspecialchars($pr['callsign']) ?></td>
            <td class="mono muted"><?= htmlspecialchars($pr['expires_at']) ?></td>
            <td style="font-size:0.65rem;word-break:break-all">
                <a href="<?= s('site_url') ?>/admin/reset.php?token=<?= htmlspecialchars($pr['token']) ?>" style="color:var(--green)">
                    <?= s('site_url') ?>/admin/reset.php?token=<?= htmlspecialchars(substr($pr['token'],0,16)) ?>...
                </a>
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
function toggleReset(id) {
    const el = document.getElementById('reset-' + id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>
