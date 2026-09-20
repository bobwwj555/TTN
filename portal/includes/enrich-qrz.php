<?php
/**
 * TTN QRZ XML lookup
 * LOCATION (proposed): portal/includes/enrich-qrz.php
 *
 * Verified live tonight (WebFetch, this session) against QRZ's own spec
 * at qrz.com/page/current_spec.html: this is the dynamic session-key
 * model, not the static Logbook API key product. Username+password log
 * in once, get a session key back, cache and reuse it until QRZ signals
 * it's invalid (spec: "clients should expect to perform only one login
 * operation per session," no guaranteed key lifetime, re-auth on demand).
 *
 * CREDENTIAL HANDLING -- the whole point of scaffolding this now: the
 * real QRZ username/password never passes through this chat or any
 * Claude session, in either direction. This file only ever READS them
 * from a config file that doesn't exist yet in this delivery. Until
 * Bobby drops real values into that file over SSH, every call below
 * just returns null -- the page degrades to "no QRZ data" instead of
 * erroring, same pattern as the other try/catch guards tonight.
 *
 * Expected config file (create on CT713, NOT in the git repo):
 *   /etc/ttn/qrz_credentials.ini
 *   [qrz]
 *   username = "W4BWW"
 *   password = "..."
 *
 * ASSUMPTION: exact ini path/section -- I made this up to match the
 * existing /etc/ttn/credentials.ini pattern (same directory, same ini
 * format) since I don't have a real convention to confirm it against.
 * Change the constant below if you want it elsewhere.
 */

define('TTN_QRZ_CRED_FILE', '/etc/ttn/qrz_credentials.ini');
define('TTN_QRZ_SESSION_CACHE', '/tmp/ttn_qrz_session.json');

function ttn_qrz_lookup(string $callsign): ?array {
    $session_key = ttn_qrz_get_session_key();
    if (!$session_key) {
        return null; // no credentials configured yet, or login failed -- silent no-op
    }

    $data = ttn_qrz_query($session_key, $callsign);

    // Spec: a session key can go invalid (e.g. the server's IP changed).
    // One retry with a forced fresh login before giving up.
    if ($data === 'INVALID_SESSION') {
        $session_key = ttn_qrz_get_session_key(true);
        if (!$session_key) {
            return null;
        }
        $data = ttn_qrz_query($session_key, $callsign);
    }

    return is_array($data) ? $data : null;
}

function ttn_qrz_get_session_key(bool $force_relogin = false): ?string {
    if (!$force_relogin && file_exists(TTN_QRZ_SESSION_CACHE)) {
        $cached = json_decode(file_get_contents(TTN_QRZ_SESSION_CACHE), true);
        // No guaranteed lifetime per spec -- keep a conservative local
        // TTL (23h) just so we don't hold a genuinely stale key forever
        // between the rare invalid-session events that trigger a refetch.
        if ($cached && (time() - ($cached['obtained_at'] ?? 0)) < 82800) {
            return $cached['key'];
        }
    }

    if (!file_exists(TTN_QRZ_CRED_FILE)) {
        return null; // not configured yet -- expected until Bobby fills this in
    }
    $conf = parse_ini_file(TTN_QRZ_CRED_FILE, true);
    $user = $conf['qrz']['username'] ?? null;
    $pass = $conf['qrz']['password'] ?? null;
    if (!$user || !$pass) {
        return null;
    }

    $url = 'https://xmldata.qrz.com/xml/current/?' . http_build_query(['username' => $user, 'password' => $pass]);
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'TTN-Portal/1.0']]);
    $xml = @file_get_contents($url, false, $ctx);
    if ($xml === false) {
        return null;
    }

    $parsed = @simplexml_load_string($xml);
    $key = (string)($parsed->Session->Key ?? '');
    if (!$key) {
        error_log('[qrz] login failed: ' . (string)($parsed->Session->Error ?? 'unknown error'));
        return null;
    }

    @file_put_contents(TTN_QRZ_SESSION_CACHE, json_encode(['key' => $key, 'obtained_at' => time()]));
    return $key;
}

function ttn_qrz_query(string $session_key, string $callsign) {
    $url = 'https://xmldata.qrz.com/xml/current/?' . http_build_query(['s' => $session_key, 'callsign' => $callsign]);
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'TTN-Portal/1.0']]);
    $xml = @file_get_contents($url, false, $ctx);
    if ($xml === false) {
        return null;
    }

    $parsed = @simplexml_load_string($xml);
    $error = (string)($parsed->Session->Error ?? '');
    if ($error && stripos($error, 'session') !== false && stripos($error, 'invalid') !== false) {
        return 'INVALID_SESSION';
    }
    if (!isset($parsed->Callsign)) {
        return null; // not found, or some other lookup-level error
    }

    return [
        'callsign' => (string)($parsed->Callsign->call ?? $callsign),
        'name'     => trim(((string)($parsed->Callsign->fname ?? '')) . ' ' . ((string)($parsed->Callsign->name ?? ''))),
        'grid'     => (string)($parsed->Callsign->grid ?? null) ?: null,
        'location' => trim(((string)($parsed->Callsign->addr2 ?? '')) . ', ' . ((string)($parsed->Callsign->state ?? ''))),
    ];
}
