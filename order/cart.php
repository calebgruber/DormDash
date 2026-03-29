<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$cart = $_SESSION['cart'] ?? [];
$csrf = generateCsrfToken();

// Check for a pending (unpaid) order
$pendingOrderId = $_SESSION['pending_order_id'] ?? null;
$pendingOrder   = null;
if ($pendingOrderId) {
    try {
        $db = getDB();
        $user = currentUser();
        $pStmt = $db->prepare(
            'SELECT o.*, r.name AS restaurant_name FROM orders o
             LEFT JOIN restaurants r ON r.id = o.restaurant_id
             WHERE o.id = ? AND o.customer_id = ? AND o.payment_status = "pending" AND o.status = "pending"'
        );
        $pStmt->execute([$pendingOrderId, $user['id']]);
        $pendingOrder = $pStmt->fetch() ?: null;
    } catch (Throwable $e) {}
}

// Check meal groups
$matchedGroups = [];
if (!empty($cart)) {
    $firstItem = reset($cart);
    $matchedGroups = checkMealGroups($cart, (int)$firstItem['restaurant_id']);
}
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold"><i class="ti ti-shopping-cart me-2 text-primary"></i>Your Cart</h2>
</div>

<?php if (empty($cart)): ?>
    <?php if ($pendingOrder): ?>
        <div class="alert alert-warning mb-3">
            <i class="ti ti-clock me-2"></i>
            <strong>You have an incomplete payment.</strong>
            Order #<?= (int)$pendingOrder['id'] ?> from <?= htmlspecialchars($pendingOrder['restaurant_name'] ?? 'Unknown', ENT_QUOTES | ENT_HTML5) ?> is waiting for payment.
            <a href="<?= APP_URL ?>/order/checkout" class="btn btn-warning btn-sm ms-2">Complete Payment</a>
        </div>
    <?php endif; ?>
    <div class="alert alert-info"><i class="ti ti-info-circle me-2"></i>Your cart is empty.</div>
    <a href="<?= APP_URL ?>/order/" class="btn btn-primary">
        <i class="ti ti-arrow-left me-1"></i> Browse Restaurants
    </a>
<?php else: ?>
    <?php if (!empty($matchedGroups)): ?>
        <div class="alert alert-success mb-4">
            <i class="ti ti-star me-2"></i>
            <strong>Meal Swipe Eligible!</strong>
            Your cart qualifies for
            <?php foreach ($matchedGroups as $mg): ?>
                <strong><?= htmlspecialchars($mg['name'], ENT_QUOTES | ENT_HTML5) ?></strong>
            <?php endforeach; ?>
            &mdash; you can apply a meal swipe at checkout.
        </div>
    <?php endif; ?>

    <div id="cart-message" class="alert d-none mb-3"></div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-center" style="width:130px;">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Subtotal</th>
                            <th class="text-center" style="width:50px;"></th>
                        </tr>
                    </thead>
                    <tbody id="cart-tbody">
                        <?php foreach ($cart as $cartKey => $item): ?>
                            <?php $rowId = preg_replace('/[^a-z0-9_-]/i', '-', $cartKey); ?>
                            <tr id="row-<?= htmlspecialchars($rowId) ?>">
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($item['name'], ENT_QUOTES | ENT_HTML5) ?></div>
                                    <?php if (!empty($item['options_label'])): ?>
                                        <small class="text-muted"><i class="ti ti-adjustments-horizontal me-1"></i><?= htmlspecialchars($item['options_label'], ENT_QUOTES | ENT_HTML5) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="input-group input-group-sm" style="max-width:110px; margin:auto;">
                                        <button class="btn btn-outline-secondary qty-btn" type="button"
                                                data-cart-key="<?= htmlspecialchars($cartKey) ?>"
                                                data-action="decrease">-</button>
                                        <input type="number" class="form-control text-center qty-input"
                                               value="<?= (int)$item['quantity'] ?>" min="1" max="99"
                                               data-cart-key="<?= htmlspecialchars($cartKey) ?>" readonly>
                                        <button class="btn btn-outline-secondary qty-btn" type="button"
                                                data-cart-key="<?= htmlspecialchars($cartKey) ?>"
                                                data-action="increase">+</button>
                                    </div>
                                </td>
                                <td class="text-end"><?= formatMoney((float)$item['unit_price']) ?></td>
                                <td class="text-end subtotal-cell"
                                    data-cart-key="<?= htmlspecialchars($cartKey) ?>"
                                    data-unit-price="<?= (float)$item['unit_price'] ?>">
                                    <?= formatMoney((float)$item['unit_price'] * (int)$item['quantity']) ?>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-outline-danger btn-sm remove-btn"
                                            data-cart-key="<?= htmlspecialchars($cartKey) ?>">
                                        <i class="ti ti-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="3" class="text-end">Food Total:</td>
                            <td class="text-end" id="food-total"><?= formatMoney(getCartTotal($cart)) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex gap-3 flex-wrap">
        <a href="<?= APP_URL ?>/order/checkout" class="btn btn-primary btn-lg">
            <i class="ti ti-arrow-right me-1"></i>Proceed to Checkout
        </a>
        <a href="<?= APP_URL ?>/order/" class="btn btn-outline-secondary">Continue Shopping</a>
    </div>

    <script>
    var csrfToken = <?= json_encode($csrf) ?>;

    function cartRequest(data, cb) {
        var fd = new FormData();
        Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
        fd.append('csrf_token', csrfToken);
        fetch('/api/cart', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(cb)
            .catch(function() { showMsg('Request failed.', false); });
    }

    function showMsg(msg, ok) {
        var el = document.getElementById('cart-message');
        el.textContent = msg;
        el.className = 'alert mb-3 ' + (ok ? 'alert-success' : 'alert-danger');
        setTimeout(function() { el.classList.add('d-none'); }, 3000);
    }

    document.querySelectorAll('.qty-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var key    = this.dataset.cartKey;
            var action = this.dataset.action;
            var inp    = document.querySelector('.qty-input[data-cart-key="' + key + '"]');
            var qty    = parseInt(inp.value, 10);
            qty = (action === 'increase') ? qty + 1 : Math.max(1, qty - 1);

            cartRequest({ action: 'update', item_id: 0, cart_key: key, quantity: qty }, function(data) {
                if (data.success) {
                    inp.value = qty;
                    var sc = document.querySelector('.subtotal-cell[data-cart-key="' + key + '"]');
                    if (sc) {
                        var up = parseFloat(sc.dataset.unitPrice || 0);
                        sc.textContent = '$' + (up * qty).toFixed(2);
                    }
                    document.getElementById('food-total').textContent = '$' + data.cart_total;
                } else {
                    showMsg(data.error || 'Update failed.', false);
                }
            });
        });
    });

    document.querySelectorAll('.remove-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var key   = this.dataset.cartKey;
            var rowId = key.replace(/[^a-z0-9_-]/gi, '-');
            cartRequest({ action: 'remove', item_id: 0, cart_key: key }, function(data) {
                if (data.success) {
                    var row = document.getElementById('row-' + rowId);
                    if (row) row.remove();
                    document.getElementById('food-total').textContent = '$' + data.cart_total;
                    if (data.cart_count === 0) location.reload();
                } else {
                    showMsg(data.error || 'Remove failed.', false);
                }
            });
        });
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
