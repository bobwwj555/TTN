<?php
require_once '/etc/ttn_config.php';
require_once TTN_INCLUDES . '/db.php';
require_once TTN_INCLUDES . '/auth.php';
ttn_session_start();
ttn_logout();
header('Location: ' . s('site_url','https://dev.ttn.radio') . '/admin/login.php');
exit;
