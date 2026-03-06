<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stripe_api.php';
requireLogin();

$user    = currentUser();
$orderId = (int)($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    header('Location: ' . APP_URL . '/order/');
    exit;
}

$db   = getDB();
$stmt = $db->prepare(
    'SELECT o.*, r.name AS restaurant_name
     FROM orders o
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.id = ? AND o.customer_id = ?'
);
$stmt->execute([$orderId, $user['id']]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: ' . APP_URL . '/order/');
    exit;
}

if ($order['payment_status'] !== 'pending') {
    // Already paid or failed
    header('Location: ' . APP_URL . '/order/history.php');
    exit;
}

$totalCents = (int)round(
    ((float)$order['food_total'] + (float)$order['delivery_fee'] +
     (float)$order['service_fee'] + (float)$order['tip_amount']) * 100
);

if ($totalCents <= 0) {
    $totalCents = 50; // Stripe minimum
}

// Build Stripe Checkout Session
$successUrl = APP_URL . '/stripe/success.php?order_id=' . $orderId;
$cancelUrl  = APP_URL . '/stripe/cancel.php?order_id=' . $orderId;

$params = [
    'payment_method_types[0]'               => 'card',
    'mode'                                  => 'payment',
    'success_url'                           => $successUrl,
    'cancel_url'                            => $cancelUrl,
    'customer_email'                        => $user['email'],
    'metadata[order_id]'                    => $orderId,
    'line_items[0][price_data][currency]'   => 'usd',
    'line_items[0][price_data][unit_amount]'=> $totalCents,
    'line_items[0][price_data][product_data][name]' => 'DormDash Order #' . $orderId . ' – ' . $order['restaurant_name'],
    'line_items[0][quantity]'               => 1,
];

$session = stripeCreateCheckoutSession($params);

if (!empty($session['url'])) {
    $db->prepare('UPDATE orders SET stripe_checkout_session_id = ? WHERE id = ?')
       ->execute([$session['id'], $orderId]);
    header('Location: ' . $session['url']);
    exit;
}

// Stripe error
$errorMsg = $session['error']['message'] ?? 'Could not create payment session.';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="alert alert-danger">
            <strong>Payment Error:</strong> <?= htmlspecialchars($errorMsg, ENT_QUOTES | ENT_HTML5) ?>
        </div>
        <a href="<?= APP_URL ?>/order/checkout.php" class="btn btn-primary">Try Again</a>
        <a href="<?= APP_URL ?>/order/" class="btn btn-outline-secondary ms-2">Go Home</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
