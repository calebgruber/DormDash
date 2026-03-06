<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/email.php';
requireLogin();

$user = currentUser();
$db   = getDB();

// Determine order type
$isDiningHall = isset($_SESSION['cart']) && !empty($_SESSION['cart']);
$isPrepaid    = isset($_SESSION['prepaid_order']) && !empty($_SESSION['prepaid_order']);

if (!$isDiningHall && !$isPrepaid) {
    header('Location: ' . APP_URL . '/order/');
    exit;
}

$config = getPaymentConfig();
$tipSuggestions = json_decode($config['tip_suggestions'], true) ?? [0.10, 0.15, 0.20, 0.25];

$errors  = [];
$success = false;

// Get restaurant id
$restaurantId = 0;
$orderType    = '';
$foodTotal    = 0.0;

if ($isDiningHall) {
    $cart      = $_SESSION['cart'];
    $foodTotal = getCartTotal($cart);
    $orderType = 'dining_hall';
    // All items must be from same restaurant
    $firstItem = reset($cart);
    $restaurantId = (int)$firstItem['restaurant_id'];
} else {
    $prepaid      = $_SESSION['prepaid_order'];
    $restaurantId = (int)$prepaid['restaurant_id'];
    $foodTotal    = (float)$prepaid['estimated_food_total'];
    $orderType    = 'prepaid_pickup';
}

$fees = calculateFees($foodTotal, $config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        $deliveryAddress = trim($_POST['delivery_address'] ?? '');
        $customerNotes   = trim($_POST['customer_notes'] ?? '');
        $morPaymentType  = trim($_POST['mor_payment_type'] ?? '');
        $tipChoice       = $_POST['tip_choice'] ?? '0';
        $customTip       = (float)($_POST['custom_tip'] ?? 0);

        if (empty($deliveryAddress)) $errors[] = 'Delivery address is required.';
        if ($isDiningHall && empty($morPaymentType)) $errors[] = 'Please select a payment type.';

        // Calculate tip
        $tipAmount = 0.0;
        if ($tipChoice === 'custom') {
            $tipAmount = max(0, $customTip);
        } elseif (is_numeric($tipChoice)) {
            $tipAmount = round($foodTotal * (float)$tipChoice, 2);
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                $customerName = $isDiningHall ? $user['name'] : ($prepaid['customer_name'] ?? $user['name']);
                $boostNum     = $isPrepaid ? ($prepaid['boost_order_number'] ?? null) : null;

                $stmt = $db->prepare(
                    'INSERT INTO orders
                     (customer_id, restaurant_id, order_type, food_total, delivery_fee, service_fee, tip_amount,
                      mor_payment_type, boost_order_number, customer_name, delivery_address, customer_notes, payment_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")'
                );
                $stmt->execute([
                    $user['id'], $restaurantId, $orderType,
                    $foodTotal, $fees['delivery_fee'], $fees['service_fee'], $tipAmount,
                    $morPaymentType ?: null, $boostNum, $customerName,
                    $deliveryAddress, $customerNotes ?: null,
                ]);
                $orderId = (int)$db->lastInsertId();

                if ($isDiningHall) {
                    foreach ($cart as $item) {
                        $itemStmt = $db->prepare(
                            'INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price) VALUES (?, ?, ?, ?)'
                        );
                        $itemStmt->execute([$orderId, $item['item_id'], $item['quantity'], $item['unit_price']]);
                    }
                }

                $db->commit();

                // Clear cart / prepaid session data
                if ($isDiningHall) unset($_SESSION['cart']);
                if ($isPrepaid)    unset($_SESSION['prepaid_order']);

                header('Location: ' . APP_URL . '/stripe/checkout.php?order_id=' . $orderId);
                exit;
            } catch (PDOException $e) {
                $db->rollBack();
                $errors[] = 'Database error. Please try again.';
            }
        }
    }
}

$csrf = generateCsrfToken();
$grandTotal = $foodTotal + $fees['delivery_fee'] + $fees['service_fee'];

// Get restaurant name
$rStmt = $db->prepare('SELECT name FROM restaurants WHERE id = ?');
$rStmt->execute([$restaurantId]);
$restaurantRow = $rStmt->fetch();
$restaurantName = $restaurantRow ? $restaurantRow['name'] : 'Unknown';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<h2 class="mb-4">💳 Checkout</h2>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err, ENT_QUOTES | ENT_HTML5) ?></div>
<?php endforeach; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white fw-bold">Order Summary — <?= htmlspecialchars($restaurantName, ENT_QUOTES | ENT_HTML5) ?></div>
            <div class="card-body p-0">
                <?php if ($isDiningHall): ?>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead class="table-light"><tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Price</th></tr></thead>
                            <tbody>
                                <?php foreach ($cart as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['name'], ENT_QUOTES | ENT_HTML5) ?></td>
                                        <td class="text-center"><?= (int)$item['quantity'] ?></td>
                                        <td class="text-end"><?= formatMoney((float)$item['unit_price'] * (int)$item['quantity']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="p-3">
                        <p class="mb-1"><strong>Customer Name:</strong> <?= htmlspecialchars($prepaid['customer_name'], ENT_QUOTES | ENT_HTML5) ?></p>
                        <?php if (!empty($prepaid['boost_order_number'])): ?>
                            <p class="mb-1"><strong>Boost Order #:</strong> <?= htmlspecialchars($prepaid['boost_order_number'], ENT_QUOTES | ENT_HTML5) ?></p>
                        <?php endif; ?>
                        <p class="mb-0"><strong>Estimated Total:</strong> <?= formatMoney($foodTotal) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" action="" id="checkout-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <?php if ($isDiningHall): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-bold">Payment Method (MOR Card)</div>
                <div class="card-body">
                    <?php $morTypes = ['2 Meal Swipes', '1 Meal + Flex', 'Card', 'SuperFlex']; ?>
                    <?php foreach ($morTypes as $morType): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="mor_payment_type"
                                   id="mor_<?= htmlspecialchars(preg_replace('/\W+/', '_', $morType)) ?>"
                                   value="<?= htmlspecialchars($morType, ENT_QUOTES | ENT_HTML5) ?>"
                                   <?= (($_POST['mor_payment_type'] ?? '') === $morType) ? 'checked' : '' ?> required>
                            <label class="form-check-label" for="mor_<?= htmlspecialchars(preg_replace('/\W+/', '_', $morType)) ?>">
                                <?= htmlspecialchars($morType, ENT_QUOTES | ENT_HTML5) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-bold">Delivery Details</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="delivery_address">Delivery Address</label>
                        <input type="text" class="form-control" id="delivery_address" name="delivery_address"
                               placeholder="Dorm Building + Room Number"
                               value="<?= htmlspecialchars($_POST['delivery_address'] ?? $user['dorm'] ?? '', ENT_QUOTES | ENT_HTML5) ?>" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold" for="customer_notes">Special Instructions <span class="text-muted">(optional)</span></label>
                        <textarea class="form-control" id="customer_notes" name="customer_notes" rows="2"
                                  placeholder="Allergies, customizations, etc."><?= htmlspecialchars($_POST['customer_notes'] ?? '', ENT_QUOTES | ENT_HTML5) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-bold">Tip for Courier</div>
                <div class="card-body">
                    <div class="row g-2 mb-3">
                        <?php foreach ($tipSuggestions as $pct): ?>
                            <?php $pctLabel = ($pct * 100) . '%'; $pctVal = number_format((float)$pct, 2); ?>
                            <div class="col-3">
                                <input type="radio" class="btn-check" name="tip_choice" id="tip_<?= str_replace('.', '_', $pctVal) ?>"
                                       value="<?= $pctVal ?>"
                                       <?= (($_POST['tip_choice'] ?? '0.15') === $pctVal) ? 'checked' : '' ?>>
                                <label class="btn btn-outline-secondary w-100" for="tip_<?= str_replace('.', '_', $pctVal) ?>">
                                    <?= $pctLabel ?><br>
                                    <small><?= formatMoney(round($foodTotal * (float)$pct, 2)) ?></small>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        <div class="col-3">
                            <input type="radio" class="btn-check" name="tip_choice" id="tip_custom" value="custom"
                                   <?= (($_POST['tip_choice'] ?? '') === 'custom') ? 'checked' : '' ?>>
                            <label class="btn btn-outline-secondary w-100" for="tip_custom">Custom</label>
                        </div>
                    </div>
                    <div id="custom-tip-wrapper" class="<?= (($_POST['tip_choice'] ?? '') === 'custom') ? '' : 'd-none' ?>">
                        <label class="form-label" for="custom_tip">Custom Tip Amount ($)</label>
                        <input type="number" class="form-control" id="custom_tip" name="custom_tip"
                               min="0" step="0.01" value="<?= htmlspecialchars($_POST['custom_tip'] ?? '0', ENT_QUOTES | ENT_HTML5) ?>">
                    </div>
                </div>
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-success btn-lg">Place Order &amp; Pay</button>
            </div>
        </form>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm border-0 sticky-top" style="top: 70px;">
            <div class="card-header bg-dark text-white fw-bold">Order Total</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Food Total</span><span><?= formatMoney($foodTotal) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Delivery Fee</span><span><?= formatMoney($fees['delivery_fee']) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Service Fee</span><span><?= formatMoney($fees['service_fee']) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Tip</span><span id="tip-display">$0.00</span>
                </div>
                <hr>
                <div class="d-flex justify-content-between fw-bold fs-5">
                    <span>Grand Total</span><span id="grand-total"><?= formatMoney($grandTotal) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var foodTotal = <?= $foodTotal ?>;
var baseTotal = <?= $foodTotal + $fees['delivery_fee'] + $fees['service_fee'] ?>;

document.getElementById('tip_custom').addEventListener('change', function() {
    document.getElementById('custom-tip-wrapper').classList.remove('d-none');
});

document.querySelectorAll('input[name="tip_choice"]').forEach(function(r) {
    if (r.value !== 'custom') {
        r.addEventListener('change', function() {
            document.getElementById('custom-tip-wrapper').classList.add('d-none');
            var tip = Math.round(foodTotal * parseFloat(this.value) * 100) / 100;
            document.getElementById('tip-display').textContent = '$' + tip.toFixed(2);
            document.getElementById('grand-total').textContent = '$' + (baseTotal + tip).toFixed(2);
        });
    }
});

document.getElementById('custom_tip').addEventListener('input', function() {
    var tip = parseFloat(this.value) || 0;
    document.getElementById('tip-display').textContent = '$' + tip.toFixed(2);
    document.getElementById('grand-total').textContent = '$' + (baseTotal + tip).toFixed(2);
});

// Initialize tip display
(function() {
    var checked = document.querySelector('input[name="tip_choice"]:checked');
    if (checked && checked.value !== 'custom') {
        var tip = Math.round(foodTotal * parseFloat(checked.value) * 100) / 100;
        document.getElementById('tip-display').textContent = '$' + tip.toFixed(2);
        document.getElementById('grand-total').textContent = '$' + (baseTotal + tip).toFixed(2);
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
