<?php
/**
 * Stripe Embedded Checkout return URL
 *
 * After the customer completes (or cancels) payment in the embedded form,
 * Stripe redirects here with ?order_id=X&session_id=cs_...
 * We verify payment status and update the order accordingly.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email.php';
require_once __DIR__ . '/../config/stripe.php';
requireLogin();

$orderId   = (int)($_GET['order_id'] ?? 0);
$sessionId = trim($_GET['session_id'] ?? '');

if ($orderId <= 0) {
    header('Location: ' . APP_URL . '/order/');
    exit;
}

$db   = getDB();
$user = currentUser();

$stmt = $db->prepare('SELECT * FROM orders WHERE id = ? AND customer_id = ?');
$stmt->execute([$orderId, $user['id']]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: ' . APP_URL . '/order/');
    exit;
}

// Already paid (webhook fired before we got here)
if ($order['payment_status'] === 'paid') {
    header('Location: ' . APP_URL . '/stripe/success?order_id=' . $orderId);
    exit;
}

// Verify session status directly from Stripe
if ($sessionId) {
    try {
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        $session = \Stripe\Checkout\Session::retrieve($sessionId);

        if ($session->status === 'complete' && $session->payment_status === 'paid') {
            $db->prepare(
                'UPDATE orders SET payment_status = "paid", status = "open",
                 stripe_payment_intent_id = ?, updated_at = NOW()
                 WHERE id = ? AND payment_status = "pending"'
            )->execute([$session->payment_intent, $orderId]);

            $db->prepare('INSERT INTO order_events (order_id, type) VALUES (?, "placed")')->execute([$orderId]);

            // Clear cart and pending order from session on successful payment
            unset($_SESSION['cart'], $_SESSION['prepaid_order'], $_SESSION['text_order'], $_SESSION['pending_order_id']);

            // Send confirmation email
            $uStmt = $db->prepare('SELECT * FROM users WHERE id = ?');
            $uStmt->execute([$order['customer_id']]);
            $customer = $uStmt->fetch();
            if ($customer) {
                sendOrderConfirmation($order, $customer);
            }

            header('Location: ' . APP_URL . '/stripe/success?order_id=' . $orderId);
            exit;
        }

        // Payment open/expired — redirect to cancel page
        if ($session->status === 'expired') {
            $db->prepare(
                'UPDATE orders SET status = "cancelled", updated_at = NOW() WHERE id = ? AND status = "pending"'
            )->execute([$orderId]);
            header('Location: ' . APP_URL . '/stripe/cancel');
            exit;
        }
    } catch (Throwable $e) {
        // Fall through to generic success page; webhook will handle the update
    }
}

// Uncertain state — redirect to success; webhook will set status when it fires
header('Location: ' . APP_URL . '/stripe/success?order_id=' . $orderId);
exit;
