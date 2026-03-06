<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

requireLogin();
$user = currentUser();
$db   = getDB();

$action  = trim($_REQUEST['action'] ?? '');
$orderId = (int)($_REQUEST['order_id'] ?? 0);

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
    exit;
}

// Verify the user can access this order
$oStmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
$oStmt->execute([$orderId]);
$order = $oStmt->fetch();

if (!$order) {
    echo json_encode(['success' => false, 'error' => 'Order not found']);
    exit;
}

$isAdmin    = $user['role'] === 'admin';
$isCustomer = (int)$order['customer_id'] === (int)$user['id'];
if (!$isAdmin && !$isCustomer) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// ── GET messages ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' || $action === 'get') {
    $mStmt = $db->prepare(
        'SELECT sm.*, u.name AS sender_name
         FROM support_messages sm
         JOIN users u ON u.id = sm.sender_id
         WHERE sm.order_id = ?
         ORDER BY sm.created_at ASC'
    );
    $mStmt->execute([$orderId]);
    $messages = $mStmt->fetchAll();

    // Mark messages as read (admin reads customer messages; customer reads admin messages)
    if ($isAdmin) {
        $db->prepare(
            'UPDATE support_messages SET read_at = NOW()
             WHERE order_id = ? AND is_from_admin = 0 AND read_at IS NULL'
        )->execute([$orderId]);
    } else {
        $db->prepare(
            'UPDATE support_messages SET read_at = NOW()
             WHERE order_id = ? AND is_from_admin = 1 AND read_at IS NULL'
        )->execute([$orderId]);
    }

    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}

// ── POST: send message ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'send') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $message = trim($_POST['message'] ?? '');
    if (empty($message)) {
        echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
        exit;
    }
    if (strlen($message) > 2000) {
        echo json_encode(['success' => false, 'error' => 'Message too long (max 2000 chars)']);
        exit;
    }

    $db->prepare(
        'INSERT INTO support_messages (order_id, sender_id, message, is_from_admin)
         VALUES (?, ?, ?, ?)'
    )->execute([$orderId, $user['id'], $message, $isAdmin ? 1 : 0]);

    $newId = (int)$db->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => [
            'id'            => $newId,
            'message'       => htmlspecialchars($message, ENT_QUOTES | ENT_HTML5),
            'is_from_admin' => $isAdmin ? 1 : 0,
            'sender_name'   => htmlspecialchars($user['name'], ENT_QUOTES | ENT_HTML5),
            'created_at'    => date('Y-m-d H:i:s'),
        ],
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
