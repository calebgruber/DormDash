<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/email.php';
requireLogin();

$user = currentUser();
$db   = getDB();

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
    $prepaid      = $_SESSION['prepaid_order'];
    $restaurantId = (int)$prepaid['restaurant_id'];
    $foodTotal    = (float)$prepaid['estimated_food_total'];
    $orderType    = 'prepaid_pickup';
}

$fees              = calculateFees($foodTotal, $config);
$isDiningHallOrder = in_array($orderType, ['dining_hall', 'dining_hall_text'], true);

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        $deliveryAddress = trim($_POST['delivery_address'] ?? '');
        $customerNotes   = trim($_POST['customer_notes'] ?? '');
        $morPaymentType  = trim($_POST['mor_payment_type'] ?? '');
        $tipChoice       = $_POST['tip_choice'] ?? '0';
        $customTip       = (float)($_POST['custom_tip'] ?? 0);
        $saveLoc         = !empty($_POST['save_location']);
        $locLabel        = trim($_POST['location_label'] ?? '');

        if (empty($deliveryAddress)) $errors[] = 'Delivery address is required.';
        if ($isDiningHallOrder && empty($morPaymentType)) $errors[] = 'Please select a payment type.';

        $tipAmount = 0.0;
        if ($tipChoice === 'custom') {
            $tipAmount = max(0, $customTip);
        } elseif (is_numeric($tipChoice)) {
            $tipAmount = round($foodTotal * (float)$tipChoice, 2);
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                $customerName = $user['name'];
                if ($isPrepaid) $customerName = $prepaid['customer_name'] ?? $user['name'];
                $boostNum     = $isPrepaid ? ($prepaid['boost_order_number'] ?? null) : null;

                $stmt = $db->prepare(
                    'INSERT INTO orders
                     (customer_id, restaurant_id, order_type, food_total, delivery_fee, service_fee, tip_amount,
                      mor_payment_type, boost_order_number, customer_name, delivery_address, customer_notes,
                      custom_description, payment_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")'
                );
                $stmt->execute([
                    $user['id'], $restaurantId, $orderType,
                    $foodTotal, $fees['delivery_fee'], $fees['service_fee'], $tipAmount,
                    $morPaymentType ?: null, $boostNum, $customerName,
                    $deliveryAddress, $customerNotes ?: null,
                    $customDesc,
                ]);
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

                // Try Stripe checkout
                try {
                    require_once __DIR__ . '/../includes/stripe_api.php';
                    require_once __DIR__ . '/../config/stripe.php';
                    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

                    $chargeAmount = $fees['delivery_fee'] + $fees['service_fee'] + $tipAmount;
                    if (!$isDiningHallOrder) $chargeAmount += $foodTotal;

                    $lineItems = [];
                    if (!$isDiningHallOrder && $foodTotal > 0) {
                        $lineItems[] = [
                            'price_data' => [
                                'currency'     => 'usd',
                                'unit_amount'  => (int)round($foodTotal * 100),
                                'product_data' => ['name' => 'Food Items'],
                            ],
                            'quantity' => 1,
                        ];
                    }
                    if ($fees['delivery_fee'] > 0) {
                        $lineItems[] = [
                            'price_data' => [
                                'currency'     => 'usd',
                                'unit_amount'  => (int)round($fees['delivery_fee'] * 100),
                                'product_data' => ['name' => 'Delivery Fee'],
                            ],
                            'quantity' => 1,
                        ];
                    }
                    if ($fees['service_fee'] > 0) {
                        $lineItems[] = [
                            'price_data' => [
                                'currency'     => 'usd',
                                'unit_amount'  => (int)round($fees['service_fee'] * 100),
                                'product_data' => ['name' => 'Service Fee'],
                            ],
                            'quantity' => 1,
                        ];
                    }
                    if ($tipAmount > 0) {
                        $lineItems[] = [
                            'price_data' => [
                                'currency'     => 'usd',
                                'unit_amount'  => (int)round($tipAmount * 100),
                                'product_data' => ['name' => 'Tip for Courier'],
                            ],
                            'quantity' => 1,
                        ];
                    }

                    if (!empty($lineItems)) {
                        $session = \Stripe\Checkout\Session::create([
                            'payment_method_types' => ['card'],
                            'line_items'           => $lineItems,
                            'mode'                 => 'payment',
                            'success_url'          => APP_URL . '/stripe/success?order_id=' . $orderId,
                            'cancel_url'           => APP_URL . '/stripe/cancel',
                            'metadata'             => ['order_id' => $orderId],
                        ]);
                        $db->prepare(
                            'UPDATE orders SET stripe_checkout_session_id = ? WHERE id = ?'
                        )->execute([$session->id, $orderId]);
                        // Clear session cart
                        unset($_SESSION['cart'], $_SESSION['prepaid_order'], $_SESSION['text_order']);
                        header('Location: ' . $session->url);
                        exit;
                    }

                    // No payment needed (0 total)
                    $db->prepare('UPDATE orders SET payment_status = "paid" WHERE id = ?')->execute([$orderId]);
                    $db->prepare('INSERT INTO order_events (order_id, type) VALUES (?, "placed")')->execute([$orderId]);
                    unset($_SESSION['cart'], $_SESSION['prepaid_order'], $_SESSION['text_order']);
                    sendOrderConfirmation(['id' => $orderId, 'food_total' => $foodTotal, 'delivery_fee' => $fees['delivery_fee'], 'service_fee' => $fees['service_fee'], 'tip_amount' => $tipAmount, 'delivery_address' => $deliveryAddress], $user);
                    header('Location: ' . APP_URL . '/stripe/success?order_id=' . $orderId);
                    exit;

                } catch (Throwable $e) {
                    $db->rollBack();
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

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-7">
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

            <!-- Order Summary -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-bold"><i class="ti ti-receipt me-2 text-primary"></i>Order Summary</div>
                <div class="card-body">
                    <?php if ($customDesc): ?>
                        <div class="mb-2"><strong>Order:</strong> <?= htmlspecialchars($customDesc, ENT_QUOTES | ENT_HTML5) ?></div>
                    <?php endif; ?>
                    <?php if ($foodTotal > 0): ?>
                        <div class="d-flex justify-content-between mb-1"><span>Food Total</span><span><?= formatMoney($foodTotal) ?></span></div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between mb-1"><span>Delivery Fee</span><span><?= formatMoney($fees['delivery_fee']) ?></span></div>
                    <div class="d-flex justify-content-between mb-1"><span>Service Fee</span><span><?= formatMoney($fees['service_fee']) ?></span></div>
                    <div class="d-flex justify-content-between mb-1"><span>Tip</span><span id="tip-display">—</span></div>
                    <hr>
                    <div class="d-flex justify-content-between fw-bold">
                        <span>You pay via Stripe</span>
                        <span id="grand-total"><?= formatMoney($fees['delivery_fee'] + $fees['service_fee']) ?></span>
                    </div>
                    <?php if ($isDiningHallOrder): ?>
                        <p class="text-muted small mt-2 mb-0">Food cost is paid by your MORE card at the dining hall.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="ti ti-lock me-1"></i>Confirm &amp; Pay
                </button>
            </div>
        </form>
    </div>
</div>

<script>
var baseFees = <?= json_encode($fees['delivery_fee'] + $fees['service_fee'] + ($isDiningHallOrder ? 0 : $foodTotal)) ?>;
var foodTotal = <?= json_encode($foodTotal) ?>;

document.querySelectorAll('.tip-radio').forEach(function(r) {
    r.addEventListener('change', function() {
        document.getElementById('custom-tip-wrap').classList.toggle('d-none', this.value !== 'custom');
        updateTotal();
    });
});
document.getElementById('custom_tip')?.addEventListener('input', updateTotal);

function updateTotal() {
    var tipChoice = document.querySelector('.tip-radio:checked')?.value;
    var tip = 0;
    if (tipChoice === 'custom') {
        tip = parseFloat(document.getElementById('custom_tip')?.value) || 0;
    } else if (tipChoice) {
        tip = Math.round(foodTotal * parseFloat(tipChoice) * 100) / 100;
    }
    document.getElementById('tip-display').textContent = '$' + tip.toFixed(2);
    document.getElementById('grand-total').textContent = '$' + (baseFees + tip).toFixed(2);
}
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
