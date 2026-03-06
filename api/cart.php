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
        'SELECT mi.*, mc.restaurant_id
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

    // Validate same restaurant
    if (!empty($_SESSION['cart'])) {
        $firstItem = reset($_SESSION['cart']);
        if ((int)$firstItem['restaurant_id'] !== (int)$item['restaurant_id']) {
            echo json_encode(['success' => false, 'error' => 'You can only order from one restaurant at a time. Please clear your cart first.']);
            exit;
        }
    }

    if (isset($_SESSION['cart'][$itemId])) {
        $_SESSION['cart'][$itemId]['quantity'] += 1;
    } else {
        $_SESSION['cart'][$itemId] = [
            'item_id'             => $itemId,
            'name'                => $item['name'],
            'unit_price'          => (float)$item['base_price'],
            'quantity'            => 1,
            'customizations_text' => '',
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
    if (isset($_SESSION['cart'][$itemId])) {
        unset($_SESSION['cart'][$itemId]);
    }
    $cartCount = getCartItemCount($_SESSION['cart']);
    $cartTotal = number_format(getCartTotal($_SESSION['cart']), 2);
    echo json_encode(['success' => true, 'cart_count' => $cartCount, 'cart_total' => $cartTotal, 'message' => 'Item removed']);
    exit;
}

if ($action === 'update') {
    if ($quantity <= 0) {
        unset($_SESSION['cart'][$itemId]);
    } elseif (isset($_SESSION['cart'][$itemId])) {
        $_SESSION['cart'][$itemId]['quantity'] = $quantity;
    }
    $cartCount = getCartItemCount($_SESSION['cart']);
    $cartTotal = number_format(getCartTotal($_SESSION['cart']), 2);
    echo json_encode(['success' => true, 'cart_count' => $cartCount, 'cart_total' => $cartTotal, 'message' => 'Cart updated']);
    exit;
}
