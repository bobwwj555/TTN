<?php
/**
 * TTN radioid.net bulk index refresh -- cron entry point
 * LOCATION (proposed): portal/cron/refresh-radioid-dump.php
 *
 * Wire this into a weekly crontab entry on CT713 so the CSV download
 * AND the SQLite index rebuild both happen proactively, off the
 * request path:
 *
 *   0 4 * * 0  php /path/to/portal/cron/refresh-radioid-dump.php
 *
 * (Sunday 4am, comfortably inside enrich-radioid.php's 7-day TTL.)
 * ttn_radioid_ensure_index() is safe to call without this cron job --
 * it self-heals inline on the next enrichment call if the CSV or index
 * is missing or stale -- but that means whichever portal visitor's
 * request happens to land the moment it goes stale eats the download
 * AND the index rebuild on their page load. This script exists so
 * that never happens in practice.
 */

require_once __DIR__ . '/../includes/enrich-radioid.php';

$ok = ttn_radioid_ensure_index(true); // force CSV refresh + index rebuild regardless of current TTL

echo $ok ? "radioid index refreshed\n" : "radioid index refresh FAILED (check error_log)\n";
exit($ok ? 0 : 1);
