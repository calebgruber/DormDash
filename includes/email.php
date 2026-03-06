<?php
require_once __DIR__ . '/../config/app.php';

function sendVerificationEmail(array $user, string $token): bool {
    $to = $user['email'];
    $subject = APP_NAME . ' - Verify Your Email Address';
    $verifyUrl = APP_URL . '/auth/verify.php?token=' . urlencode($token);
    $name = htmlspecialchars($user['name'], ENT_QUOTES | ENT_HTML5);

    $message = "Hello {$name},\n\n"
        . "Thank you for registering with " . APP_NAME . "!\n\n"
        . "Please verify your email address by clicking the link below:\n"
        . $verifyUrl . "\n\n"
        . "If you did not create an account, you can safely ignore this email.\n\n"
        . "Thanks,\n" . APP_NAME . " Team\nSUNY Purchase";

    $headers = "From: no-reply@purchase.edu\r\n"
        . "Reply-To: no-reply@purchase.edu\r\n"
        . "X-Mailer: PHP/" . phpversion();

    return mail($to, $subject, $message, $headers);
}

function sendOrderConfirmation(array $order, array $user): bool {
    $to = $user['email'];
    $subject = APP_NAME . ' - Order #' . $order['id'] . ' Confirmed';
    $name = htmlspecialchars($user['name'], ENT_QUOTES | ENT_HTML5);
    $ordersUrl = APP_URL . '/customer/orders.php';

    $total = number_format(
        (float)$order['food_total'] + (float)$order['delivery_fee'] +
        (float)$order['service_fee'] + (float)$order['tip_amount'],
        2
    );

    $message = "Hello {$name},\n\n"
        . "Your order #" . $order['id'] . " has been placed successfully!\n\n"
        . "Order Total: \${$total}\n"
        . "Delivery Address: " . $order['delivery_address'] . "\n\n"
        . "A courier will accept your order shortly.\n\n"
        . "Track your order: " . $ordersUrl . "\n\n"
        . "Thanks,\n" . APP_NAME . " Team";

    $headers = "From: no-reply@purchase.edu\r\n"
        . "Reply-To: no-reply@purchase.edu\r\n"
        . "X-Mailer: PHP/" . phpversion();

    return mail($to, $subject, $message, $headers);
}

function sendOrderStatusUpdate(array $order, array $user, string $newStatus): bool {
    $to = $user['email'];
    $statusLabels = [
        'open'           => 'Waiting for Courier',
        'accepted'       => 'Courier Accepted',
        'card_collected' => 'Card Collected',
        'food_collected' => 'Food Collected',
        'delivered'      => 'Delivered',
        'cancelled'      => 'Cancelled',
    ];
    $statusLabel = $statusLabels[$newStatus] ?? ucfirst($newStatus);
    $subject = APP_NAME . ' - Order #' . $order['id'] . ' Status Update: ' . $statusLabel;
    $name = htmlspecialchars($user['name'], ENT_QUOTES | ENT_HTML5);
    $ordersUrl = APP_URL . '/customer/orders.php';

    $message = "Hello {$name},\n\n"
        . "Your order #" . $order['id'] . " status has been updated to: {$statusLabel}\n\n"
        . "Track your order: " . $ordersUrl . "\n\n"
        . "Thanks,\n" . APP_NAME . " Team";

    $headers = "From: no-reply@purchase.edu\r\n"
        . "Reply-To: no-reply@purchase.edu\r\n"
        . "X-Mailer: PHP/" . phpversion();

    return mail($to, $subject, $message, $headers);
}
