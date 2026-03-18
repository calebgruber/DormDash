<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/email.php';
requireLogin();

$user = currentUser();
$db   = getDB();

// Require email verification before allowing payment
if (empty($user['email_verified'])) {
    $_SESSION['flash_error'] = 'Please verify your email address before placing an order. Check your @purchase.edu inbox for the verification link.';
    header('Location: ' . APP_URL . '/auth/verify_notice');
    exit;
}

// Determine order mode: cart (dining_hall), text_order, or prepaid
$isDiningHall = isset($_SESSION['cart'])         && !empty($_SESSION['cart']);
$isPrepaid    = isset($_SESSION['prepaid_order']) && !empty($_SESSION['prepaid_order']);
$isTextOrder  = isset($_SESSION['text_order'])    && !empty($_SESSION['text_order']);

if (!$isDiningHall && !$isPrepaid && !$isTextOrder) {
    header('Location: ' . APP_URL . '/order/');
    exit;
}

$config         = getPaymentConfig();
$tipSuggestions = json_decode($config['tip_suggestions'], true) ?? [0.10, 0.15, 0.20, 0.25];

$errors  = [];
$success = false;
$restaurantId   = 0;
$orderType      = '';
$foodTotal      = 0.0;
$customDesc     = null;
$cart           = [];
$prepaid        = [];
$isBoostOrder   = false;
$boostPayMethod = null;
$stripeClientSecret = null;

if ($isTextOrder && !$isDiningHall) {
    $tOrder       = $_SESSION['text_order'];
    $restaurantId = (int)$tOrder['restaurant_id'];
    $foodTotal    = 0.0;
    $orderType    = 'dining_hall_text';
    $customDesc   = $tOrder['custom_description'];
} elseif ($isDiningHall) {
    $cart         = $_SESSION['cart'];
    $foodTotal    = getCartTotal($cart);
    $orderType    = 'dining_hall';
    $firstItem    = reset($cart);
    $restaurantId = (int)$firstItem['restaurant_id'];
} else {
    $prepaid          = $_SESSION['prepaid_order'];
    $restaurantId     = (int)$prepaid['restaurant_id'];
    $boostPayMethod   = $prepaid['boost_payment_method'] ?? null;  // 'in_person' | 'boost_app' | null
    $isBoostOrder     = !empty($boostPayMethod);
    // If boost_app the food is already paid via the Boost app; only charge fees + tip
    $foodTotal        = ($isBoostOrder && $boostPayMethod === 'boost_app')
                            ? 0.0
                            : (float)($prepaid['estimated_food_total'] ?? 0);
    $orderType        = $isBoostOrder ? 'boost_order' : 'prepaid_pickup';
    $customDesc       = $prepaid['boost_description'] ?? null;
}

$fees              = calculateFees($foodTotal, $config);
$isDiningHallOrder = in_array($orderType, ['dining_hall', 'dining_hall_text'], true);
// For boost_app the food is already paid; don't charge it again via Stripe
$chargeFood        = !$isDiningHallOrder && !($isBoostOrder && $boostPayMethod === 'boost_app');

// Meal swipe value from settings
$mealSwipeValue = max(0.01, (float)getAppSetting('meal_swipe_value', '8.00'));

// Saved locations
$savedLocations = [];
try {
    $lStmt = $db->prepare('SELECT * FROM user_locations WHERE user_id = ? ORDER BY is_default DESC, label ASC');
    $lStmt->execute([$user['id']]);
    $savedLocations = $lStmt->fetchAll();
} catch (Throwable $e) {}

// Meal groups
$matchedGroups = [];
if ($isDiningHall && !empty($cart)) {
    $matchedGroups = checkMealGroups($cart, $restaurantId);
}

// Get restaurant info for meal swipe eligibility
$restaurantInfo = null;
if ($restaurantId) {
    try {
        $rStmt = $db->prepare('SELECT * FROM restaurants WHERE id = ?');
        $rStmt->execute([$restaurantId]);
        $restaurantInfo = $rStmt->fetch();
    } catch (Throwable $e) {}
}
$mealSwipeEligible = !empty($restaurantInfo['meal_swipe_eligible']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        $deliveryAddress  = trim($_POST['delivery_address'] ?? '');
        $customerNotes    = trim($_POST['customer_notes'] ?? '');
        $morPaymentType   = trim($_POST['mor_payment_type'] ?? '');
        $tipChoice        = $_POST['tip_choice'] ?? '0';
        $customTip        = (float)($_POST['custom_tip'] ?? 0);
        $saveLoc          = !empty($_POST['save_location']);
        $locLabel         = trim($_POST['location_label'] ?? '');
        $mealSwipesApplied = max(0, (int)($_POST['meal_swipes_applied'] ?? 0));

        if (empty($deliveryAddress)) $errors[] = 'Delivery address is required.';
        if ($isDiningHallOrder && empty($morPaymentType)) $errors[] = 'Please select a payment type.';

        $tipAmount = 0.0;
        if ($tipChoice === 'custom') {
            $tipAmount = max(0, $customTip);
        } elseif (is_numeric($tipChoice)) {
            $tipAmount = round($foodTotal * (float)$tipChoice, 2);
        }

        // Calculate meal swipe credit
        $mealSwipeCredit = 0.0;
        if ($mealSwipesApplied > 0 && $mealSwipeEligible) {
            $mealSwipeCredit = min(
                round($mealSwipesApplied * $mealSwipeValue, 2),
                $foodTotal + $fees['delivery_fee'] + $fees['service_fee'] + $tipAmount
            );
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                $customerName = $user['name'];
                if ($isPrepaid) $customerName = $prepaid['customer_name'] ?? $user['name'];
                $boostNum     = $isPrepaid ? ($prepaid['boost_order_number'] ?? null) : null;

                // Attempt with meal swipe columns (graceful fallback if migration not run yet)
                try {
                    $stmt = $db->prepare(
                        'INSERT INTO orders
                         (customer_id, restaurant_id, order_type, food_total, delivery_fee, service_fee, tip_amount,
                          mor_payment_type, boost_order_number, boost_payment_method, customer_name,
                          delivery_address, customer_notes, custom_description,
                          meal_swipes_applied, meal_swipe_credit, status, payment_status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", "pending")'
                    );
                    $stmt->execute([
                        $user['id'], $restaurantId, $orderType,
                        $foodTotal, $fees['delivery_fee'], $fees['service_fee'], $tipAmount,
                        $morPaymentType ?: null, $boostNum, $boostPayMethod ?: null, $customerName,
                        $deliveryAddress, $customerNotes ?: null, $customDesc,
                        $mealSwipesApplied, $mealSwipeCredit,
                    ]);
                } catch (PDOException $e2) {
                    // Columns may not exist yet — fallback without them
                    $stmt = $db->prepare(
                        'INSERT INTO orders
                         (customer_id, restaurant_id, order_type, food_total, delivery_fee, service_fee, tip_amount,
                          mor_payment_type, boost_order_number, boost_payment_method, customer_name,
                          delivery_address, customer_notes, custom_description, status, payment_status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", "pending")'
                    );
                    $stmt->execute([
                        $user['id'], $restaurantId, $orderType,
                        $foodTotal, $fees['delivery_fee'], $fees['service_fee'], $tipAmount,
                        $morPaymentType ?: null, $boostNum, $boostPayMethod ?: null, $customerName,
                        $deliveryAddress, $customerNotes ?: null, $customDesc,
                    ]);
                }
                $orderId = (int)$db->lastInsertId();

                // Insert cart items with options
                if ($isDiningHall && !empty($cart)) {
                    foreach ($cart as $item) {
                        $optJson = !empty($item['options']) ? json_encode($item['options']) : null;
                        $db->prepare(
                            'INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price, options_json)
                             VALUES (?, ?, ?, ?, ?)'
                        )->execute([$orderId, $item['item_id'], $item['quantity'], $item['unit_price'], $optJson]);
                    }
                }

                // Save new location if requested
                if ($saveLoc && $deliveryAddress && $locLabel) {
                    try {
                        // Clear old default if setting a new one
                        $db->prepare('UPDATE user_locations SET is_default = 0 WHERE user_id = ?')->execute([$user['id']]);
                        $db->prepare(
                            'INSERT INTO user_locations (user_id, label, address, is_default) VALUES (?, ?, ?, 1)'
                        )->execute([$user['id'], $locLabel, $deliveryAddress]);
                    } catch (Throwable $e) {}
                }

                $db->commit();

                // ── Stripe Embedded Checkout ─────────────────────────────────
                try {
                    require_once __DIR__ . '/../config/stripe.php';
                    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

                    $lineItems = [];
                    if ($chargeFood && $foodTotal > 0) {
                        $lineItems[] = [
                            'price_data' => ['currency' => 'usd', 'unit_amount' => (int)round($foodTotal * 100),
                                             'product_data' => ['name' => 'Food Items']],
                            'quantity' => 1,
                        ];
                    }
                    if ($fees['delivery_fee'] > 0) {
                        $lineItems[] = [
                            'price_data' => ['currency' => 'usd', 'unit_amount' => (int)round($fees['delivery_fee'] * 100),
                                             'product_data' => ['name' => 'Delivery Fee']],
                            'quantity' => 1,
                        ];
                    }
                    if ($fees['service_fee'] > 0) {
                        $lineItems[] = [
                            'price_data' => ['currency' => 'usd', 'unit_amount' => (int)round($fees['service_fee'] * 100),
                                             'product_data' => ['name' => 'Service Fee']],
                            'quantity' => 1,
                        ];
                    }
                    if ($tipAmount > 0) {
                        $lineItems[] = [
                            'price_data' => ['currency' => 'usd', 'unit_amount' => (int)round($tipAmount * 100),
                                             'product_data' => ['name' => 'Tip for Courier']],
                            'quantity' => 1,
                        ];
                    }

                    // Deduct meal swipe credit from line items (work in cents to avoid float issues)
                    $remainingCreditCents = (int)round($mealSwipeCredit * 100);
                    if ($remainingCreditCents > 0) {
                        foreach ($lineItems as &$li) {
                            if ($remainingCreditCents <= 0) break;
                            $amt    = (int)$li['price_data']['unit_amount'];
                            $deduct = min($amt, $remainingCreditCents);
                            $li['price_data']['unit_amount'] = $amt - $deduct;
                            $remainingCreditCents -= $deduct;
                        }
                        unset($li);
                        // Remove zero-amount line items
                        $lineItems = array_values(array_filter($lineItems, fn($li) => $li['price_data']['unit_amount'] > 0));
                    }

                    if (!empty($lineItems)) {
                        $session = \Stripe\Checkout\Session::create([
                            'line_items'  => $lineItems,
                            'mode'        => 'payment',
                            'ui_mode'     => 'embedded',
                            'return_url'  => APP_URL . '/stripe/return?order_id=' . $orderId . '&session_id={CHECKOUT_SESSION_ID}',
                            'customer_email' => $user['email'],
                            'metadata'    => ['order_id' => $orderId],
                            // Wallets (Apple Pay / Google Pay) are auto-enabled via 'card'
                            'payment_method_types' => ['card'],
                        ]);
                        $db->prepare(
                            'UPDATE orders SET stripe_checkout_session_id = ? WHERE id = ?'
                        )->execute([$session->id, $orderId]);
                        // Keep cart in session until payment confirmed; store pending order id
                        $_SESSION['pending_order_id'] = $orderId;
                        $stripeClientSecret = $session->client_secret;
                        // Page continues to render with embedded checkout
                    } else {
                        // No payment needed (e.g. $0 order)
                        $db->prepare(
                            'UPDATE orders SET payment_status = "paid", status = "open", updated_at = NOW() WHERE id = ?'
                        )->execute([$orderId]);
                        $db->prepare('INSERT INTO order_events (order_id, type) VALUES (?, "placed")')->execute([$orderId]);
                        unset($_SESSION['cart'], $_SESSION['prepaid_order'], $_SESSION['text_order']);
                        sendOrderConfirmation(['id' => $orderId, 'food_total' => $foodTotal,
                            'delivery_fee' => $fees['delivery_fee'], 'service_fee' => $fees['service_fee'],
                            'tip_amount' => $tipAmount, 'delivery_address' => $deliveryAddress], $user);
                        header('Location: ' . APP_URL . '/stripe/success?order_id=' . $orderId);
                        exit;
                    }
                } catch (Throwable $e) {
                    logAppError('Stripe session creation failed for order ' . ($orderId ?? 0) . ': ' . $e->getMessage(), 'error');
                    $errors[] = 'Payment setup failed: ' . $e->getMessage();
                }
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                $errors[] = 'Database error. Please try again.';
            }
        }
    }
}

$csrf = generateCsrfToken();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<?php if ($stripeClientSecret): ?>
<!-- ── Stripe Embedded Checkout: Left order summary + Right stripe widget ─── -->
<div class="row g-4">
    <!-- Left: sticky order summary -->
    <div class="col-lg-4 d-none d-lg-block">
        <div class="card shadow-sm border-0 sticky-top" style="top:1.5rem;">
            <div class="card-header fw-bold"><i class="ti ti-receipt me-2 text-primary"></i>Order Summary</div>
            <div class="card-body">
                <?php if ($customDesc): ?>
                    <div class="mb-2 small text-muted"><strong>Order:</strong> <?= htmlspecialchars($customDesc, ENT_QUOTES | ENT_HTML5) ?></div>
                <?php endif; ?>
                <?php if (!empty($cart)): ?>
                    <?php foreach ($cart as $item): ?>
                        <div class="d-flex justify-content-between small mb-1">
                            <span><?= htmlspecialchars($item['name'], ENT_QUOTES | ENT_HTML5) ?> &times;<?= (int)$item['quantity'] ?></span>
                            <span><?= formatMoney((float)$item['unit_price'] * (int)$item['quantity']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <hr class="my-2">
                <?php endif; ?>
                <?php if ($foodTotal > 0): ?>
                    <div class="d-flex justify-content-between small mb-1"><span>Food Total</span><span><?= formatMoney($foodTotal) ?></span></div>
                <?php endif; ?>
                <div class="d-flex justify-content-between small mb-1"><span>Delivery Fee</span><span><?= formatMoney($fees['delivery_fee']) ?></span></div>
                <div class="d-flex justify-content-between small mb-1"><span>Service Fee</span><span><?= formatMoney($fees['service_fee']) ?></span></div>
                <div class="d-flex justify-content-between small mb-1"><span>Tip</span><span><?= formatMoney($tipAmount ?? 0) ?></span></div>
                <?php if (!empty($mealSwipeCredit)): ?>
                    <div class="d-flex justify-content-between small mb-1 text-success"><span>Meal Swipe Credit</span><span>&minus;<?= formatMoney($mealSwipeCredit) ?></span></div>
                <?php endif; ?>
                <hr class="my-2">
                <?php
                $stripTotal = $foodTotal + $fees['delivery_fee'] + $fees['service_fee'] + ($tipAmount ?? 0) - ($mealSwipeCredit ?? 0);
                ?>
                <div class="d-flex justify-content-between fw-bold">
                    <span>Charged via Stripe</span>
                    <span class="text-primary"><?= formatMoney(max(0, $stripTotal)) ?></span>
                </div>
                <p class="text-muted small mt-2 mb-0"><i class="ti ti-shield-check me-1 text-success"></i>Secured by Stripe. Supports Apple Pay &amp; Google Pay.</p>
            </div>
        </div>
    </div>
    <!-- Right: Stripe Embedded Checkout -->
    <div class="col-lg-8">
        <h2 class="fw-bold mb-4"><i class="ti ti-lock me-2 text-primary"></i>Secure Payment</h2>
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div id="stripe-checkout"></div>
            </div>
        </div>
    </div>
</div>
<script src="https://js.stripe.com/v3/"></script>
<script>
(async () => {
    const stripe = Stripe(<?= json_encode(STRIPE_PUBLIC_KEY) ?>);
    const checkout = await stripe.initEmbeddedCheckout({
        clientSecret: <?= json_encode($stripeClientSecret) ?>
    });
    checkout.mount('#stripe-checkout');
})();
</script>
<?php else: ?>

<!-- ── Checkout Form: Left sticky summary + Right form ─────────────────── -->
<div class="row g-4">
    <!-- Left: sticky order summary sidebar -->
    <div class="col-lg-4 order-lg-1 order-2">
        <div class="card shadow-sm border-0 sticky-top" style="top:1.5rem;">
            <div class="card-header fw-bold"><i class="ti ti-receipt me-2 text-primary"></i>Order Summary</div>
            <div class="card-body">
                <?php if ($customDesc): ?>
                    <div class="mb-2 small text-muted"><strong>Order:</strong> <?= htmlspecialchars($customDesc, ENT_QUOTES | ENT_HTML5) ?></div>
                <?php endif; ?>

                <?php if (!empty($cart)): ?>
                    <?php foreach ($cart as $item): ?>
                        <div class="d-flex justify-content-between small mb-1">
                            <span><?= htmlspecialchars($item['name'], ENT_QUOTES | ENT_HTML5) ?> &times;<?= (int)$item['quantity'] ?></span>
                            <span><?= formatMoney((float)$item['unit_price'] * (int)$item['quantity']) ?></span>
                        </div>
                        <?php if (!empty($item['options'])): ?>
                            <div class="text-muted small ms-2 mb-1">
                                <?php foreach ($item['options'] as $og => $choice): ?>
                                    <div><?= htmlspecialchars($og, ENT_QUOTES | ENT_HTML5) ?>: <?= htmlspecialchars(is_array($choice) ? implode(', ', $choice) : $choice, ENT_QUOTES | ENT_HTML5) ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <hr class="my-2">
                <?php endif; ?>

                <?php if ($foodTotal > 0): ?>
                    <div class="d-flex justify-content-between small mb-1"><span>Food Total</span><span><?= formatMoney($foodTotal) ?></span></div>
                <?php endif; ?>
                <div class="d-flex justify-content-between small mb-1"><span>Delivery Fee</span><span><?= formatMoney($fees['delivery_fee']) ?></span></div>
                <div class="d-flex justify-content-between small mb-1"><span>Service Fee</span><span><?= formatMoney($fees['service_fee']) ?></span></div>
                <div class="d-flex justify-content-between small mb-1"><span>Tip</span><span id="sidebar-tip">—</span></div>
                <div class="d-flex justify-content-between small mb-1 text-success <?= ($mealSwipeEligible) ? '' : 'd-none' ?>" id="sidebar-swipe-row">
                    <span>Meal Swipe Credit</span><span id="sidebar-swipe">—</span>
                </div>
                <hr class="my-2">
                <div class="d-flex justify-content-between fw-bold">
                    <span>You Pay via Stripe</span>
                    <span class="text-primary" id="sidebar-grand">—</span>
                </div>
                <?php if ($isDiningHallOrder): ?>
                    <p class="text-muted small mt-2 mb-0">Food paid by your MORE card at the dining hall.</p>
                <?php endif; ?>
                <p class="text-muted small mt-2 mb-0"><i class="ti ti-shield-check me-1 text-success"></i>Secured by Stripe &mdash; Apple Pay &amp; Google Pay supported.</p>
            </div>
        </div>
    </div>

    <!-- Right: form -->
    <div class="col-lg-8 order-lg-2 order-1">
        <h2 class="fw-bold mb-4"><i class="ti ti-credit-card me-2 text-primary"></i>Checkout</h2>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger py-2">
                <i class="ti ti-alert-circle me-1"></i><?= htmlspecialchars($err, ENT_QUOTES | ENT_HTML5) ?>
            </div>
        <?php endforeach; ?>

        <?php if ($isTextOrder && !$isDiningHall && $customDesc): ?>
            <div class="alert alert-info mb-4">
                <strong><i class="ti ti-pencil me-1"></i>Text Order:</strong> <?= htmlspecialchars($customDesc, ENT_QUOTES | ENT_HTML5) ?>
            </div>
        <?php endif; ?>

        <?php if ($isBoostOrder): ?>
            <div class="alert alert-info mb-4">
                <strong><i class="ti ti-shopping-cart me-1"></i>Boost Order</strong>
                <?php if ($boostPayMethod === 'boost_app'): ?>
                    — Your food is already paid via the Boost app. You are only charged fees &amp; tip here.
                <?php else: ?>
                    — Your courier will place this order in person and pay for it.
                <?php endif; ?>
                <?php if ($customDesc): ?>
                    <div class="mt-1 small"><strong>Order:</strong> <?= htmlspecialchars($customDesc, ENT_QUOTES | ENT_HTML5) ?></div>
                <?php endif; ?>
                <?php if (!empty($prepaid['boost_order_number'])): ?>
                    <div class="mt-1 small"><strong>Boost Order #:</strong> <?= htmlspecialchars($prepaid['boost_order_number'], ENT_QUOTES | ENT_HTML5) ?></div>
                <?php endif; ?>
                <?php if (!empty($prepaid['customer_name']) && $boostPayMethod === 'boost_app'): ?>
                    <div class="mt-1 small"><strong>Name on order:</strong> <?= htmlspecialchars($prepaid['customer_name'], ENT_QUOTES | ENT_HTML5) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($matchedGroups)): ?>
            <div class="alert alert-success mb-4">
                <i class="ti ti-star me-2"></i><strong>Meal Swipe Eligible!</strong>
                Your cart qualifies for: <?= implode(', ', array_map(fn($mg) => htmlspecialchars($mg['name'], ENT_QUOTES | ENT_HTML5), $matchedGroups)) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <!-- Delivery address -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-bold"><i class="ti ti-map-pin me-2 text-primary"></i>Delivery Address</div>
                <div class="card-body">
                    <?php if (!empty($savedLocations)): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Saved Locations</label>
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <?php foreach ($savedLocations as $loc): ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm saved-loc-btn"
                                            data-address="<?= htmlspecialchars($loc['address'], ENT_QUOTES | ENT_HTML5) ?>">
                                        <i class="ti ti-map-pin me-1"></i><?= htmlspecialchars($loc['label'], ENT_QUOTES | ENT_HTML5) ?>
                                        <?php if ($loc['is_default']): ?><span class="badge bg-purple ms-1">Default</span><?php endif; ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="delivery_address">Enter Address</label>
                        <input type="text" class="form-control" id="delivery_address" name="delivery_address"
                               placeholder="e.g. Central Hall, Room 204"
                               value="<?= htmlspecialchars($_POST['delivery_address'] ?? ($user['dorm'] ? $user['dorm'] . ' Hall' : ''), ENT_QUOTES | ENT_HTML5) ?>" required>
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input" id="save_location" name="save_location"
                               <?= !empty($_POST['save_location']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="save_location">Save this address</label>
                    </div>
                    <div id="loc-label-wrap" class="<?= empty($_POST['save_location']) ? 'd-none' : '' ?>">
                        <input type="text" class="form-control form-control-sm" name="location_label"
                               placeholder="Label (e.g. My Dorm, Science Building)"
                               value="<?= htmlspecialchars($_POST['location_label'] ?? '', ENT_QUOTES | ENT_HTML5) ?>">
                    </div>
                </div>
            </div>

            <?php if ($isDiningHallOrder): ?>
            <!-- MORE card payment type -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-bold"><i class="ti ti-id-badge-2 me-2 text-primary"></i>MORE Card Payment</div>
                <div class="card-body">
                    <p class="text-muted small mb-3">How should the courier pay for your food?</p>
                    <div class="row g-3">
                        <?php
                        $payTypes = ['Meal Swipe', 'Dining Dollars', 'Cash'];
                        $selPay   = $_POST['mor_payment_type'] ?? 'Meal Swipe';
                        ?>
                        <?php foreach ($payTypes as $pt): ?>
                            <div class="col-auto">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="mor_payment_type"
                                           id="pay-<?= md5($pt) ?>" value="<?= htmlspecialchars($pt) ?>"
                                           <?= $selPay === $pt ? 'checked' : '' ?> required>
                                    <label class="form-check-label" for="pay-<?= md5($pt) ?>">
                                        <?= htmlspecialchars($pt) ?>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($mealSwipeEligible): ?>
            <!-- Meal swipe counter -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-bold"><i class="ti ti-id-badge-2 me-2 text-success"></i>Apply Meal Swipes</div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Each meal swipe covers <strong><?= formatMoney($mealSwipeValue) ?></strong> of your order total. The remainder will be charged via card.
                    </p>
                    <div class="d-flex align-items-center gap-3">
                        <label class="fw-semibold mb-0" for="meal_swipes_applied">Meal Swipes to Apply:</label>
                        <div class="input-group" style="max-width:140px;">
                            <button type="button" class="btn btn-outline-secondary" id="swipe-dec">
                                <i class="ti ti-minus"></i>
                            </button>
                            <input type="number" class="form-control text-center" id="meal_swipes_applied"
                                   name="meal_swipes_applied" min="0" max="20" value="<?= (int)($_POST['meal_swipes_applied'] ?? 0) ?>">
                            <button type="button" class="btn btn-outline-secondary" id="swipe-inc">
                                <i class="ti ti-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div id="swipe-preview" class="mt-2 text-success small fw-semibold"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tip -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-bold"><i class="ti ti-heart me-2 text-primary"></i>Tip for Your Courier</div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?php foreach ($tipSuggestions as $tip): ?>
                            <div class="form-check">
                                <input class="form-check-input tip-radio" type="radio" name="tip_choice"
                                       id="tip-<?= (int)($tip*100) ?>" value="<?= (float)$tip ?>"
                                       <?= ($_POST['tip_choice'] ?? '0.15') == $tip ? 'checked' : '' ?>>
                                <label class="form-check-label" for="tip-<?= (int)($tip*100) ?>">
                                    <?= ($tip > 0) ? round($tip * 100) . '%' : 'No tip' ?>
                                    <?php if ($foodTotal > 0): ?><span class="text-muted small">(<?= formatMoney(round($foodTotal * $tip, 2)) ?>)</span><?php endif; ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        <div class="form-check">
                            <input class="form-check-input tip-radio" type="radio" name="tip_choice"
                                   id="tip-custom" value="custom"
                                   <?= ($_POST['tip_choice'] ?? '') === 'custom' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="tip-custom">Custom</label>
                        </div>
                    </div>
                    <div id="custom-tip-wrap" class="<?= ($_POST['tip_choice'] ?? '') === 'custom' ? '' : 'd-none' ?>">
                        <div class="input-group" style="max-width:200px">
                            <span class="input-group-text">$</span>
                            <input type="number" class="form-control" id="custom_tip" name="custom_tip"
                                   min="0" step="0.01" placeholder="0.00"
                                   value="<?= htmlspecialchars($_POST['custom_tip'] ?? '', ENT_QUOTES | ENT_HTML5) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-bold"><i class="ti ti-note me-2 text-primary"></i>Notes <span class="fw-normal text-muted">(optional)</span></div>
                <div class="card-body">
                    <textarea class="form-control" name="customer_notes" rows="2"
                              placeholder="Any special instructions for the courier?"><?= htmlspecialchars($_POST['customer_notes'] ?? '', ENT_QUOTES | ENT_HTML5) ?></textarea>
                </div>
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="ti ti-lock me-1"></i>Confirm &amp; Pay
                </button>
            </div>
        </form>
    </div><!-- /.col -->
</div><!-- /.row -->

<script>
var baseFees     = <?= json_encode($fees['delivery_fee'] + $fees['service_fee'] + ($isDiningHallOrder ? 0 : $foodTotal)) ?>;
var foodTotal    = <?= json_encode($foodTotal) ?>;
var swipeValue   = <?= json_encode($mealSwipeValue) ?>;
var swipeEligible = <?= json_encode($mealSwipeEligible) ?>;

function getTip() {
    var tipChoice = document.querySelector('.tip-radio:checked')?.value;
    if (tipChoice === 'custom') return parseFloat(document.getElementById('custom_tip')?.value) || 0;
    if (tipChoice) return Math.round(foodTotal * parseFloat(tipChoice) * 100) / 100;
    return 0;
}

function getSwipeCredit() {
    if (!swipeEligible) return 0;
    var n = parseInt(document.getElementById('meal_swipes_applied')?.value) || 0;
    var credit = Math.round(n * swipeValue * 100) / 100;
    var total  = baseFees + getTip();
    return Math.min(credit, total);
}

function updateTotal() {
    var tip    = getTip();
    var credit = getSwipeCredit();
    var grand  = Math.max(0, baseFees + tip - credit);

    var tipEl = document.getElementById('sidebar-tip');
    if (tipEl) tipEl.textContent = '$' + tip.toFixed(2);

    var swipeRowEl = document.getElementById('sidebar-swipe-row');
    var swipeEl    = document.getElementById('sidebar-swipe');
    if (swipeEl) {
        swipeEl.textContent = credit > 0 ? '-$' + credit.toFixed(2) : '—';
        if (swipeRowEl) swipeRowEl.classList.toggle('d-none', credit <= 0 && !swipeEligible);
    }

    var grandEl = document.getElementById('sidebar-grand');
    if (grandEl) grandEl.textContent = '$' + grand.toFixed(2);

    var previewEl = document.getElementById('swipe-preview');
    if (previewEl && swipeEligible) {
        var n = parseInt(document.getElementById('meal_swipes_applied')?.value) || 0;
        if (n > 0) {
            previewEl.textContent = n + ' swipe' + (n > 1 ? 's' : '') + ' covers $' + credit.toFixed(2) + '; you pay $' + grand.toFixed(2) + ' via card.';
        } else {
            previewEl.textContent = '';
        }
    }
}

document.querySelectorAll('.tip-radio').forEach(function(r) {
    r.addEventListener('change', function() {
        document.getElementById('custom-tip-wrap').classList.toggle('d-none', this.value !== 'custom');
        updateTotal();
    });
});
document.getElementById('custom_tip')?.addEventListener('input', updateTotal);
document.getElementById('meal_swipes_applied')?.addEventListener('input', updateTotal);

// Swipe increment / decrement buttons
document.getElementById('swipe-inc')?.addEventListener('click', function() {
    var el = document.getElementById('meal_swipes_applied');
    if (el) { el.value = Math.min(20, (parseInt(el.value) || 0) + 1); updateTotal(); }
});
document.getElementById('swipe-dec')?.addEventListener('click', function() {
    var el = document.getElementById('meal_swipes_applied');
    if (el) { el.value = Math.max(0, (parseInt(el.value) || 0) - 1); updateTotal(); }
});

updateTotal();

// Saved location quick-select
document.querySelectorAll('.saved-loc-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('delivery_address').value = this.dataset.address;
    });
});

// Save location checkbox
document.getElementById('save_location')?.addEventListener('change', function() {
    document.getElementById('loc-label-wrap').classList.toggle('d-none', !this.checked);
});
</script>

<?php endif; // end stripe/form conditional ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>