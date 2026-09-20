<?php
/**
 * TTN DVRef P25 reflector-directory lookup
 * LOCATION (proposed): portal/includes/enrich-dvref.php
 *
 * CONFIRMED, not guessed (2026-09-20, second follow-up): Bobby read the
 * real docs directly at https://dvref.com/docs/api-guide/ (blocked for
 * this session's fetch tool by robots.txt, not for a real browser) and
 * relayed the actual API contract. Rewritten against that, replacing
 * the earlier "unverified, likely wrong" guess entirely:
 *
 * - Two access tiers. Anonymous (headers: User-Agent, X-DVRef-Callsign,
 *   X-DVRef-Contact) covers ONLY collection/list GET endpoints, e.g.
 *   GET /api/v2/mrefd/reflectors/. Authenticated (Authorization: Token
 *   <token>) covers everything else -- including per-reflector DETAIL
 *   endpoints (.../<slug>/), which have NO anonymous path at all, token
 *   or not. This file calls .../p25/reflectors/?search=<term> -- a
 *   collection/list endpoint (no slug in the path), so it's correctly
 *   in the anonymous tier. The X-DVRef-Callsign/X-DVRef-Contact headers
 *   this file already used were the right idea, not the wrong guess an
 *   earlier pass suspected -- confirmed now, not just carried over
 *   optimistically.
 * - User-Agent must look like software/version (e.g. "TTN/1.0") -- a
 *   generic or empty UA is rejected outright. Set to exactly that below
 *   rather than the "TTN-Portal/1.0" used elsewhere in this codebase,
 *   since this is the one endpoint with a stated strict check on it and
 *   there's no reason to risk an unconfirmed variant against it.
 * - Response envelope: {"status":"success","generated_at":...,
 *   "_dvref_metadata":{...},"data":{...}} -- the actual content is
 *   under "data", not top-level. The earlier version read a top-level
 *   "results" key (or the whole body as a bare array) -- both wrong,
 *   now fixed to unwrap "data" first.
 * - RATE LIMIT, the finding that actually changes this file's design:
 *   anonymous access is throttled to 1 request per resource per source
 *   IP every 6h, SHARED ACROSS QUERY PARAMS on that endpoint -- i.e.
 *   the limit is per-endpoint, not per-designator. The old per-
 *   designator cache (one HTTP request per new designator looked up)
 *   would 429 as soon as a second distinct designator was queried
 *   inside the same 6h window under real lastheard traffic. Fixed by
 *   fetching the FULL reflector list once per TTL (no search filter)
 *   and answering every lookup from that local cache -- at most one
 *   HTTP request per TTL window, however many designators get looked
 *   up. Same shape as enrich-radioid.php's v2/v3 bulk-index redesign,
 *   for the identical underlying reason.
 * - 429 responses carry retry_after_seconds in the body and a
 *   Retry-After header -- logged when present, not acted on
 *   automatically (this file was already failing closed without
 *   retrying, so there was no blind-retry bug to fix, just visibility
 *   to add).
 *
 * STILL NOT independently confirmed by this session directly (Bobby's
 * relay, not a fetch this session made itself): the exact key inside
 * "data" that holds the reflector array for a LIST response -- could be
 * "data" itself as a bare array, or "data.results"/"data.reflectors" (a
 * DRF-style paginated wrapper, given the validation endpoint's
 * success/responsive-style field naming elsewhere suggests a Django-ish
 * API). Handled defensively below rather than hardcoded to one guess.
 * Also unconfirmed: whether the list endpoint paginates at all -- if
 * "data" carries a "next" URL and this file doesn't follow it, some
 * reflectors could be silently missing from the cache. Logged as a
 * warning if a "next" field is seen, not silently ignored. Bobby's
 * message pointed at the Swagger spec linked from the docs page for the
 * exact per-field shape if this needs tightening further.
 *
 * SCOPE (unchanged): this is a REFLECTOR directory -- which P25
 * network/reflector a designator/talkgroup routes to -- not a per-
 * radio/per-user lookup, and does NOT resolve individual P25 radio IDs
 * the way enrich-radioid.php resolves DMR IDs.
 *
 * ATTRIBUTION: response data carries a required CC-BY-4.0 attribution
 * string per the earlier report ("Reflector data provided by DVRef —
 * https://dvref.com/"). ttn_dvref_attribution_html() below exists so
 * that's a one-line include on any public page using this data.
 *
 * Still correctly NOT wired into lastheard -- that's a separate
 * integration decision (per-network banner, not per-event row
 * enrichment, per TTN_TodoList_2026-09-20_0127.md), not blocked on
 * this file's correctness anymore.
 */

define('TTN_DVREF_CALLSIGN', 'W4BWW');
define('TTN_DVREF_CONTACT', 'https://ttn.radio');
define('TTN_DVREF_USER_AGENT', 'TTN/1.0'); // confirmed strict format requirement -- see file header

define('TTN_DVREF_LIST_URL', 'https://dvref.com/api/v2/p25/reflectors/'); // no search param -- full list, fetched at most once per TTL
define('TTN_DVREF_LIST_CACHE_FILE', '/tmp/ttn_dvref_reflector_list.json');
define('TTN_DVREF_LIST_TTL', 86400); // 24h -- comfortably above the confirmed 6h anonymous throttle floor

function ttn_dvref_attribution_html(): string {
    return 'Reflector data provided by <a href="https://dvref.com/">DVRef</a>';
}

/**
 * Public lookup: answers from the local bulk cache only, never makes a
 * per-call HTTP request. Matches on either "designator" or "slug",
 * same as the earlier per-designator version did, since which field
 * callers pass isn't confirmed either.
 */
function ttn_enrich_dvref_p25_reflector(string $designator): ?array {
    if (!ttn_dvref_ensure_reflector_list()) {
        return null; // no usable cached list, and refresh failed or was throttled -- fail closed, not a crash
    }

    $cache = ttn_dvref_read_list_cache();
    foreach ($cache['reflectors'] ?? [] as $r) {
        if ((string)($r['designator'] ?? '') === $designator || (string)($r['slug'] ?? '') === $designator) {
            return $r;
        }
    }
    return null; // list is fresh but this designator isn't in it -- a real "not found," not a fetch failure
}

/**
 * Ensures a fresh-enough local copy of the FULL reflector list exists.
 * The one function in this file that ever makes an HTTP request --
 * deliberately, since that's what keeps this to at most one request per
 * TTL window regardless of how many distinct designators get looked up
 * (see file header on why per-designator caching was wrong here).
 */
function ttn_dvref_ensure_reflector_list(bool $force = false): bool {
    $cache = ttn_dvref_read_list_cache();
    $fresh = !empty($cache['fetched_at']) && (time() - $cache['fetched_at']) < TTN_DVREF_LIST_TTL;
    if ($fresh && !$force) {
        return true;
    }

    $fetched = ttn_dvref_fetch_reflector_list();
    if ($fetched === null) {
        // Refresh failed (network error, non-2xx, bad shape) -- keep
        // serving whatever was cached before rather than wiping it out,
        // same fail-safe pattern as radioid.net's dump refresh.
        return !empty($cache['reflectors']);
    }

    @file_put_contents(TTN_DVREF_LIST_CACHE_FILE, json_encode([
        'reflectors' => $fetched,
        'fetched_at' => time(),
    ]));
    return true;
}

function ttn_dvref_read_list_cache(): array {
    if (!file_exists(TTN_DVREF_LIST_CACHE_FILE)) {
        return [];
    }
    $decoded = json_decode(file_get_contents(TTN_DVREF_LIST_CACHE_FILE), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * The one HTTP call. Returns the reflector array on success, null on
 * any failure -- never throws, never retries (a 429 here means waiting
 * out the confirmed 6h window, not trying again sooner).
 */
function ttn_dvref_fetch_reflector_list(): ?array {
    $ctx = stream_context_create(['http' => [
        'timeout' => 8,
        'header'  => "X-DVRef-Callsign: " . TTN_DVREF_CALLSIGN . "\r\n"
                   . "X-DVRef-Contact: " . TTN_DVREF_CONTACT . "\r\n",
        'user_agent' => TTN_DVREF_USER_AGENT,
        'ignore_errors' => true, // so a 4xx/429 body is still readable below instead of file_get_contents just returning false
    ]]);
    $raw = @file_get_contents(TTN_DVREF_LIST_URL, false, $ctx);
    if ($raw === false) {
        error_log('[dvref] list fetch: connection/timeout failure');
        return null;
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $hdr) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hdr, $m)) {
            $status = (int)$m[1];
        }
    }

    if ($status === 429) {
        $body = json_decode($raw, true);
        $retry_after = $body['retry_after_seconds'] ?? null;
        error_log('[dvref] list fetch throttled (429)' . ($retry_after !== null ? " -- retry_after_seconds={$retry_after}" : '') . ' -- confirmed limit is 1 request per resource per 6h; not retrying');
        return null;
    }
    if ($status && ($status < 200 || $status >= 300)) {
        error_log("[dvref] list fetch: non-2xx response ({$status})");
        return null;
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        error_log('[dvref] list fetch: response was not valid JSON');
        return null;
    }

    // Confirmed: real content is under "data", not top-level.
    $data = $parsed['data'] ?? null;
    if ($data === null) {
        error_log('[dvref] list fetch: response had no "data" key -- envelope shape may have changed');
        return null;
    }

    if (!empty($data['next'])) {
        // Unconfirmed whether/how this endpoint paginates -- not
        // followed here, so flag it rather than silently returning a
        // partial list.
        error_log('[dvref] list fetch: response data carries a "next" field -- this endpoint may paginate and only the first page was fetched. Check the Swagger spec linked from https://dvref.com/docs/api-guide/ before assuming full coverage.');
    }

    // Exact key holding the array isn't confirmed -- try the plausible
    // shapes rather than committing to one guess. Portable list check
    // (not array_is_list(), PHP 8.1+ only -- CT713's exact PHP version
    // isn't confirmed from this session).
    $is_list = $data === array_values($data) === true || (array_keys($data) === range(0, count($data) - 1));
    if (!empty($data) && $is_list) {
        $list = $data;
    } elseif (isset($data['results']) && is_array($data['results'])) {
        $list = $data['results'];
    } elseif (isset($data['reflectors']) && is_array($data['reflectors'])) {
        $list = $data['reflectors'];
    } else {
        error_log('[dvref] list fetch: could not find a reflector array inside "data" (tried bare list, data.results, data.reflectors)');
        return null;
    }

    if (!$list) {
        error_log('[dvref] list fetch: succeeded but returned zero reflectors -- discarding rather than caching an empty list over a possibly-good previous one');
        return null;
    }

    $normalized = [];
    foreach ($list as $r) {
        if (!is_array($r)) {
            continue;
        }
        $normalized[] = [
            'designator'   => $r['designator'] ?? null,
            'name'         => $r['name'] ?? null,
            'slug'         => $r['slug'] ?? null,
            'url'          => $r['url'] ?? null,
            'dns'          => $r['dns'] ?? null,
            'ipv4'         => $r['ipv4'] ?? null,
            'ipv6'         => $r['ipv6'] ?? null,
            'port'         => $r['port'] ?? null,
            'sponsor'      => $r['sponsor'] ?? null,
            'country'      => $r['country'] ?? null,
            'network_type' => $r['network_type'] ?? null,
        ];
    }

    return $normalized;
}
