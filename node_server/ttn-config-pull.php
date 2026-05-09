<?php
$conf_file  = '/etc/ttn-node.conf';
$output     = '/var/www/html/node-config.json';
$log_prefix = '['.date('Y-m-d H:i:s').'] ttn-config-pull: ';

if (!file_exists($conf_file)) { echo $log_prefix."ERROR: $conf_file not found\n"; exit(1); }

$conf = [];
foreach (file($conf_file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if (!$line || $line[0]==='#') continue;
    [$k,$v] = array_map('trim', explode('=', $line, 2));
    $conf[$k] = $v;
}

$portal_url = rtrim($conf['portal_url'] ?? '', '/');
$site_key   = $conf['site_key'] ?? '';

if (!$portal_url || !$site_key) { echo $log_prefix."ERROR: portal_url or site_key missing\n"; exit(1); }

$url = $portal_url.'/api/node-config.php?key='.urlencode($site_key);
$ctx = stream_context_create(['http'=>['timeout'=>10,'ignore_errors'=>true]]);
$raw = @file_get_contents($url, false, $ctx);

if ($raw === false) { echo $log_prefix."WARN: CT 713 unreachable — keeping existing node-config.json\n"; exit(0); }

$decoded = json_decode($raw, true);
if (!$decoded || json_last_error()!==JSON_ERROR_NONE) { echo $log_prefix."ERROR: invalid JSON — keeping existing\n"; exit(1); }
if (isset($decoded['error'])) { echo $log_prefix."ERROR: ".$decoded['error']."\n"; exit(1); }

$tmp = $output.'.tmp';
if (file_put_contents($tmp, $raw)===false) { echo $log_prefix."ERROR: could not write $tmp\n"; exit(1); }
rename($tmp, $output);
echo $log_prefix."OK: ".$decoded['site']." updated (".$decoded['generated'].")\n";
