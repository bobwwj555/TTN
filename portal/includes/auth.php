<?php
/**
 * TTN Authentication Layer
 * Tennessee Technological Community · ttn.radio
 *
 * LOCATION: /home/obdswlpx/dev.ttn.radio/includes/auth.php
 *
 * Provides:
 *   ttn_session_start()    — start session securely, call on every page
 *   ttn_login()            — validate credentials, create session
 *   ttn_logout()           — destroy session
 *   ttn_is_logged_in()     — boolean, is there a valid session
 *   ttn_require_login()    — redirect to login if not authenticated
 *   ttn_require_role()     — redirect if user lacks required role
 *   ttn_current_operator() — returns operator row or null
 *   ttn_has_role()         — boolean role check
 *
 * Roles (least to most privileged):
 *   viewer     — read-only, can see admin dashboard but not change anything
 *   operator   — can post build logs, update site status, manage assets
 *   site_admin — full control of assigned site(s) only
 *   admin      — full access including operator management and node control
 */

require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';

// ── START SESSION ─────────────────────────────────────────────────────────────
/**
 * Call this at the top of every page before any output.
 * Sets secure session parameters — httponly, samesite, secure in production.
 */
function ttn_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    session_name(TTN_SESSION_NAME);

    $cookie_params = [
        'lifetime' => 0,           // expire when browser closes
        'path'     => '/',
        'domain'   => '',
        'secure'   => TTN_ENV === 'production', // HTTPS only in production
        'httponly' => true,        // no JS access to session cookie
        'samesite' => 'Strict',    // CSRF protection
    ];
    session_set_cookie_params($cookie_params);
    session_start();

    // Regenerate session ID periodically to prevent fixation
    if (!isset($_SESSION['_created'])) {
        $_SESSION['_created'] = time();
    } elseif (time() - $_SESSION['_created'] > 300) {
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }

    // Inactivity timeout
    if (isset($_SESSION['_last_active'])) {
        if (time() - $_SESSION['_last_active'] > TTN_SESSION_TIMEOUT) {
            ttn_logout();
            return;
        }
    }
    if (isset($_SESSION['operator_id'])) {
        $_SESSION['_last_active'] = time();
    }
}

// ── LOGIN ─────────────────────────────────────────────────────────────────────
/**
 * Attempt login with callsign + password.
 * Returns ['success' => true] or ['success' => false, 'error' => 'message']
 *
 * Tracks failed attempts per callsign. Locks out after TTN_MAX_LOGIN_ATTEMPTS.
 */
function ttn_login(string $callsign, string $password): array {
    $callsign = strtoupper(trim($callsign));

    // Check lockout
    $lockout_key = 'login_fails_' . $callsign;
    $fails       = $_SESSION[$lockout_key] ?? 0;
    $lockout_at  = $_SESSION[$lockout_key . '_time'] ?? 0;

    if ($fails >= TTN_MAX_LOGIN_ATTEMPTS) {
        $elapsed = time() - $lockout_at;
        if ($elapsed < TTN_LOCKOUT_MINUTES * 60) {
            $remaining = ceil((TTN_LOCKOUT_MINUTES * 60 - $elapsed) / 60);
            return [
                'success' => false,
                'error'   => "Too many failed attempts. Try again in {$remaining} minute(s)."
            ];
        }
        // Lockout expired — reset
        unset($_SESSION[$lockout_key], $_SESSION[$lockout_key . '_time']);
    }

    // Fetch operator
    $operator = db_row(
        "SELECT * FROM operators WHERE callsign = ? AND is_active = 1",
        [$callsign]
    );

    if (!$operator || !password_verify($password, $operator['password_hash'])) {
        $_SESSION[$lockout_key] = ($fails + 1);
        $_SESSION[$lockout_key . '_time'] = time();

        $remaining_attempts = TTN_MAX_LOGIN_ATTEMPTS - ($fails + 1);
        $msg = $remaining_attempts > 0
            ? "Invalid callsign or password. {$remaining_attempts} attempt(s) remaining."
            : "Too many failed attempts. Account locked for " . TTN_LOCKOUT_MINUTES . " minutes.";

        return ['success' => false, 'error' => $msg];
    }

    // Success — build session
    session_regenerate_id(true);
    $_SESSION['operator_id']   = $operator['id'];
    $_SESSION['callsign']      = $operator['callsign'];
    $_SESSION['display_name']  = $operator['display_name'];
    $_SESSION['role']          = $operator['role'];
    $_SESSION['node_id']       = $operator['node_id'];
    $_SESSION['_last_active']  = time();
    $_SESSION['_created']      = time();

    // Clear any lockout data
    unset($_SESSION[$lockout_key], $_SESSION[$lockout_key . '_time']);

    // Update last login timestamp
    db_execute(
        "UPDATE operators SET last_login = NOW() WHERE id = ?",
        [$operator['id']]
    );

    return ['success' => true];
}

// ── LOGOUT ────────────────────────────────────────────────────────────────────
function ttn_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

// ── IS LOGGED IN ──────────────────────────────────────────────────────────────
function ttn_is_logged_in(): bool {
    return !empty($_SESSION['operator_id']);
}

// ── CURRENT OPERATOR ──────────────────────────────────────────────────────────
/**
 * Returns the session operator data as array, or null if not logged in.
 * Uses session data — no DB hit on every page load.
 */
function ttn_current_operator(): ?array {
    if (!ttn_is_logged_in()) return null;
    return [
        'id'           => $_SESSION['operator_id'],
        'callsign'     => $_SESSION['callsign'],
        'display_name' => $_SESSION['display_name'],
        'role'         => $_SESSION['role'],
        'node_id'      => $_SESSION['node_id'],
    ];
}

// ── ROLE CHECK ────────────────────────────────────────────────────────────────
/**
 * Returns true if the current operator has at least the given role.
 * Role hierarchy: viewer < operator < site_admin < admin
 *
 * ttn_has_role('operator') returns true for operator, site_admin AND admin.
 */
function ttn_has_role(string $required_role): bool {
    if (!ttn_is_logged_in()) return false;

    $hierarchy = ['viewer' => 1, 'operator' => 2, 'site_admin' => 3, 'admin' => 4];
    $user_level     = $hierarchy[$_SESSION['role']] ?? 0;
    $required_level = $hierarchy[$required_role]    ?? 99;

    return $user_level >= $required_level;
}

// ── REQUIRE LOGIN (redirect if not) ───────────────────────────────────────────
/**
 * Call at the top of any admin page.
 * Saves the requested URL so the user is redirected back after login.
 */
function ttn_require_login(): void {
    ttn_session_start();
    if (!ttn_is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        $site = function_exists('s') ? s('site_url', 'https://dev.ttn.radio') : 'https://dev.ttn.radio';
        header('Location: ' . $site . '/admin/login.php');
        exit;
    }
}

// ── REQUIRE ROLE (redirect if insufficient) ───────────────────────────────────
/**
 * Call at top of admin pages that need a specific role.
 *
 * ttn_require_role('admin');    // only admins
 * ttn_require_role('operator'); // operators and admins
 */
function ttn_require_role(string $role): void {
    ttn_require_login();
    if (!ttn_has_role($role)) {
        http_response_code(403);
        $site = function_exists('s') ? s('site_url', 'https://dev.ttn.radio') : 'https://dev.ttn.radio';
        header('Location: ' . $site . '/admin/dashboard.php?error=access_denied');
        exit;
    }
}

// ── CSRF TOKEN ────────────────────────────────────────────────────────────────
/**
 * Generate a CSRF token for forms. Store in session, verify on submit.
 *
 * In your form:
 *   <input type="hidden" name="csrf_token" value="<?= ttn_csrf_token() ?>">
 *
 * On form submission:
 *   ttn_verify_csrf($_POST['csrf_token']);
 */
function ttn_csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . ttn_csrf_token() . '">';
}

function ttn_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function ttn_verify_csrf(?string $token): void {
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid request. Please go back and try again.');
    }
    unset($_SESSION['csrf_token']);
}

// Alias — returns bool so admin pages can handle errors gracefully
function ttn_csrf_verify(?string $token): bool {
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        return false;
    }
    unset($_SESSION['csrf_token']);
    return true;
}
