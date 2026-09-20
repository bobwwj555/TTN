<?php
/**
 * TTN EchoLink node/callsign lookup
 * LOCATION (proposed): portal/includes/enrich-echolink.php
 *
 * Verified independently, twice, tonight: fetched
 * https://www.echolink.org/validation/node_lookup.jsp?call=W4BWW-L
 * myself (plain GET, no cookies/session) and got back the same three
 * rows reported (W4BWW/535789, W4BWW-L/995754, W4BWW-R/358104) --
 * matches Bobby's own browser result too. No auth, no form POST
 * required despite the page presenting itself as a form.
 *
 * This is the validation/registry lookup (who's registered to which
 * node, whether or not currently online) -- different from
 * secure.echolink.org/logins.jsp, which is a live "who's online right
 * now" snapshot of the whole network and isn't built here (~280KB,
 * ~6500 rows, wrong shape for a per-callsign enrichment call). If a
 * "currently online" signal is wanted later, that's a separate,
 * differently-shaped module against a differently-shaped source, not
 * an extension of this one.
 *
 * TABLE PARSE: same pattern as enrich-allstarlink.php's v2 fix --
 * DOMDocument/DOMXPath, columns read from <thead> th text at request
 * time rather than assumed by position or matched by tag-adjacency
 * regex. I have NOT independently inspected this page's raw HTML (only
 * WebFetch's processed summary, same limitation as before), so this
 * deliberately doesn't hardcode "exactly two columns" -- it picks up
 * whatever columns the live thead actually has.
 *
 * INTEGRITY CHECK: a query for one base callsign can legitimately
 * return several rows (base + -L/-R/-x suffixes, as with W4BWW above),
 * so this doesn't check for a single exact match like the AllStarLink
 * module does -- instead it drops any returned row whose callsign
 * doesn't start with the queried base callsign, as a basic guard
 * against a parsing bug pulling in unrelated table content.
 *
 * INTEGRATION POINT (deliberately not built yet): which callsigns to
 * query is still open, pending Bobby's conn_log check for system_id=8
 * -- once that's back, the caller should be whatever iterates
 * conn_log's distinct callsigns for EchoLink-shaped rows, not this
 * file itself.
 */

function ttn_enrich_echolink_callsign(string $callsign): array {
    static $cache = null;
    $cache_file = '/tmp/ttn_echolink_cache.json';
    $ttl = 86400; // 24h -- this is a registry/validation lookup, not a live status poll, so it changes rarely

    $key = strtoupper(trim($callsign));

    if ($cache === null) {
        $cache = file_exists($cache_file) ? (json_decode(file_get_contents($cache_file), true) ?: []) : [];
    }

    if (isset($cache[$key]) && (time() - $cache[$key]['fetched_at']) < $ttl) {
        return $cache[$key]['data'];
    }

    $data = ttn_fetch_echolink_lookup($key);
    $cache[$key] = ['data' => $data, 'fetched_at' => time()];
    @file_put_contents($cache_file, json_encode($cache));

    return $data;
}

function ttn_fetch_echolink_lookup(string $callsign): array {
    $url = 'https://www.echolink.org/validation/node_lookup.jsp?call=' . urlencode($callsign);

    $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'TTN-Portal/1.0']]);
    $html = @file_get_contents($url, false, $ctx);
    if ($html === false) {
        return [];
    }

    return ttn_parse_echolink_table($html, $callsign);
}

function ttn_parse_echolink_table(string $html, string $base_callsign): array {
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML($html);
    libxml_use_internal_errors($prev);
    $xpath = new DOMXPath($doc);

    $headers = [];
    foreach ($xpath->query('//thead//th') as $i => $th) {
        $headers[$i] = trim($th->textContent);
    }
    if (!$headers) {
        return []; // no results, or markup didn't match expectations -- fail closed
    }

    $results = [];
    foreach ($xpath->query('//tbody//tr') as $row) {
        $cells = [];
        foreach ($row->getElementsByTagName('td') as $i => $td) {
            $cells[$i] = trim($td->textContent);
        }
        $byName = [];
        foreach ($headers as $i => $name) {
            if (isset($cells[$i])) {
                $byName[$name] = $cells[$i];
            }
        }
        if (isset($byName['Callsign']) && stripos($byName['Callsign'], $base_callsign) !== 0) {
            error_log("[echolink] dropped unrelated row: asked for {$base_callsign}, got {$byName['Callsign']}");
            continue;
        }
        if ($byName) {
            $results[] = $byName;
        }
    }

    return $results;
}
