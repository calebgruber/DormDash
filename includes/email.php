<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/functions.php';

function getEmailHeaders(): string {
    $fromAddr = getAppSetting('email_from_address', 'no-reply@purchase.edu');
    $fromName = getAppSetting('email_from_name',    APP_NAME);
    $encoded  = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    return "From: {$encoded} <{$fromAddr}>\r\n"
         . "Reply-To: {$fromAddr}\r\n"
         . "X-Mailer: PHP/" . phpversion() . "\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n";
}

function sendVerificationEmail(array $user, string $token): bool {
    $to         = $user['email'];
    $subject    = APP_NAME . ' - Verify Your Email Address';
    $verifyUrl  = APP_URL . '/auth/verify?token=' . urlencode($token);
    $name       = htmlspecialchars($user['name'], ENT_QUOTES | ENT_HTML5);

    $message = "Hello {$name},\n\n"
        . "Thank you for registering with " . APP_NAME . "!\n\n"
        . "Please verify your email address by clicking the link below:\n"
        . $verifyUrl . "\n\n"
        . "If you did not create an account, you can safely ignore this email.\n\n"
        . "Thanks,\n" . APP_NAME . " Team\nSUNY Purchase";

    return mail($to, $subject, $message, getEmailHeaders());
}

function sendOrderConfirmation(array $order, array $user): bool {
    $to      = $user['email'];
    $subject = APP_NAME . ' - Order #' . $order['id'] . ' Confirmed';
    $name    = htmlspecialchars($user['name'], ENT_QUOTES | ENT_HTML5);

    $total = number_format(
        (float)$order['food_total'] + (float)$order['delivery_fee'] +
        (float)$order['service_fee'] + (float)$order['tip_amount'],
        2
    );

    $message = "Hello {$name},\n\n"
        . "Your order #{$order['id']} has been placed successfully!\n\n"
        . "Order Total: \${$total}\n"
        . "Delivery Address: " . ($order['delivery_address'] ?? 'N/A') . "\n\n"
        . "A courier will accept your order shortly.\n\n"
        . "Track your order: " . APP_URL . "/order/history\n\n"
        . "Thanks,\n" . APP_NAME . " Team";

    return mail($to, $subject, $message, getEmailHeaders());
}

function sendOrderStatusUpdate(array $order, array $user, string $newStatus): bool {
    $statusLabels = [
        'open'           => 'Waiting for Courier',
        'accepted'       => 'Courier Accepted',
        'card_collected' => 'Card Collected',
        'food_collected' => 'Food Collected',
        'delivered'      => 'Delivered',
        'cancelled'      => 'Cancelled',
    ];
    $statusLabel = $statusLabels[$newStatus] ?? ucfirst($newStatus);
    $to          = $user['email'];
    $subject     = APP_NAME . ' - Order #' . $order['id'] . ' Status Update: ' . $statusLabel;
    $name        = htmlspecialchars($user['name'], ENT_QUOTES | ENT_HTML5);

    $message = "Hello {$name},\n\n"
        . "Your order #{$order['id']} status has been updated to: {$statusLabel}\n\n"
        . "Track your order: " . APP_URL . "/order/history\n\n"
        . "Thanks,\n" . APP_NAME . " Team";

    return mail($to, $subject, $message, getEmailHeaders());
}
