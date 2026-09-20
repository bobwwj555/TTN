<?php
/**
 * TTN radioid.net DMR user enrichment
 * LOCATION (proposed): portal/includes/enrich-radioid.php
 * COMPANION (proposed): portal/cron/refresh-radioid-dump.php (see bottom of this file)
 *
 * Independently verified live tonight (WebFetch, this session):
 * https://radioid.net/api/dmr/user/?id=3147984 -- real JSON API, no
 * auth, matched every field name and the shape reported (count:313458,
 * results[] with radio_id/callsign/fname/name/city/state/country/
 * lastheard/lastmaster/lasttg). Full confidence on this one -- unlike
 * DVRef, I reached the real endpoint myself.
 *
 * SCOPE: dmr_conn_log.src_id is the raw DMR radio ID, so the by-ID
 * lookup is what actually gets used for last-heard enrichment (radio_id
 * -> callsign/name/location). by-callsign is included too since the API
 * supports it cheaply, but isn't the expected call path here.
 *
 * v2 -- BULK DUMP AS PRIMARY SOURCE, LIVE API AS FALLBACK ONLY:
 * per Bobby's research into how the wider Pi-Star ecosystem solves this
 * exact problem (kencormack/pistar-lastqso), the sanctioned pattern is
 * a periodic bulk CSV download with local lookups, live API used only
 * for records the current dump doesn't have yet. Confirmed directly
 * against that tool's own README: it re-downloads only when its local
 * copy is >7 days old (verbatim: "If the user.csv file is already
 * present on your hotspot, but is older than 7 days, pistar-lastqso
 * will update the file to it's latest version automatically").
 *
 * Correction found during that verification: the real URL a working
 * reference tool pulls from is https://database.radioid.net/static/user.csv
 * -- not https://radioid.net/database/dumps/user.csv as originally
 * reported (different subdomain). The dumps listing page itself is
 * behind a "Checking your browser..." bot-check interstitial I
 * couldn't get past from this session (same style as DVRef's Anubis
 * gate), so I couldn't compare the two directly -- went with the one
 * that has real third-party corroboration.
 *
 * v3 -- INDEXED STORE INSTEAD OF A LINEAR SCAN, per a second, sharper
 * finding: Bobby pasted the actual lh.php source from a DVSwitch
 * dashboard mod (same software this gateway runs) that does this exact
 * job today. It reads Pi-Star's DMRIds.dat into a plain PHP indexed
 * array on every single page load (full file read + parse, no
 * cross-request cache), then for each of the ~20 displayed rows runs a
 * linear `strpos($dmrIDline[$a], $arr2) != false` scan across all
 * ~177k lines -- an O(rows_shown x file_size) scan repeated per page
 * load. That's real, confirmed-in-code corroboration for what forum
 * reports describe as 100% CPU, not an inferred guess. It also has a
 * genuine correctness bug worth naming even though this file never had
 * it: `!= false` is a loose comparison, so a match strpos() finds at
 * position 0 (`0 != false` evaluates false in PHP) gets silently
 * treated as no-match -- an occasional dropped real hit, not just slow.
 *
 * This file's v2 never had that correctness bug (exact `===` compare
 * against a parsed CSV field, not strpos against a whole line), but it
 * had the same *performance* shape: `ttn_radioid_bulk_lookup_by_id()`
 * did a streamed `fgetcsv` scan of the whole file per lookup, with
 * nothing persisted between requests. Same underlying problem, no
 * substring-match bug.
 *
 * Fixed properly below: the weekly refresh parses the CSV exactly ONCE
 * and builds a small SQLite file (indexed on radio_id as the primary
 * key, plus an index on callsign) rather than a bigger/faster linear
 * scan or a re-parsed-every-request PHP array. Per-request lookups are
 * then a single indexed SQLite query -- no full-file scan, no ~300k-row
 * PHP array held in memory, no re-parsing CSV text, on every page load.
 * SQLite over a serialized-PHP-array cache (the other option raised)
 * because a flat associative array still has to be fully deserialized
 * into memory before a single key can be read; a SQLite B-tree index
 * answers one query without loading the other 299,999 rows at all.
 *
 * ASSUMPTION, flagged rather than confirmed: this needs PHP's sqlite3
 * extension (bundled with core PHP since 5.3 and enabled in this
 * sandbox, confirmed via `php -m`) actually enabled on CT713 too --
 * that's a different host and I have no way to check it from here. If
 * it isn't, ttn_radioid_ensure_index() below detects that
 * (class_exists('SQLite3')) and fails closed -- the whole bulk path
 * quietly disables itself and every lookup falls back to the live
 * per-ID API, same graceful degradation as "no dump file yet." Run
 * `php -m | grep -i sqlite` on CT713 once to confirm before assuming
 * the fast path is actually active there.
 *
 * CSV HEADER: CONFIRMED, not inferred. Bobby downloaded the real file
 * and handed it over directly -- 17,053,263 bytes, 313,459 lines
 * (313,458 data rows), matching radioid.net's own dumps listing and
 * its "313458 DMR ID's" homepage figure. Header row, byte for byte:
 *   RADIO_ID,CALLSIGN,FIRST_NAME,LAST_NAME,CITY,STATE,COUNTRY
 * -- exactly the first candidate in ttn_radioid_bulk_column_map() for
 * every column, so no behavior changes here. Sample row confirmed too:
 * 3147984,W4BWW,Bobby,,New Market,Tennessee,United States (empty
 * LAST_NAME is real, not a parse gap -- matches the live API result
 * from earlier verification exactly).
 *
 * Column names are matched by NAME at parse time rather than hardcoded
 * to fixed positions, deliberately, even now that the exact header is
 * known -- and this costs nothing to keep: the header-row parse runs
 * ONCE per weekly build (ttn_radioid_build_index_from_csv()), not once
 * per lookup, so there's no per-request performance case for
 * hardcoding it away. What it buys instead is resilience for an
 * unattended weekly cron job: if radioid.net ever reorders these
 * columns, name-based mapping keeps working without a code change;
 * fixed positions would silently misparse every field until someone
 * noticed. If the real header ever stops matching, it fails closed and
 * logs the actual row it saw, rather than either guessing or crashing.
 *
 * Still open, unrelated to the header itself: whether radioid.net
 * regenerates the source CSV daily -- only the *client's* 7-day
 * re-check cadence (pistar-lastqso's own behavior) is confirmed, not
 * the server side's actual update frequency. The bulk dump is
 * registration data, so it won't carry lastheard/lasttg -- those come
 * back null on index-sourced records by design; only the live-API
 * fallback still populates them.
 *
 * USAGE POLICY: radioid.net's policy prohibits bulk mirroring,
 * re-publishing as a public feed, or building a competing directory,
 * and separately says to "be gentle" with request volume; the dump
 * files reportedly carry the same terms as the API. I could not
 * re-read the actual policy page this session to confirm that specific
 * line (same bot-check issue as above) -- worth reading directly
 * before this ships. A once-a-week bulk download used for on-demand,
 * cached, non-republished internal lookups reads as a conservative fit
 * either way.
 *
 * OPERATIONAL NOTE: wire refresh-radioid-dump.php (bottom of this
 * file) into a weekly cron job on CT713. ttn_radioid_ensure_index()
 * self-heals inline if the index is missing or stale, but that means
 * whichever portal visitor's request lands the moment it goes stale
 * eats the download-and-rebuild on their page load -- the whole reason
 * a v2-vs-v3 rework mattered in the first place was to get this work
 * OFF the request path, so don't let it sneak back onto the request
 * path via a missing cron entry.
 */

define('TTN_RADIOID_DUMP_URL', 'https://database.radioid.net/static/user.csv');
define('TTN_RADIOID_DUMP_FILE', '/tmp/ttn_radioid_user_dump.csv');
define('TTN_RADIOID_INDEX_FILE', '/tmp/ttn_radioid_user.sqlite');
define('TTN_RADIOID_DUMP_TTL', 604800); // 7 days -- matches pistar-lastqso's confirmed refresh cadence

function ttn_enrich_radioid_user(string $radio_id): ?array {
    static $cache = null;
    $cache_file = '/tmp/ttn_radioid_cache.json';
    $ttl = 86400; // 24h -- registration data, not a live-status poll

    $key = trim($radio_id);

    if ($cache === null) {
        $cache = file_exists($cache_file) ? (json_decode(file_get_contents($cache_file), true) ?: []) : [];
    }
    if (isset($cache[$key]) && (time() - $cache[$key]['fetched_at']) < $ttl) {
        return $cache[$key]['data'];
    }

    $data = ttn_radioid_lookup_by_id($key);
    $cache[$key] = ['data' => $data, 'fetched_at' => time()];
    @file_put_contents($cache_file, json_encode($cache));

    return $data;
}

function ttn_enrich_radioid_by_callsign(string $callsign): array {
    $key = strtoupper(trim($callsign));
    $result = ttn_radioid_index_lookup_by_callsign($key);

    // Index has no matching row (or isn't available at all) -- fall
    // back to the live API, same as the by-ID path.
    if (!$result) {
        $result = ttn_fetch_radioid('callsign', $key, true);
    }

    return is_array($result) ? $result : [];
}

/**
 * Primary lookup path: indexed SQLite first, live API only as fallback
 * for a radio_id the current index doesn't have yet (a brand-new
 * registration since the last weekly refresh) or if no index is
 * available at all (extension missing, first run before cron has
 * populated it, or a download/build failure with nothing usable yet).
 */
function ttn_radioid_lookup_by_id(string $radio_id): ?array {
    $indexed = ttn_radioid_index_lookup_by_id($radio_id);
    if ($indexed) {
        return $indexed;
    }

    return ttn_fetch_radioid('id', $radio_id);
}

/**
 * Ensures a local copy of the raw bulk CSV exists and is fresh. Fails
 * closed on the REFRESH (a failed download never destroys a good
 * existing file), but returns false if there is no usable file at all
 * afterward. This is now an intermediate build artifact for
 * ttn_radioid_ensure_index() -- lookups never read this file directly.
 */
function ttn_radioid_ensure_dump(bool $force = false): bool {
    $fresh = file_exists(TTN_RADIOID_DUMP_FILE) && (time() - filemtime(TTN_RADIOID_DUMP_FILE)) < TTN_RADIOID_DUMP_TTL;
    if ($fresh && !$force) {
        return true;
    }

    $ctx = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'TTN-Portal/1.0']]);
    $raw = @file_get_contents(TTN_RADIOID_DUMP_URL, false, $ctx);

    if ($raw === false || strlen($raw) < 1000) {
        // Download failed or came back suspiciously small (e.g. an
        // error page instead of the real CSV) -- don't overwrite a
        // known-good existing file with garbage.
        error_log('[radioid] dump refresh failed or looked truncated; keeping existing file if present');
        return file_exists(TTN_RADIOID_DUMP_FILE);
    }

    // Atomic swap: write to a temp file in the same directory, then
    // rename over the target, so a concurrent reader never sees a
    // half-written file.
    $tmp = TTN_RADIOID_DUMP_FILE . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $raw) === false) {
        return file_exists(TTN_RADIOID_DUMP_FILE);
    }
    @rename($tmp, TTN_RADIOID_DUMP_FILE);

    return true;
}

/**
 * Ensures the SQLite index is built and at least as fresh as the CSV
 * it's built from. This is the function the request path actually
 * depends on -- ensure_dump() alone is not enough to serve a lookup.
 */
function ttn_radioid_ensure_index(bool $force = false): bool {
    if (!class_exists('SQLite3')) {
        error_log('[radioid] SQLite3 extension not available -- bulk index disabled, falling back to live API per lookup. Run `php -m | grep -i sqlite` on this host to fix.');
        return false;
    }

    if (!ttn_radioid_ensure_dump($force)) {
        return false; // no CSV at all -- nothing to index from
    }

    $index_fresh = file_exists(TTN_RADIOID_INDEX_FILE)
        && filemtime(TTN_RADIOID_INDEX_FILE) >= filemtime(TTN_RADIOID_DUMP_FILE);

    if ($index_fresh && !$force) {
        return true;
    }

    return ttn_radioid_build_index_from_csv(TTN_RADIOID_DUMP_FILE, TTN_RADIOID_INDEX_FILE);
}

/**
 * The one place this file does a full linear pass over the CSV --
 * deliberately, since it happens once per weekly refresh, not once per
 * lookup. Builds into a temp SQLite file and atomically renames it
 * into place, so a request reading the index mid-rebuild always sees
 * either the complete old index or the complete new one, never a
 * half-built file.
 */
function ttn_radioid_build_index_from_csv(string $csv_path, string $index_path): bool {
    $fh = @fopen($csv_path, 'r');
    if (!$fh) {
        return file_exists($index_path);
    }

    $tmp_path = $index_path . '.tmp.' . getmypid();
    @unlink($tmp_path);
    $count = 0;

    try {
        $map = ttn_radioid_bulk_column_map($fh);
        if ($map === null) {
            return file_exists($index_path); // bad/unrecognized CSV -- keep whatever index already existed
        }

        $db = new SQLite3($tmp_path);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA journal_mode = OFF'); // scratch build file -- discarded on any failure, no durability needed mid-build
        $db->exec('PRAGMA synchronous = OFF');
        $db->exec('CREATE TABLE users (
            radio_id TEXT PRIMARY KEY,
            callsign TEXT,
            name TEXT,
            city TEXT,
            state TEXT,
            country TEXT
        )');

        $stmt = $db->prepare(
            'INSERT OR REPLACE INTO users (radio_id, callsign, name, city, state, country)
             VALUES (:radio_id, :callsign, :name, :city, :state, :country)'
        );

        $db->exec('BEGIN');
        while (($row = fgetcsv($fh)) !== false) {
            $rec = ttn_radioid_bulk_row_to_record($row, $map);
            if (!$rec) {
                continue;
            }
            $stmt->bindValue(':radio_id', $rec['radio_id'], SQLITE3_TEXT);
            $stmt->bindValue(':callsign', $rec['callsign'], SQLITE3_TEXT);
            $stmt->bindValue(':name', $rec['name'], SQLITE3_TEXT);
            $stmt->bindValue(':city', $rec['city'], SQLITE3_TEXT);
            $stmt->bindValue(':state', $rec['state'], SQLITE3_TEXT);
            $stmt->bindValue(':country', $rec['country'], SQLITE3_TEXT);
            $stmt->execute();
            $stmt->reset();
            $count++;
        }
        $db->exec('COMMIT');
        $db->exec('CREATE INDEX idx_users_callsign ON users(callsign COLLATE NOCASE)');
        $db->close();
    } catch (\Throwable $e) {
        error_log('[radioid] index build failed: ' . $e->getMessage());
        @unlink($tmp_path);
        return file_exists($index_path);
    } finally {
        fclose($fh);
    }

    if ($count === 0) {
        // Parsed zero usable rows -- almost certainly a bad file.
        // Don't swap an empty index over a good existing one.
        error_log('[radioid] index build produced zero rows, discarding');
        @unlink($tmp_path);
        return file_exists($index_path);
    }

    @rename($tmp_path, $index_path);
    return true;
}

function ttn_radioid_index_lookup_by_id(string $radio_id): ?array {
    if (!ttn_radioid_ensure_index()) {
        return null;
    }

    $db = ttn_radioid_open_index_readonly();
    if (!$db) {
        return null;
    }

    $row = null;
    try {
        $stmt = $db->prepare('SELECT * FROM users WHERE radio_id = :id');
        $stmt->bindValue(':id', $radio_id, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC) ?: null;
    } finally {
        $db->close();
    }

    return $row ? ttn_radioid_sqlite_row_to_record($row) : null;
}

function ttn_radioid_index_lookup_by_callsign(string $callsign): array {
    if (!ttn_radioid_ensure_index()) {
        return [];
    }

    $db = ttn_radioid_open_index_readonly();
    if (!$db) {
        return [];
    }

    $records = [];
    try {
        $stmt = $db->prepare('SELECT * FROM users WHERE callsign = :call COLLATE NOCASE');
        $stmt->bindValue(':call', $callsign, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $records[] = ttn_radioid_sqlite_row_to_record($row);
        }
    } finally {
        $db->close();
    }

    return $records;
}

function ttn_radioid_open_index_readonly(): ?SQLite3 {
    if (!file_exists(TTN_RADIOID_INDEX_FILE)) {
        return null;
    }
    try {
        return new SQLite3(TTN_RADIOID_INDEX_FILE, SQLITE3_OPEN_READONLY);
    } catch (\Throwable $e) {
        error_log('[radioid] failed to open index: ' . $e->getMessage());
        return null;
    }
}

function ttn_radioid_sqlite_row_to_record(array $row): array {
    return [
        'radio_id'  => $row['radio_id'],
        'callsign'  => $row['callsign'],
        'name'      => $row['name'],
        'city'      => $row['city'],
        'state'     => $row['state'],
        'country'   => $row['country'],
        // Not present in the bulk registration dump -- only the live
        // per-ID API returns these; left null on index-sourced records
        // by design, not a gap.
        'lastheard' => null,
        'lasttg'    => null,
    ];
}

/**
 * Reads the header row (must be called with the file pointer at the
 * very start) and maps column names to indexes, matching several
 * plausible header spellings rather than assuming exact ones -- see
 * the "exact CSV column headers" caveat at the top of this file.
 * Advances the file pointer past the header row on success.
 */
function ttn_radioid_bulk_column_map($fh): ?array {
    $header = fgetcsv($fh);
    if (!$header) {
        return null;
    }

    $normalized = array_map(fn($h) => strtoupper(trim($h)), $header);

    $find = function (array $candidates) use ($normalized): ?int {
        foreach ($candidates as $c) {
            $i = array_search($c, $normalized, true);
            if ($i !== false) {
                return $i;
            }
        }
        return null;
    };

    $map = [
        'radio_id'   => $find(['RADIO_ID', 'ID', 'DMRID', 'DMR_ID']),
        'callsign'   => $find(['CALLSIGN', 'CALL']),
        'first_name' => $find(['FIRST_NAME', 'FNAME', 'FIRSTNAME']),
        'last_name'  => $find(['LAST_NAME', 'SURNAME', 'LNAME', 'LASTNAME', 'NAME']),
        'city'       => $find(['CITY']),
        'state'      => $find(['STATE']),
        'country'    => $find(['COUNTRY']),
    ];

    if ($map['radio_id'] === null || $map['callsign'] === null) {
        error_log('[radioid] bulk dump header row did not match any known column shape: ' . implode(',', $header));
        return null;
    }

    return $map;
}

function ttn_radioid_bulk_row_to_record(array $row, array $map): ?array {
    $get = fn($key) => $map[$key] !== null && isset($row[$map[$key]]) ? trim($row[$map[$key]]) : null;

    $radio_id = $get('radio_id');
    if (!$radio_id) {
        return null;
    }

    return [
        'radio_id'   => $radio_id,
        'callsign'   => $get('callsign'),
        'name'       => trim(($get('first_name') ?? '') . ' ' . ($get('last_name') ?? '')),
        'city'       => $get('city'),
        'state'      => $get('state'),
        'country'    => $get('country'),
    ];
}

/**
 * Live per-ID/per-callsign API fetch -- the FALLBACK path, used when
 * the index has no matching row (or isn't available at all), not the
 * primary lookup. Unchanged from the original single-source version.
 *
 * @param bool $multi false: return the single matching record (by id) or
 *   null. true: return every matching record as an array (by callsign,
 *   which can have more than one radio ID registered).
 */
function ttn_fetch_radioid(string $field, string $value, bool $multi = false) {
    $url = 'https://radioid.net/api/dmr/user/?' . http_build_query([$field => $value]);
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'TTN-Portal/1.0']]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return $multi ? [] : null;
    }

    $parsed = json_decode($raw, true);
    $results = $parsed['results'] ?? [];
    if (!$results) {
        return $multi ? [] : null;
    }

    // Integrity check -- confirm returned record(s) actually match what
    // was asked for, don't trust API-side filtering blindly.
    $matched = array_values(array_filter($results, function ($r) use ($field, $value) {
        if ($field === 'id') {
            return (string)($r['radio_id'] ?? '') === $value;
        }
        return strcasecmp((string)($r['callsign'] ?? ''), $value) === 0;
    }));

    if (!$matched) {
        error_log("[radioid] no matching {$field}={$value} in results despite non-empty response");
        return $multi ? [] : null;
    }

    $map = fn($r) => [
        'radio_id' => $r['radio_id'] ?? null,
        'callsign' => $r['callsign'] ?? null,
        'name'     => trim(($r['fname'] ?? '') . ' ' . ($r['surname'] ?? $r['name'] ?? '')),
        'city'     => $r['city'] ?? null,
        'state'    => $r['state'] ?? null,
        'country'  => $r['country'] ?? null,
        'lastheard' => $r['lastheard'] ?? null,
        'lasttg'    => $r['lasttg'] ?? null,
    ];

    return $multi ? array_map($map, $matched) : $map($matched[0]);
}

/**
 * ---------------------------------------------------------------------
 * refresh-radioid-dump.php (proposed companion file, NOT this file --
 * split out to portal/cron/refresh-radioid-dump.php so it can be wired
 * into a crontab entry directly, e.g.:
 *   0 4 * * 0  php /path/to/portal/cron/refresh-radioid-dump.php
 * i.e. weekly, comfortably inside the 7-day TTL, so the inline
 * ensure_index() check in the request path almost never has to do the
 * actual download-and-rebuild itself:
 *
 *   <?php
 *   require_once __DIR__ . '/../includes/enrich-radioid.php';
 *   $ok = ttn_radioid_ensure_index(true); // force refresh + rebuild regardless of current TTL
 *   echo $ok ? "radioid index refreshed\n" : "radioid index refresh FAILED\n";
 *   exit($ok ? 0 : 1);
 * ---------------------------------------------------------------------
 */
