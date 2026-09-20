<?php
/**
 * TTN DVRef P25 reflector-directory lookup
 * LOCATION (proposed): portal/includes/enrich-dvref.php
 *
 * NOT independently verified the way the other four enrichment modules
 * were. My fetch tool respects robots.txt, and both dvref.com/api/v2/
 * and dvref.com/docs/ are disallowed by it for me -- I couldn't reach
 * the API response, the docs, or find third-party corroboration of the
 * exact header names, JSON field names, or the CC-BY-4.0 attribution
 * text (a forum FAQ I could reach didn't mention any of this; a
 * relevant pistar forum thread 403'd). Everything below is written
 * exactly as reported, not as something I confirmed myself -- unlike
 * radioid.net, which I did reach directly. Read the real
 * dvref.com/docs/ page yourself before this ships, if only to confirm
 * the header/field names below are actually right.
 *
 * SCOPE (per the report): this is a REFLECTOR directory -- which P25
 * network/reflector a designator/talkgroup routes to -- not a per-
 * radio/per-user lookup. Useful for identifying what external P25
 * system TTN's DVSwitch gear is bridged to; does NOT resolve individual
 * P25 radio IDs the way enrich-radioid.php resolves DMR IDs. Don't
 * wire this in as if it were the P25 equivalent of the DMR lookup --
 * it answers a different question.
 *
 * RATE LIMIT: reported live -- anonymous DVRef API access returns
 * HTTP 429 with a JSON body ("Anonymous DVRef API clients may retrieve
 * this resource once every six hours") past one request per resource
 * per 6h. The 24h cache TTL below is fine for steady state, but a
 * cleared cache file or querying more designators than expected turns
 * into a 6h lockout PER DESIGNATOR, not just a slow response -- this
 * is exactly why the function below fails closed to null on a bad
 * response rather than retrying: a retry loop against a 429 would make
 * this worse, not better. Don't call this speculatively to "just
 * check" a designator -- a wrong guess costs 6 hours before it can be
 * re-tried.
 *
 * ATTRIBUTION: the report says the API response itself carries the
 * required attribution string ("Reflector data provided by DVRef —
 * https://dvref.com/") under CC-BY-4.0, and that it must be displayed
 * on any public page using this data, not just used internally.
 * ttn_dvref_attribution_html() below exists so that's a one-line
 * include rather than something easy to forget -- but read DVRef's
 * actual license terms before this data appears on anything public.
 */

// ASSUMPTION (unverified): the exact header names, and that a
// callsign/contact identifying TTN is what's expected here rather than
// a per-operator value. Matches what was reported tested successfully.
define('TTN_DVREF_CALLSIGN', 'W4BWW');
define('TTN_DVREF_CONTACT', 'https://ttn.radio');

function ttn_dvref_attribution_html(): string {
    return 'Reflector data provided by <a href="https://dvref.com/">DVRef</a>';
}

function ttn_enrich_dvref_p25_reflector(string $designator): ?array {
    static $cache = null;
    $cache_file = '/tmp/ttn_dvref_cache.json';
    $ttl = 86400; // 24h -- reflector directory data, not a live-status poll

    $key = trim($designator);

    if ($cache === null) {
        $cache = file_exists($cache_file) ? (json_decode(file_get_contents($cache_file), true) ?: []) : [];
    }
    if (isset($cache[$key]) && (time() - $cache[$key]['fetched_at']) < $ttl) {
        return $cache[$key]['data'];
    }

    $data = ttn_fetch_dvref_reflector($key);
    $cache[$key] = ['data' => $data, 'fetched_at' => time()];
    @file_put_contents($cache_file, json_encode($cache));

    return $data;
}

function ttn_fetch_dvref_reflector(string $designator): ?array {
    $url = 'https://dvref.com/api/v2/p25/reflectors/?' . http_build_query(['search' => $designator]);

    // Unverified: exact header names as reported. If this 401s/403s in
    // practice, that's the first thing to check against the real docs.
    $ctx = stream_context_create(['http' => [
        'timeout' => 5,
        'header'  => "X-DVRef-Callsign: " . TTN_DVREF_CALLSIGN . "\r\n"
                   . "X-DVRef-Contact: " . TTN_DVREF_CONTACT . "\r\n",
        'user_agent' => 'TTN-Portal/1.0',
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }

    $parsed = json_decode($raw, true);
    // ASSUMPTION: response shape (results[] vs a bare array) -- handle
    // both rather than commit to one unverified guess.
    $results = $parsed['results'] ?? (is_array($parsed) ? $parsed : []);
    if (!$results) {
        return null;
    }

    // Integrity check -- confirm the returned reflector's designator
    // or slug actually matches what was searched for.
    $match = null;
    foreach ($results as $r) {
        if ((string)($r['designator'] ?? '') === $designator || (string)($r['slug'] ?? '') === $designator) {
            $match = $r;
            break;
        }
    }
    if (!$match) {
        error_log("[dvref] no matching reflector for designator={$designator} despite non-empty response");
        return null;
    }

    return [
        'designator'   => $match['designator'] ?? null,
        'name'         => $match['name'] ?? null,
        'slug'         => $match['slug'] ?? null,
        'url'          => $match['url'] ?? null,
        'dns'          => $match['dns'] ?? null,
        'ipv4'         => $match['ipv4'] ?? null,
        'ipv6'         => $match['ipv6'] ?? null,
        'port'         => $match['port'] ?? null,
        'sponsor'      => $match['sponsor'] ?? null,
        'country'      => $match['country'] ?? null,
        'network_type' => $match['network_type'] ?? null,
    ];
}
