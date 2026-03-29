<?php
// Load local overrides first (written by /config/ settings page, gitignored)
$_localCfg = __DIR__ . '/local.php';
if (file_exists($_localCfg)) {
    require_once $_localCfg;
}
unset($_localCfg);

if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'voxelnodes_dormdash_dev');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'voxelnodes_dormdash_dev');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '%sS?ffKHkUI&');
