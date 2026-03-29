<?php
require_once __DIR__ . '/../includes/auth.php';
startAppSession();

// Toggle theme in the user's session
$current = $_SESSION['theme'] ?? 'light';
$_SESSION['theme'] = ($current === 'dark') ? 'light' : 'dark';

// Redirect back to the page the user came from
$redirect = $_GET['redirect'] ?? '/';
// Basic open-redirect guard: only allow relative paths on same host
$parsed = parse_url($redirect);
if (isset($parsed['host'])) {
    $redirect = '/';
}

$base = defined('APP_URL') ? APP_URL : '';
header('Location: ' . $base . $redirect);
exit;
