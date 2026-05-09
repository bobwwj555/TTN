<?php
function ttn_node_refresh(int $site_id): void {
    $servers = db_rows("
        SELECT DISTINCT srv.ip_address, srv.public_hostname, s.site_api_key
        FROM asl_servers srv
        JOIN sys_asl sa ON sa.server_id = srv.id AND sa.is_active = 1
        JOIN systems sys ON sys.id = sa.system_id AND sys.site_id = ?
        JOIN sites s ON s.id = ?
        WHERE s.site_api_key IS NOT NULL
    ", [$site_id, $site_id]);

    if (empty($servers)) return;

    foreach ($servers as $srv) {
        $hostname = $srv["public_hostname"] ?? '';
        $key      = $srv['site_api_key'] ?? '';
        if (!$hostname || !$key) continue;
        $url = 'https://'.$hostname.'/ttn-refresh.php';
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/x-www-form-urlencoded',
            'content'       => 'key='.urlencode($key),
            'timeout'       => 5,
            'ignore_errors' => true,
        ]]);
        @file_get_contents($url, false, $ctx);
    }
}
