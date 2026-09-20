<?php
/**
 * TTN Admin -- Enrichment API Credentials
 * LOCATION (proposed): portal/admin/api-credentials.php
 *
 * Answers Bobby's ask (2026-09-20): a web-UI page to enter QRZ
 * credentials instead of hand-editing credentials.ini over SSH, and an
 * audit of the other four 0005 enrichment modules for the same need.
 *
 * AUDIT RESULT -- which of the 5 modules in 0005 actually need
 * credentials, checked against each module's own code, not re-guessed:
 *   - QRZ            -- YES. Real secret (username + password). This page.
 *   - AllStarLink     -- NO. stats.allstarlink.org scrape, no auth at all.
 *   - EchoLink        -- NO. echolink.org lookup, no auth at all.
 *   - radioid.net     -- NO. Public bulk CSV + public JSON API, no auth.
 *   - DVRef           -- NOT a secret today. Uses TTN_DVREF_CALLSIGN /
 *     TTN_DVREF_CONTACT constants (an unverified guess at identifying
 *     headers, not a password/token) hardcoded in enrich-dvref.php.
 *     Real evidence turned up this session (forum.dvref.com) suggests
 *     DVRef's actual API uses bearer-style `Authorization: Token <key>`
 *     auth for at least some operations -- IF that turns out to apply to
 *     the anonymous search endpoint too and TTN ever gets a real DVRef
 *     token, THIS is the natural place to add it (the $services array
 *     below is structured so that's a small addition, not a rebuild).
 *     Not built now: DVRef isn't wired into lastheard yet, and adding a
 *     token field for an unconfirmed auth requirement would be building
 *     ahead of an actual need.
 *
 * So: one real form (QRZ) today, three modules confirmed needing
 * nothing, one noted as a likely-future extension point.
 *
 * SECOND BUG FOUND AND FIXED (2026-09-20, same live debugging session,
 * after the first fix above got the page loading but a real admin
 * session still got bounced): the ASSUMPTION this section used to flag
 * -- `$_SESSION['role'] >= 4`, guessed with no auth.php to check against
 * -- was wrong on both axes at once, confirmed by reading the real
 * includes/auth.php directly:
 *   - Wrong comparison: `role` is a STRING (viewer/operator/site_admin/
 *     admin), not a numeric level. `$_SESSION['role'] >= 4` was comparing
 *     a role string against an int, which is not what rejected valid
 *     admins by accident so much as never reliably matched real role
 *     values in the first place.
 *   - Wrong approach entirely: auth.php already provides
 *     `ttn_require_role(string $role)` as the real, already-used gate --
 *     the exact same helper dashboard.php calls (`ttn_require_role
 *     ('viewer')`, confirmed via a live grep of that file). It handles
 *     session start (secure cookie params, inactivity timeout via
 *     ttn_session_start()), redirect-to-login preserving the requested
 *     URL if not authenticated, and a proper 403 + redirect to
 *     /admin/dashboard.php?error=access_denied on insufficient role --
 *     all of which the old inline check reimplemented, and got wrong.
 * Fixed below to call the real helper directly: `ttn_require_role
 * ('admin')`. TTN_ADMIN_SESSION_CHECK and the inline $_SESSION check are
 * gone entirely, not patched.
 *
 * FIRST BUG FOUND AND FIXED (2026-09-20, earlier same session): the
 * unauthenticated redirect used to guess `/login.php` as the login
 * page's path. Wrong -- confirmed two ways: (1) direct browser fetch,
 * GET /login.php -> 404, GET /admin/login.php -> 200; (2) CT713's own
 * nginx error log already showed real traffic -- "POST /admin/login.php"
 * with referrer "https://ttechnological.net/admin/login.php". Now moot
 * as a separate fix -- ttn_require_role() above builds the login URL
 * itself (from the real site_url config, not a hardcoded path), so this
 * page no longer constructs that redirect at all.
 *
 * ttn_csrf_field() and ttn_csrf_verify() ARE confirmed real function
 * names, read directly from includes/auth.php -- used below as-is.
 *
 * This page never echoes a stored password back into the form or page
 * source -- only a boolean "configured" / "not configured" status per
 * service, via ttn_credentials_section_configured(). The write path
 * itself is in includes/credentials-ini.php -- see that file's header
 * for the file-permission requirement this page's save action depends
 * on (credentials.ini must be group-writable by this process's user;
 * that's a one-time server-side chmod, not something this code does).
 */

require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
require_once __DIR__ . '/../includes/credentials-ini.php';

// Real gate, confirmed against includes/auth.php -- same helper
// dashboard.php already uses (ttn_require_role('viewer')). Handles
// session start, redirect-to-login if unauthenticated, and a 403 +
// redirect to dashboard if the session's role isn't high enough. See
// file header for what this replaced and why.
ttn_require_role('admin');

// Services this page manages. Adding a future service (e.g. a DVRef
// token, if one is ever obtained) means adding one entry here plus one
// $fields loop iteration -- not new plumbing.
$services = [
    'qrz' => [
        'label'    => 'QRZ (callsign lookup)',
        'fields'   => ['username' => 'Username', 'password' => 'Password'],
        'used_by'  => 'includes/enrich-qrz.php',
    ],
];

$errors = [];
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ASSUMPTION: real CSRF verification helper name -- ttn_csrf_field()
    // itself is confirmed (see file header); the matching *verify*
    // function on submit isn't named anywhere I could read, so this is
    // written against the obvious counterpart name. Swap if auth.php's
    // real one differs -- deliberately NOT skipped/stubbed out, since a
    // missing CSRF check on a credentials-writing form is worse than a
    // broken-until-fixed one.
    if (!function_exists('ttn_csrf_verify') || !ttn_csrf_verify($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security check failed -- form may have expired. Reload and try again.';
    } else {
        $service = $_POST['service'] ?? '';
        if (!isset($services[$service])) {
            $errors[] = 'Unknown service.';
        } else {
            $kv = [];
            foreach ($services[$service]['fields'] as $field => $field_label) {
                $val = trim($_POST[$field] ?? '');
                // Blank field = "leave this value unchanged" (so re-saving
                // the username doesn't require re-typing a password
                // that's already stored, or vice versa), not "clear it."
                if ($val === '') {
                    continue;
                }
                $kv[$field] = $val;
            }
            if (empty($kv)) {
                $errors[] = 'Nothing to save -- all fields were left blank.';
            } else {
                // Merge onto whatever's already stored, don't just
                // replace, so leaving the password field blank while
                // updating the username doesn't wipe the password.
                $existing = ttn_credentials_read_section($service);
                $merged = array_merge($existing, $kv);
                if (ttn_credentials_write_section($service, $merged)) {
                    $saved = true;
                } else {
                    $errors[] = 'Save failed -- credentials.ini may not be writable by the web server user yet. Check ownership/permissions server-side (see includes/credentials-ini.php header) and try again.';
                }
            }
        }
    }
}

$page_title = 'Enrichment API Credentials';
$adm_page   = 'api-credentials'; // matches admin_nav.php's new System nav entry -- sets the active-link highlight
require_once TTN_INCLUDES . '/header.php';
?>
<style>
.cred-wrap{padding:3rem 5vw;background:var(--bg);color:var(--t1, #ddd);max-width:640px}
.cred-card{background:var(--panel);border:1px solid var(--border2);padding:1.5rem;margin:1.5rem 0}
.cred-card h3{margin:0 0 0.3rem;font-size:1rem}
.cred-status{font-size:0.75rem;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:1rem}
.cred-status.on{color:var(--green)}
.cred-status.off{color:var(--t3)}
.cred-field{margin-bottom:0.8rem}
.cred-field label{display:block;font-size:0.8rem;color:var(--t3);margin-bottom:0.3rem}
.cred-field input{width:100%;padding:0.5rem;background:var(--bg);border:1px solid var(--border2);color:var(--t1, #ddd)}
.cred-hint{font-size:0.75rem;color:var(--t3);margin-top:0.3rem}
.cred-msg{padding:0.8rem 1rem;margin-bottom:1rem;font-size:0.85rem}
.cred-msg.err{border:1px solid #ff1744;color:#ff1744}
.cred-msg.ok{border:1px solid var(--green);color:var(--green)}
</style>
<div class="cred-wrap">
  <h1>Enrichment API Credentials</h1>
  <p style="color:var(--t3)">Stored in <code>/etc/ttn/credentials.ini</code> on CT713 -- values are write-only here, never displayed back.</p>

  <?php foreach ($errors as $e): ?>
    <div class="cred-msg err"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>
  <?php if ($saved): ?>
    <div class="cred-msg ok">Saved.</div>
  <?php endif; ?>

  <?php foreach ($services as $key => $svc):
      $configured = ttn_credentials_section_configured($key, array_keys($svc['fields']));
  ?>
  <div class="cred-card">
    <h3><?= htmlspecialchars($svc['label']) ?></h3>
    <div class="cred-status <?= $configured ? 'on' : 'off' ?>"><?= $configured ? 'Configured' : 'Not configured' ?> -- used by <?= htmlspecialchars($svc['used_by']) ?></div>
    <form method="post">
      <?= function_exists('ttn_csrf_field') ? ttn_csrf_field() : '' ?>
      <input type="hidden" name="service" value="<?= htmlspecialchars($key) ?>">
      <?php foreach ($svc['fields'] as $field => $field_label): ?>
      <div class="cred-field">
        <label for="<?= htmlspecialchars($key . '_' . $field) ?>"><?= htmlspecialchars($field_label) ?></label>
        <input type="<?= $field === 'password' ? 'password' : 'text' ?>"
               id="<?= htmlspecialchars($key . '_' . $field) ?>"
               name="<?= htmlspecialchars($field) ?>"
               autocomplete="off"
               placeholder="<?= $configured ? 'Leave blank to keep current value' : '' ?>">
      </div>
      <?php endforeach; ?>
      <div class="cred-hint">Leave a field blank to keep its current stored value unchanged.</div>
      <button type="submit">Save</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>
<?php require_once TTN_INCLUDES . '/footer.php'; ?>
