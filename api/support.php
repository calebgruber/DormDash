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

    // Mark messages as read
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

    // Check if the OTHER party is currently typing (typed within last 5 seconds)
    $otherIsAdmin  = $isAdmin ? 0 : 1;
    $typingStmt = $db->prepare(
        'SELECT typed_at FROM support_typing
         WHERE order_id = ? AND is_admin = ?
           AND typed_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND)'
    );
    $typingStmt->execute([$orderId, $otherIsAdmin]);
    $otherTyping = (bool)$typingStmt->fetch();

    echo json_encode(['success' => true, 'messages' => $messages, 'other_typing' => $otherTyping]);
    exit;
}

// ── POST: typing indicator ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'typing') {
    // No CSRF needed — low-stakes indicator; verified by session auth above
    try {
        $db->prepare(
            'INSERT INTO support_typing (order_id, is_admin, typed_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE typed_at = NOW()'
        )->execute([$orderId, $isAdmin ? 1 : 0]);
    } catch (Throwable $e) {
        // Table might not exist yet — ignore silently
    }
    echo json_encode(['success' => true]);
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

    // Clear typing indicator
    try {
        $db->prepare('DELETE FROM support_typing WHERE order_id = ? AND is_admin = ?')
           ->execute([$orderId, $isAdmin ? 1 : 0]);
    } catch (Throwable $e) {}

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

