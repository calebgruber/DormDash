<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/stripe_api.php';
require_once __DIR__ . '/../includes/email.php';
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

requireLogin();

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$orderId   = (int)($_POST['order_id'] ?? 0);
$eventType = trim($_POST['event_type'] ?? '');
$note      = trim($_POST['note'] ?? '');

$validEvents = ['accept', 'card_collected', 'food_collected', 'delivered', 'cancel'];
if ($orderId <= 0 || !in_array($eventType, $validEvents, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

$user = currentUser();
$db   = getDB();

// Load order
$stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    echo json_encode(['success' => false, 'error' => 'Order not found']);
    exit;
}

$responseMessage = '';

try {
    $db->beginTransaction();

    if ($eventType === 'accept') {
        if ($order['status'] !== 'open' || $order['payment_status'] !== 'paid') {
            throw new RuntimeException('Order is not available for acceptance.');
        }
        if ($user['role'] !== 'courier' || !$user['courier_approved']) {
            throw new RuntimeException('You must be an approved courier to accept orders.');
        }

        $db->prepare('UPDATE orders SET status = "accepted", courier_id = ?, updated_at = NOW() WHERE id = ?')
           ->execute([$user['id'], $orderId]);
        $db->prepare('INSERT INTO order_events (order_id, type, courier_id) VALUES (?, "accepted", ?)')
           ->execute([$orderId, $user['id']]);

        $responseMessage = 'Order accepted! Check your courier dashboard.';

    } elseif ($eventType === 'card_collected') {
        if ($order['status'] !== 'accepted') {
            throw new RuntimeException('Cannot collect card in current order status.');
        }
        if ((int)$order['courier_id'] !== $user['id']) {
            throw new RuntimeException('You are not the assigned courier for this order.');
        }

        $db->prepare('UPDATE orders SET status = "card_collected", updated_at = NOW() WHERE id = ?')
           ->execute([$orderId]);
        $db->prepare('INSERT INTO order_events (order_id, type, courier_id, note) VALUES (?, "card_collected", ?, ?)')
           ->execute([$orderId, $user['id'], $note ?: null]);

        $responseMessage = 'Card collected! Head to the restaurant.';

    } elseif ($eventType === 'food_collected') {
        if (!in_array($order['status'], ['accepted', 'card_collected'], true)) {
            throw new RuntimeException('Cannot collect food in current order status.');
        }
        if ((int)$order['courier_id'] !== $user['id']) {
            throw new RuntimeException('You are not the assigned courier for this order.');
        }

        $db->prepare('UPDATE orders SET status = "food_collected", updated_at = NOW() WHERE id = ?')
           ->execute([$orderId]);
        $db->prepare('INSERT INTO order_events (order_id, type, courier_id, note) VALUES (?, "food_collected", ?, ?)')
           ->execute([$orderId, $user['id'], $note ?: null]);

        $responseMessage = 'Food collected! Deliver to the customer.';

    } elseif ($eventType === 'delivered') {
        if ($order['status'] !== 'food_collected') {
            throw new RuntimeException('Cannot mark delivered in current order status.');
        }
        if ((int)$order['courier_id'] !== $user['id']) {
            throw new RuntimeException('You are not the assigned courier for this order.');
        }

        // Handle optional photo upload
        $photoPath = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $photoPath = uploadFile($_FILES['photo'], UPLOAD_DIR);
            if ($photoPath === null) {
                throw new RuntimeException('Invalid photo upload. Please use a valid image.');
            }
        }

        $db->prepare('UPDATE orders SET status = "delivered", updated_at = NOW() WHERE id = ?')
           ->execute([$orderId]);
        $db->prepare('INSERT INTO order_events (order_id, type, courier_id, note, photo_path) VALUES (?, "delivered", ?, ?, ?)')
           ->execute([$orderId, $user['id'], $note ?: null, $photoPath]);

        // Trigger Stripe transfer to courier if they have a stripe_account_id
        $cStmt = $db->prepare('SELECT stripe_account_id FROM users WHERE id = ?');
        $cStmt->execute([$user['id']]);
        $courier = $cStmt->fetch();

        if ($courier && !empty($courier['stripe_account_id']) && !empty($order['stripe_payment_intent_id'])) {
            $tipCents = (int)round((float)$order['tip_amount'] * 100);
            if ($tipCents > 0) {
                $transfer = stripeCreateTransfer([
                    'amount'         => $tipCents,
                    'currency'       => 'usd',
                    'destination'    => $courier['stripe_account_id'],
                    'transfer_group' => 'ORDER_' . $orderId,
                ]);
                // Log transfer failure but don't block delivery confirmation
                if (!empty($transfer['error'])) {
                    error_log('Stripe transfer failed for order ' . $orderId . ': ' . ($transfer['error']['message'] ?? 'unknown'));
                }
            }
        }

        // Send status update email
        $uStmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $uStmt->execute([$order['customer_id']]);
        $customer = $uStmt->fetch();
        if ($customer) {
            $freshOrder = $db->prepare('SELECT * FROM orders WHERE id = ?');
            $freshOrder->execute([$orderId]);
            sendOrderStatusUpdate($freshOrder->fetch(), $customer, 'delivered');
        }

        $responseMessage = 'Order marked as delivered!';

    } elseif ($eventType === 'cancel') {
        if ($user['role'] !== 'admin') {
            throw new RuntimeException('Only admins can cancel orders.');
        }
        if (in_array($order['status'], ['delivered', 'cancelled'], true)) {
            throw new RuntimeException('Cannot cancel a completed or already cancelled order.');
        }

        $db->prepare('UPDATE orders SET status = "cancelled", updated_at = NOW() WHERE id = ?')
           ->execute([$orderId]);
        $db->prepare('INSERT INTO order_events (order_id, type, note) VALUES (?, "cancelled", ?)')
           ->execute([$orderId, $note ?: null]);

        $responseMessage = 'Order cancelled.';
    }

    $db->commit();
    echo json_encode(['success' => true, 'message' => $responseMessage]);

} catch (RuntimeException $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'error' => 'Database error. Please try again.']);
}
