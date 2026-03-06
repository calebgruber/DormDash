<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$user = currentUser();

// Allow couriers (approved) or admins
if ($user['role'] !== 'admin' && !($user['role'] === 'courier' && $user['courier_approved'])) {
    header('Location: ' . APP_URL . '/order/');
    exit;
}

$db = getDB();
$csrf = generateCsrfToken();

// Fetch available orders (open + paid)
$availableStmt = $db->prepare(
    'SELECT o.*, r.name AS restaurant_name, u.name AS customer_name_display
     FROM orders o
     JOIN restaurants r ON r.id = o.restaurant_id
     JOIN users u ON u.id = o.customer_id
     WHERE o.status = "open" AND o.payment_status = "paid"
     ORDER BY o.created_at ASC'
);
$availableStmt->execute();
$availableOrders = $availableStmt->fetchAll();

// Fetch my active orders
$activeStmt = $db->prepare(
    'SELECT o.*, r.name AS restaurant_name
     FROM orders o
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.courier_id = ? AND o.status IN ("accepted","card_collected","food_collected")
     ORDER BY o.updated_at DESC'
);
$activeStmt->execute([$user['id']]);
$activeOrders = $activeStmt->fetchAll();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<h2 class="mb-4">🚴 Courier Dashboard</h2>

<div id="event-message" class="alert d-none mb-3"></div>

<!-- Available Orders -->
<h4 class="mb-3">Available Orders <span class="badge bg-warning text-dark"><?= count($availableOrders) ?></span></h4>

<?php if (empty($availableOrders)): ?>
    <div class="alert alert-info mb-4">No orders available right now. Check back soon!</div>
<?php else: ?>
    <div class="row g-3 mb-5">
        <?php foreach ($availableOrders as $order): ?>
            <?php
            // Show only partial delivery address for privacy
            $addrParts = explode(' ', $order['delivery_address'] ?? '');
            $shortAddr = implode(' ', array_slice($addrParts, 0, 3)) . (count($addrParts) > 3 ? '...' : '');
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <h6 class="mb-0">Order #<?= (int)$order['id'] ?></h6>
                            <span class="badge bg-success"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $order['order_type'])), ENT_QUOTES | ENT_HTML5) ?></span>
                        </div>
                        <p class="mb-1"><strong>Restaurant:</strong> <?= htmlspecialchars($order['restaurant_name'], ENT_QUOTES | ENT_HTML5) ?></p>
                        <p class="mb-1"><strong>Delivery:</strong> <?= htmlspecialchars($shortAddr, ENT_QUOTES | ENT_HTML5) ?></p>
                        <?php if ($order['mor_payment_type']): ?>
                            <p class="mb-1"><strong>Payment:</strong> <?= htmlspecialchars($order['mor_payment_type'], ENT_QUOTES | ENT_HTML5) ?></p>
                        <?php endif; ?>
                        <p class="mb-2"><strong>Tip:</strong> <span class="text-success fw-bold"><?= formatMoney((float)$order['tip_amount']) ?></span></p>
                        <form class="accept-form" data-order-id="<?= (int)$order['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="event_type" value="accept">
                            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                            <button type="submit" class="btn btn-primary w-100">Accept Order</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- My Active Orders -->
<h4 class="mb-3">My Active Orders <span class="badge bg-info text-dark"><?= count($activeOrders) ?></span></h4>

<?php if (empty($activeOrders)): ?>
    <div class="alert alert-secondary">No active orders.</div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($activeOrders as $order): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <h6 class="mb-0">Order #<?= (int)$order['id'] ?></h6>
                            <span class="badge <?= getOrderStatusBadgeClass($order['status']) ?>"><?= formatOrderStatus($order['status']) ?></span>
                        </div>
                        <p class="mb-1"><?= htmlspecialchars($order['restaurant_name'], ENT_QUOTES | ENT_HTML5) ?></p>
                        <p class="mb-2 text-muted small"><?= htmlspecialchars($order['delivery_address'] ?? '', ENT_QUOTES | ENT_HTML5) ?></p>
                        <a href="<?= APP_URL ?>/deliver/order.php?id=<?= (int)$order['id'] ?>" class="btn btn-outline-primary w-100">View Order</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
document.querySelectorAll('.accept-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        var btn = this.querySelector('button[type=submit]');
        btn.disabled = true;
        btn.textContent = 'Accepting...';

        fetch('/api/order_event', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var msgEl = document.getElementById('event-message');
                if (data.success) {
                    msgEl.className = 'alert alert-success mb-3';
                    msgEl.textContent = data.message || 'Order accepted!';
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Accept Order';
                    msgEl.className = 'alert alert-danger mb-3';
                    msgEl.textContent = data.error || 'Failed to accept order.';
                }
                msgEl.classList.remove('d-none');
            })
            .catch(function() {
                btn.disabled = false;
                btn.textContent = 'Accept Order';
            });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
