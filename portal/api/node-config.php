<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$key = trim($_GET['key'] ?? '');
if (!$key) { http_response_code(401); echo json_encode(['error'=>'missing key']); exit; }

$site = db_row("SELECT * FROM sites WHERE site_api_key=? AND is_public=1", [$key]);
if (!$site) { http_response_code(403); echo json_encode(['error'=>'invalid key']); exit; }

$site_id = $site['id'];

$sys = db_row("
    SELECT sys.*, sa.asl_number AS hub_asl
    FROM systems sys
    LEFT JOIN sys_asl sa ON sa.system_id=sys.id AND sa.is_hub=1
    WHERE sys.site_id=? AND sys.is_public=1
    ORDER BY sys.sort_order LIMIT 1
", [$site_id]);

$asl_nodes = db_rows("
    SELECT sa.asl_number, sa.node_type, sa.callsign, sa.is_hub
    FROM sys_asl sa
    JOIN systems s ON s.id=sa.system_id
    WHERE s.site_id=? AND sa.is_active=1
    ORDER BY sa.is_hub DESC, sa.asl_number
", [$site_id]);

$interfaces = db_rows("
    SELECT si.label, si.url, si.interface_type, si.notes
    FROM sys_interfaces si
    JOIN systems s ON s.id=si.system_id
    WHERE s.site_id=? AND si.is_public=1
    ORDER BY si.sort_order, si.label
", [$site_id]);

$loc = trim(($site['city'] ? $site['city'].', ' : '').($site['state'] ?? 'TN'));

$specs = [];
if ($loc) $specs[] = ['Location', $loc.($site['county'] ? ' · '.$site['county'].' Co.' : ''), ''];
if ($sys && $sys['hub_asl']) $specs[] = ['Hub Node', 'AllStar '.$sys['hub_asl'], 'a'];
if ($sys && $sys['freq_tx'] && $sys['freq_tx'] != '0.0000') {
    $freq = $sys['freq_tx'];
    if ($sys['freq_rx'] && $sys['freq_rx'] != '0.0000') $freq .= ' / '.$sys['freq_rx'];
    if ($sys['access_code']) $freq .= ' · PL '.$sys['access_code'];
    if ($sys['band']) $freq .= ' · '.$sys['band'];
    $specs[] = ['Frequency', $freq, ''];
}
foreach ($asl_nodes as $n) {
    if (!$n['is_hub']) $specs[] = ['AllStar', $n['asl_number'].($n['node_type'] ? ' · '.ucfirst($n['node_type']) : ''), ''];
}
if ($site['tower_height_ft']) {
    $t = $site['tower_height_ft'].'ft';
    if ($site['tower_type']) $t .= ' '.$site['tower_type'];
    $specs[] = ['Tower', $t, ''];
}
if ($site['power_primary']) {
    $p = $site['power_primary'];
    if ($site['power_backup']) $p .= ' · Backup: '.$site['power_backup'];
    if ($site['solar_watts']) $p .= ' · '.$site['solar_watts'].'W Solar';
    $specs[] = ['Power', $p, 'g'];
}
if ($site['elevation_ft']) $specs[] = ['Elevation', $site['elevation_ft'].'ft ASL', ''];
if ($sys && $sys['callsign']) $specs[] = ['Trustee', $sys['callsign'], ''];

$ticker = [];
if ($sys && $sys['freq_tx'] && $sys['freq_tx'] != '0.0000') {
    $t = $sys['freq_tx'];
    if ($sys['freq_rx'] && $sys['freq_rx'] != '0.0000') $t .= ' / '.$sys['freq_rx'];
    if ($sys['access_code']) $t .= ' · PL '.$sys['access_code'];
    if ($sys['hub_asl']) $t .= ' · AllStar '.$sys['hub_asl'];
    if ($loc) $t .= ' · '.strtoupper($loc);
    $ticker[] = $t;
} elseif ($sys && $sys['hub_asl']) {
    $ticker[] = 'AllStar '.$sys['hub_asl'].' · '.strtoupper($site['name']);
}
foreach ($asl_nodes as $n) {
    if (!$n['is_hub'] && $n['asl_number'])
        $ticker[] = 'AllStar '.$n['asl_number'].($n['node_type'] ? ' · '.strtoupper($n['node_type']) : '');
}
$ticker[] = 'TTN · TENNESSEE TECHNOLOGICAL COMMUNITY · ttn.radio';
$ticker[] = 'NO DUES · NO POLITICS · OPEN TO ALL LICENSED HAMS';
if ($sys && $sys['callsign'] && $loc) $ticker[] = strtoupper($sys['callsign']).' · '.strtoupper($loc);

$images = [];
if ($site['photo_url'])    $images[] = [$site['photo_url'],    'Site Photo'];
if ($site['coverage_url']) $images[] = [$site['coverage_url'], 'Coverage Estimate'];

$hub_node = $sys['hub_asl'] ?? ($asl_nodes[0]['asl_number'] ?? '');
$hub_freq = ($sys && $sys['freq_tx'] && $sys['freq_tx'] != '0.0000') ? $sys['freq_tx'] : '';
$callsign = $sys['callsign'] ?? '';
$description = $site['notes'] ?? ('TTN '.$site['name'].' node. AllStarLink backbone for the Tennessee Technological Community.');
$site_url = db_row("SELECT setting_val FROM site_settings WHERE setting_key='site_url'")['setting_val'] ?? 'https://ttn.radio';

echo json_encode([
    'generated'      => gmdate('c'),
    'site_id'        => $site_id,
    'site'           => $site['name'],
    'callsign'       => $callsign,
    'location'       => $loc,
    'description'    => $description,
    'hub_node'       => $hub_node,
    'hub_freq'       => rtrim(rtrim($hub_freq, '0'), '.'),
    'status_url'     => '/ttn-status.php',
    'ttn_url'        => $site_url,
    'interfaces'     => array_map(fn($i) => [
        'label' => $i['label'],
        'url'   => $i['url'],
        'type'  => $i['interface_type'],
        'notes' => $i['notes'] ?? '',
    ], $interfaces),
    'specs'          => $specs,
    'ticker'         => $ticker,
    'images'         => $images,
    'footer_main'    => ($callsign ?: 'TTN').' · '.$site['name'].' · Tennessee Technological Community · EIN 41-2680033',
    'footer_contact' => 'ttn.radio',
    'cw_text'        => ($callsign ?: 'TTN').' DE TTN 73',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
