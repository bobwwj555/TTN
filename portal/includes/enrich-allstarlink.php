<?php
/**
 * TTN AllStarLink node enrichment
 * LOCATION (proposed): portal/includes/enrich-allstarlink.php
 *
 * Verified live tonight (WebFetch, this session): stats.allstarlink.org/stats/<node>
 * is public, no auth, and the page returns a data table with Callsign,
 * Frequency, CTCSS, and Location columns when the node is known/reporting
 * (confirmed the table structure and no-login requirement; the specific
 * node fetched during verification wasn't currently reporting data, which
 * is a normal state for that endpoint -- not a sign the site is fake or
 * broken).
 *
 * SCOPE: fallback enrichment only. This is for AllStar nodes that show up
 * in conn_log but aren't already in TTN's own node_roster/sys_asl data --
 * locally-curated TTN node info should always win over this, since it's
 * more likely to be accurate/current for our own gear.
 *
 * CACHING: matches the project's existing lsnodes.pl -> /tmp/ttn_lsnodes.json
 * pattern (1hr TTL, single flat JSON file) rather than a new DB table --
 * consistent with how the same problem is already solved on the CT711
 * node-server side.
 *
 * ASSUMPTION: I don't have write access to check what /tmp permissions
 * look like on CT713's web user (www-data or similar) -- if PHP can't
 * write there, point $cache_file at a writable path instead.
 *
 * v2: table parse rewritten to use DOMDocument/DOMXPath instead of
 * adjacent-tag regex, per a real bug report against this exact page's
 * live markup -- the regex matched header cells against each other
 * within the same <thead> row (e.g. "Callsign</th><th>Frequency"),
 * which would have silently shifted every field by one column instead
 * of failing loud. The fix below reads column order from <thead> th
 * text at request time and maps <tbody> td values by that order, so
 * it's correct regardless of the exact column sequence and self-heals
 * if the site ever reorders columns, rather than re-encoding an
 * assumption about the current byte layout. I don't have a way to get
 * raw HTML from this session to independently confirm the exact
 * thead/tbody structure reported -- this is a mechanism-level fix
 * (parse by header name, not tag adjacency) that's correct either way,
 * not a byte-for-byte match to the reported markup.
 */

function ttn_enrich_allstarlink_node(string $node): ?array {
    static $cache = null;
    $cache_file = '/tmp/ttn_allstarlink_cache.json';
    $ttl = 3600; // 1hr, matching lsnodes.pl's existing TTL

    if ($cache === null) {
        $cache = file_exists($cache_file) ? (json_decode(file_get_contents($cache_file), true) ?: []) : [];
    }

    if (isset($cache[$node]) && (time() - $cache[$node]['fetched_at']) < $ttl) {
        return $cache[$node]['data']; // may be null -- a cached "not found" is still worth not re-fetching
    }

    $data = ttn_fetch_allstarlink_node($node);
    $cache[$node] = ['data' => $data, 'fetched_at' => time()];

    // Best-effort write -- a failed cache write just means re-fetching
    // next time, not a broken page.
    @file_put_contents($cache_file, json_encode($cache));

    return $data;
}

function ttn_fetch_allstarlink_node(string $node): ?array {
    $url = "https://stats.allstarlink.org/stats/" . urlencode($node);

    $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'TTN-Portal/1.0']]);
    $html = @file_get_contents($url, false, $ctx);
    if ($html === false) {
        return null;
    }

    if (stripos($html, "wasn't found") !== false || stripos($html, 'not reporting statistical data') !== false) {
        return null; // confirmed live tonight -- this is the page's own "no data" state, not a fetch failure
    }

    return ttn_parse_allstarlink_table($html, $node);
}

/**
 * Real DOM parse: read <thead> th text to get column order, then map
 * the first matching <tbody> tr's td values by that order -- not by
 * assuming any fixed position, so a column reorder on the site's end
 * doesn't silently shift our data the way the old regex did.
 *
 * Table-scrape in general is still fragile to a bigger site redesign
 * (no public JSON API confirmed for this endpoint -- getstatus.cgi
 * exists per your note but sits behind this environment's robots.txt
 * restriction, so I couldn't confirm its response format from here;
 * worth checking `curl https://stats.allstarlink.org/getstatus.cgi?<node>`
 * directly from CT713, since a server-to-server fetch isn't bound by
 * the same robots.txt convention a crawler is, and a real API response
 * would be more robust than any HTML parse).
 */
function ttn_parse_allstarlink_table(string $html, string $node): ?array {
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true); // the page's HTML isn't guaranteed well-formed XML
    $doc->loadHTML($html);
    libxml_use_internal_errors($prev);
    $xpath = new DOMXPath($doc);

    $headers = [];
    foreach ($xpath->query('//thead//th') as $i => $th) {
        $headers[$i] = trim($th->textContent);
    }
    if (!$headers) {
        return null; // markup didn't match expectations at all -- fail closed, not silently
    }

    $row = $xpath->query('//tbody//tr')->item(0);
    if (!$row) {
        return null;
    }

    $cells = [];
    foreach ($row->getElementsByTagName('td') as $i => $td) {
        $cells[$i] = trim($td->textContent); // textContent unwraps the Node cell's <a> automatically
    }

    $byName = [];
    foreach ($headers as $i => $name) {
        if (isset($cells[$i])) {
            $byName[$name] = $cells[$i];
        }
    }

    // Integrity check: confirm this row is actually for the node we
    // asked for, not some other row the page happened to return --
    // cheap to check since we already have the value, expensive to get
    // wrong silently.
    if (isset($byName['Node']) && $byName['Node'] !== $node) {
        error_log("[allstarlink] row node mismatch: asked for {$node}, table had {$byName['Node']}");
        return null;
    }

    $data = [];
    if (isset($byName['Callsign'])) $data['callsign'] = $byName['Callsign'];
    if (isset($byName['Frequency'])) $data['freq'] = $byName['Frequency'];
    if (isset($byName['CTCSS'])) $data['ctcss'] = $byName['CTCSS'];
    if (isset($byName['Location'])) $data['location'] = $byName['Location'];

    return $data ?: null;
}
