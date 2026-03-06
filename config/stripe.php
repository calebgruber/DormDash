<?php
// Load local overrides (same file used by database.php; require_once ensures single load)
$_localCfg = __DIR__ . '/local.php';
if (file_exists($_localCfg)) {
    require_once $_localCfg;
}
unset($_localCfg);

if (!defined('STRIPE_PUBLIC_KEY'))    define('STRIPE_PUBLIC_KEY',    getenv('STRIPE_PUBLIC_KEY')    ?: 'pk_test_placeholder');
if (!defined('STRIPE_SECRET_KEY'))    define('STRIPE_SECRET_KEY',    getenv('STRIPE_SECRET_KEY')    ?: 'sk_test_placeholder');
if (!defined('STRIPE_WEBHOOK_SECRET')) define('STRIPE_WEBHOOK_SECRET', getenv('STRIPE_WEBHOOK_SECRET') ?: '');
