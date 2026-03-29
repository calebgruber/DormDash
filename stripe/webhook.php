<?php
// No session/auth required - authenticated via Stripe webhook signature
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/stripe_api.php';
require_once __DIR__ . '/../includes/email.php';
require_once __DIR__ . '/../config/stripe.php';

header('Content-Type: text/plain');

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Verify signature if secret is set
if (STRIPE_WEBHOOK_SECRET !== '') {
    if (!stripeVerifyWebhookSignature($payload, $sigHeader, STRIPE_WEBHOOK_SECRET)) {
        http_response_code(400);
        echo 'Invalid signature';
        exit;
    }
}

$event = json_decode($payload, true);
if (!$event || !isset($event['type'])) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

if ($event['type'] === 'checkout.session.completed') {
    $session         = $event['data']['object'];
    $checkoutId      = $session['id'] ?? '';
    $paymentIntentId = $session['payment_intent'] ?? '';
    $orderId         = (int)($session['metadata']['order_id'] ?? 0);

    $db = getDB();

    // Find order by metadata or by checkout session id
    if ($orderId > 0) {
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? AND payment_status = "pending"');
        $stmt->execute([$orderId]);
    } else {
        $stmt = $db->prepare('SELECT * FROM orders WHERE stripe_checkout_session_id = ? AND payment_status = "pending"');
        $stmt->execute([$checkoutId]);
    }
    $order = $stmt->fetch();

    if ($order) {
        $db->prepare(
            'UPDATE orders SET payment_status = "paid", status = "open", stripe_payment_intent_id = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$paymentIntentId, $order['id']]);

        $db->prepare('INSERT INTO order_events (order_id, type) VALUES (?, "placed")')->execute([$order['id']]);

        // Send confirmation email
        $uStmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $uStmt->execute([$order['customer_id']]);
        $user = $uStmt->fetch();
        if ($user) {
            sendOrderConfirmation($order, $user);
        }
    }
}

echo 'OK';
