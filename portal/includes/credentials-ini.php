<?php
/**
 * TTN shared credentials.ini reader/writer
 * LOCATION (proposed): portal/includes/credentials-ini.php
 *
 * Single shared ini file, one section per service -- confirmed pattern,
 * not a guess: ttn-logger-dvswitch.php already reads /etc/ttn/credentials.ini
 * this way for its [database] section ($conf['database']['host']),
 * matching the standing rule in TTN_Instructions_2026-08-13.md
 * ("Credentials in /etc/ttn/credentials.ini, 0600, root:www-data") and
 * Bobby's own original ask ("some ini or other file... for any set of
 * needed creds").
 *
 * PERMISSION ISSUE, flagged rather than quietly assumed away: the
 * standing rule's stated mode (0600, root:www-data) gives ONLY the
 * file's owner (root) any access at all -- 0600 has no group bits, so
 * www-data (the user this portal runs as, and per
 * TTN_Security_2026-05-03.md the user its cron jobs are supposed to run
 * as too -- "Never run logger cron as root -- www-data only") could not
 * even READ this file as configured, let alone this page WRITE to it.
 * Two things already in this codebase disagree about who touches this
 * file: the security doc says cron must run as www-data, but
 * ttn-logger-dvswitch.php's own header comment says it runs "as root,
 * since /var/log/mmdvm is root-owned." Not something this file can
 * resolve by guessing a third answer -- worth Bobby's own look
 * independent of this patch, since it also means enrich-qrz.php (0005)
 * can't actually read QRZ creds from a 0600 file as-is either.
 *
 * For THIS file's write path to work at all, credentials.ini needs to be
 * group-writable by whichever user the portal actually runs as -- e.g.
 * `chown root:www-data /etc/ttn/credentials.ini && chmod 0660 ...` if
 * that's www-data. One-time server-side permission change, Bobby's to
 * make. This code never attempts to chmod/chown itself and fails loudly
 * (logged, boolean return) rather than silently if the write isn't
 * permitted.
 *
 * WRITE STRATEGY: rewrites the existing file's inode in place (fopen
 * 'r+' + ftruncate + fwrite, under flock), never a temp-file-plus-rename
 * -- a rename would create a NEW inode owned by whatever user/group this
 * process runs as, silently losing the root:www-data ownership the
 * standing rule requires. In-place rewrite preserves the existing
 * owner/mode; it only works if those already permit the write.
 *
 * SECTION PRESERVATION: only the target section's line range is
 * replaced. Every other section (e.g. [database]) is copied through
 * byte-for-byte, so this can never clobber unrelated credentials in the
 * same shared file. Trade-off: comments *inside* the section being
 * replaced are not preserved across a rewrite of that section; comments
 * in other sections are untouched.
 */

define('TTN_CREDENTIALS_INI_PATH', '/etc/ttn/credentials.ini');

/**
 * Reads one section as an associative array (empty array if the file or
 * section doesn't exist -- never a fatal, matching every enrichment
 * module's fail-closed pattern from 0005).
 */
function ttn_credentials_read_section(string $section): array {
    if (!file_exists(TTN_CREDENTIALS_INI_PATH)) {
        return [];
    }
    $all = @parse_ini_file(TTN_CREDENTIALS_INI_PATH, true);
    if ($all === false) {
        error_log('[credentials-ini] parse_ini_file failed on ' . TTN_CREDENTIALS_INI_PATH);
        return [];
    }
    return $all[$section] ?? [];
}

/**
 * True only if every given key in $section is present and non-empty --
 * the "is this service configured" check admin pages use. Deliberately
 * returns a bool, never the values themselves, so a caller can't
 * accidentally echo a credential into a page template.
 */
function ttn_credentials_section_configured(string $section, array $required_keys): bool {
    $values = ttn_credentials_read_section($section);
    foreach ($required_keys as $key) {
        if (empty($values[$key])) {
            return false;
        }
    }
    return true;
}

/**
 * Writes (creates or replaces) one [section] block with the given
 * key => value pairs, leaving every other section untouched. Returns
 * true on success, false on any failure (missing file, permission
 * denied, lock failure, short write) -- caller decides how to surface
 * that; this never throws or fatals, since a credentials save failing
 * shouldn't take the whole admin page down.
 */
function ttn_credentials_write_section(string $section, array $kv): bool {
    $path = TTN_CREDENTIALS_INI_PATH;

    if (!file_exists($path)) {
        error_log("[credentials-ini] {$path} does not exist -- refusing to create it from a web request. Create it server-side first (e.g. chmod 0660 root:www-data) before using this page.");
        return false;
    }

    $fh = @fopen($path, 'r+');
    if (!$fh) {
        error_log("[credentials-ini] cannot open {$path} for read/write -- check ownership/permissions (needs to be group-writable by this process's user).");
        return false;
    }

    if (!flock($fh, LOCK_EX)) {
        error_log("[credentials-ini] could not acquire lock on {$path}");
        fclose($fh);
        return false;
    }

    $raw = stream_get_contents($fh);
    if ($raw === false) {
        flock($fh, LOCK_UN);
        fclose($fh);
        return false;
    }

    $lines = preg_split('/\R/', $raw);
    $new_block = ["[{$section}]"];
    foreach ($kv as $k => $v) {
        // Quote every value and escape embedded quotes/backslashes so a
        // password containing " or \ can't break the ini structure or
        // spill into the next line/section.
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$v);
        $new_block[] = "{$k} = \"{$escaped}\"";
    }

    $out = [];
    $i = 0;
    $n = count($lines);
    $replaced = false;
    while ($i < $n) {
        $line = $lines[$i];
        if (preg_match('/^\s*\[' . preg_quote($section, '/') . '\]\s*$/', $line)) {
            // Found the existing section header -- swap in the new block,
            // then skip every line until the next [section] header or EOF.
            $out = array_merge($out, $new_block);
            $i++;
            while ($i < $n && !preg_match('/^\s*\[[^\]]+\]\s*$/', $lines[$i])) {
                $i++;
            }
            $replaced = true;
            continue;
        }
        $out[] = $line;
        $i++;
    }

    if (!$replaced) {
        // Section didn't exist yet -- append it (blank-line separator if
        // the file doesn't already end in one).
        if (!empty($out) && trim(end($out)) !== '') {
            $out[] = '';
        }
        $out = array_merge($out, $new_block);
    }

    $new_content = implode("\n", $out);
    if (substr($new_content, -1) !== "\n") {
        $new_content .= "\n";
    }

    rewind($fh);
    ftruncate($fh, 0);
    $written = fwrite($fh, $new_content);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    if ($written === false || $written < strlen($new_content)) {
        error_log("[credentials-ini] short or failed write to {$path}");
        return false;
    }

    return true;
}
