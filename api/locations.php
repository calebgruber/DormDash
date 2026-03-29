<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
requireLogin();

$user   = currentUser();
$db     = getDB();
$action = trim($_REQUEST['action'] ?? '');

if ($action === 'list' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('SELECT * FROM user_locations WHERE user_id = ? ORDER BY is_default DESC, label ASC');
    $stmt->execute([$user['id']]);
    echo json_encode(['success' => true, 'locations' => $stmt->fetchAll()]);
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if ($action === 'add') {
    $label   = trim($_POST['label']   ?? '');
    $address = trim($_POST['address'] ?? '');
    $setDef  = !empty($_POST['is_default']);

    if (!$label || !$address) {
        echo json_encode(['success' => false, 'error' => 'Label and address are required']);
        exit;
    }

    if ($setDef) {
        $db->prepare('UPDATE user_locations SET is_default = 0 WHERE user_id = ?')->execute([$user['id']]);
    }

    $db->prepare(
        'INSERT INTO user_locations (user_id, label, address, is_default) VALUES (?, ?, ?, ?)'
    )->execute([$user['id'], $label, $address, $setDef ? 1 : 0]);

    echo json_encode(['success' => true, 'message' => 'Location saved']);
    exit;
}

if ($action === 'delete') {
    $locId = (int)($_POST['location_id'] ?? 0);
    if ($locId > 0) {
        $db->prepare('DELETE FROM user_locations WHERE id = ? AND user_id = ?')->execute([$locId, $user['id']]);
        echo json_encode(['success' => true, 'message' => 'Location deleted']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid location ID']);
    }
    exit;
}

if ($action === 'set_default') {
    $locId = (int)($_POST['location_id'] ?? 0);
    if ($locId > 0) {
        $db->prepare('UPDATE user_locations SET is_default = 0 WHERE user_id = ?')->execute([$user['id']]);
        $db->prepare('UPDATE user_locations SET is_default = 1 WHERE id = ? AND user_id = ?')->execute([$locId, $user['id']]);
        echo json_encode(['success' => true, 'message' => 'Default location updated']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid location ID']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
