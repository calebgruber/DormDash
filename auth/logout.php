<?php
require_once __DIR__ . '/../includes/auth.php';
startAppSession();
session_unset();
session_destroy();
header('Location: ' . APP_URL . '/index.php');
exit;
