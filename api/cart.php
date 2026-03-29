<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

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

$action   = trim($_POST['action'] ?? '');
$itemId   = (int)($_POST['item_id'] ?? 0);
$quantity = (int)($_POST['quantity'] ?? 1);

$validActions = ['add', 'remove', 'update', 'clear'];
if (!in_array($action, $validActions, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$db = getDB();

if ($action === 'clear') {
    unset($_SESSION['cart']);
    echo json_encode(['success' => true, 'cart_count' => 0, 'cart_total' => '0.00', 'message' => 'Cart cleared']);
    exit;
}

if ($action === 'add') {
    if ($itemId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid item']);
        exit;
    }

    // Validate item exists and is active
    $stmt = $db->prepare(
        'SELECT mi.*, mc.restaurant_id, mc.id AS category_id
         FROM menu_items mi
         JOIN menu_categories mc ON mc.id = mi.category_id
         WHERE mi.id = ? AND mi.active = 1'
    );
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();

    if (!$item) {
        echo json_encode(['success' => false, 'error' => 'Item not found or unavailable']);
        exit;
    }

    // Parse options (JSON from customization modal)
    $optionsRaw  = trim($_POST['options'] ?? '');
    $options     = ($optionsRaw && $optionsRaw !== '{}') ? json_decode($optionsRaw, true) : [];
    $extraPrice  = (float)($_POST['extra_price'] ?? 0);
    $unitPrice   = (float)$item['base_price'] + $extraPrice;

    // Generate a cart key: item ID alone if no options, composite if options present
    $cartKey = $options ? $itemId . '_' . substr(md5($optionsRaw), 0, 8) : (string)$itemId;

    // Validate same restaurant
    if (!empty($_SESSION['cart'])) {
        $firstItem = reset($_SESSION['cart']);
        if ((int)$firstItem['restaurant_id'] !== (int)$item['restaurant_id']) {
            echo json_encode(['success' => false, 'error' => 'You can only order from one restaurant at a time. Please clear your cart first.']);
            exit;
        }
    }

    // Build option label for display
    $optLabel = '';
    if ($options) {
        $parts = [];
        foreach ($options as $grp) {
            $choiceNames = array_column($grp['choices'], 'name');
            $parts[] = $grp['name'] . ': ' . implode(', ', $choiceNames);
        }
        $optLabel = implode(' | ', $parts);
    }

    if (isset($_SESSION['cart'][$cartKey]) && !$options) {
        $_SESSION['cart'][$cartKey]['quantity'] += 1;
    } else {
        $_SESSION['cart'][$cartKey] = [
            'item_id'             => $itemId,
            'name'                => $item['name'],
            'unit_price'          => $unitPrice,
            'quantity'            => 1,
            'category_id'         => (int)$item['category_id'],
            'options'             => $options ?: null,
            'options_label'       => $optLabel,
            'restaurant_id'       => (int)$item['restaurant_id'],
        ];
    }

    $cartCount = getCartItemCount($_SESSION['cart']);
    $cartTotal = number_format(getCartTotal($_SESSION['cart']), 2);
    echo json_encode([
        'success'    => true,
        'cart_count' => $cartCount,
        'cart_total' => $cartTotal,
        'message'    => htmlspecialchars($item['name'], ENT_QUOTES | ENT_HTML5) . ' added to cart!',
    ]);
    exit;
}

if ($action === 'remove') {
    $cartKey = trim($_POST['cart_key'] ?? (string)$itemId);
    if (isset($_SESSION['cart'][$cartKey])) {
        unset($_SESSION['cart'][$cartKey]);
    }
    $cartCount = getCartItemCount($_SESSION['cart']);
    $cartTotal = number_format(getCartTotal($_SESSION['cart']), 2);
    echo json_encode(['success' => true, 'cart_count' => $cartCount, 'cart_total' => $cartTotal, 'message' => 'Item removed']);
    exit;
}

if ($action === 'update') {
    $cartKey = trim($_POST['cart_key'] ?? (string)$itemId);
    if ($quantity <= 0) {
        unset($_SESSION['cart'][$cartKey]);
    } elseif (isset($_SESSION['cart'][$cartKey])) {
        $_SESSION['cart'][$cartKey]['quantity'] = $quantity;
    }
    $cartCount = getCartItemCount($_SESSION['cart']);
    $cartTotal = number_format(getCartTotal($_SESSION['cart']), 2);
    echo json_encode(['success' => true, 'cart_count' => $cartCount, 'cart_total' => $cartTotal, 'message' => 'Cart updated']);
    exit;
}
