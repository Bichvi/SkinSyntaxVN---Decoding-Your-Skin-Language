<?php
// backend/app/config/config.php
date_default_timezone_set('Asia/Ho_Chi_Minh');

$base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base = rtrim($base, '/');

define('BASE_URL', $base);
