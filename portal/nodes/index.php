<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
header('Location: ' . s('site_url','https://dev.ttn.radio') . '/sites/');
exit;
