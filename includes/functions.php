<?php
require_once __DIR__ . '/db.php';

function formatMoney(float $amount): string {
    return '$' . number_format($amount, 2);
}

function calculateFees(float $foodTotal, array $config): array {
    if ($config['delivery_fee_type'] === 'percent') {
        $deliveryFee = $foodTotal * ((float)$config['delivery_fee_value'] / 100);
    } else {
        $deliveryFee = (float)$config['delivery_fee_value'];
    }

    if ($config['service_fee_type'] === 'percent') {
        $serviceFee = $foodTotal * ((float)$config['service_fee_value'] / 100);
    } else {
        $serviceFee = (float)$config['service_fee_value'];
    }

    return [
        'delivery_fee' => round($deliveryFee, 2),
        'service_fee'  => round($serviceFee, 2),
    ];
}

function sanitize(string $input): string {
    return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function uploadFile(array $fileArray, string $destDir): ?string {
    if (!isset($fileArray['tmp_name']) || $fileArray['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($fileArray['tmp_name']);

    if (strpos($mimeType, 'image/') !== 0) {
        return null;
    }

    $ext = pathinfo($fileArray['name'], PATHINFO_EXTENSION);
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower($ext);
    if (!in_array($ext, $allowedExts, true)) {
        return null;
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = rtrim($destDir, '/') . '/' . $filename;

    if (!move_uploaded_file($fileArray['tmp_name'], $destPath)) {
        return null;
    }

    return $filename;
}

function getPaymentConfig(): array {
    $db = getDB();
    $stmt = $db->query('SELECT * FROM payment_config LIMIT 1');
    $config = $stmt->fetch();
    if (!$config) {
        return [
            'id'                => 1,
            'delivery_fee_type' => 'flat',
            'delivery_fee_value'=> 2.00,
            'service_fee_type'  => 'percent',
            'service_fee_value' => 5.00,
            'tip_suggestions'   => '[0.10, 0.15, 0.20, 0.25]',
        ];
    }
    return $config;
}

function formatOrderStatus(string $status): string {
    $map = [
        'open'           => 'Waiting for Courier',
        'accepted'       => 'Courier Accepted',
        'card_collected' => 'Card Collected',
        'food_collected' => 'Food Collected',
        'delivered'      => 'Delivered',
        'cancelled'      => 'Cancelled',
    ];
    return $map[$status] ?? ucfirst($status);
}

function getOrderStatusBadgeClass(string $status): string {
    $map = [
        'open'           => 'bg-warning text-dark',
        'accepted'       => 'bg-info text-dark',
        'card_collected' => 'bg-primary',
        'food_collected' => 'bg-primary',
        'delivered'      => 'bg-success',
        'cancelled'      => 'bg-danger',
    ];
    return $map[$status] ?? 'bg-secondary';
}

function getCartTotal(array $cart): float {
    $total = 0.0;
    foreach ($cart as $item) {
        $total += (float)$item['unit_price'] * (int)$item['quantity'];
    }
    return $total;
}

function getCartItemCount(array $cart): int {
    $count = 0;
    foreach ($cart as $item) {
        $count += (int)$item['quantity'];
    }
    return $count;
}
