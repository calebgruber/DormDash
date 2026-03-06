<?php
define('APP_NAME', 'DormDash');

// Build APP_URL that honours the current protocol so JS fetch() calls over
// HTTPS don't trigger Mixed Content errors.
$_rawUrl = getenv('APP_URL') ?: 'https://dormdash.dev.calebgruber.me';
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $_rawUrl = preg_replace('/^http:\/\//', 'https://', $_rawUrl);
}
define('APP_URL', rtrim($_rawUrl, '/'));
unset($_rawUrl);

define('UPLOAD_DIR',            __DIR__ . '/../uploads/');
define('UPLOAD_URL',            APP_URL . '/uploads/');
define('PURCHASE_EMAIL_DOMAIN', '@purchase.edu');
define('SESSION_NAME',          'dormdash_session');
