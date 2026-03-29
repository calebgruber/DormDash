<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$user     = currentUser();
$orderId  = (int)($_GET['id'] ?? 0);

if ($orderId <= 0) {
    header('Location: ' . APP_URL . '/deliver/');
    exit;
}

$db = getDB();
$stmt = $db->prepare(
    'SELECT o.*, r.name AS restaurant_name
     FROM orders o
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.id = ?'
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: ' . APP_URL . '/deliver/');
    exit;
}

// Only allow the assigned courier, admin, or an unaccepted order
$isAdmin   = $user['role'] === 'admin';
$isCourier = $user['role'] === 'courier' && (int)$order['courier_id'] === $user['id'];
$isOpen    = $order['status'] === 'open';

if (!$isAdmin && !$isCourier && !$isOpen) {
    header('Location: ' . APP_URL . '/deliver/');
    exit;
}

// Fetch order items
$itemStmt = $db->prepare(
    'SELECT oi.*, mi.name AS item_name
     FROM order_items oi
     JOIN menu_items mi ON mi.id = oi.menu_item_id
     WHERE oi.order_id = ?'
);
$itemStmt->execute([$orderId]);
$items = $itemStmt->fetchAll();

// Fetch order events
$evtStmt = $db->prepare(
    'SELECT oe.*, u.name AS courier_name
     FROM order_events oe
     LEFT JOIN users u ON u.id = oe.courier_id
     WHERE oe.order_id = ?
     ORDER BY oe.timestamp ASC'
);
$evtStmt->execute([$orderId]);
$events = $evtStmt->fetchAll();

$csrf = generateCsrfToken();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/deliver/">Courier Dashboard</a></li>
        <li class="breadcrumb-item active">Order #<?= (int)$orderId ?></li>
    </ol>
</nav>

<div id="event-message" class="alert d-none mb-3"></div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="ti ti-package me-2 text-primary"></i>Order #<?= (int)$orderId ?> — <?= htmlspecialchars($order['restaurant_name'], ENT_QUOTES | ENT_HTML5) ?></span>
                <span class="badge <?= getOrderStatusBadgeClass($order['status']) ?>"><?= formatOrderStatus($order['status']) ?></span>
            </div>
            <div class="card-body">
                <p class="mb-1"><strong>Customer:</strong> <?= htmlspecialchars($order['customer_name'] ?? 'N/A', ENT_QUOTES | ENT_HTML5) ?></p>
                <p class="mb-1"><strong>Delivery Address:</strong> <?= htmlspecialchars($order['delivery_address'] ?? 'N/A', ENT_QUOTES | ENT_HTML5) ?></p>
                <?php if ($order['mor_payment_type']): ?>
                    <p class="mb-1"><strong>Payment Type:</strong> <?= htmlspecialchars($order['mor_payment_type'], ENT_QUOTES | ENT_HTML5) ?></p>
                <?php endif; ?>
                <?php if ($order['boost_order_number']): ?>
                    <p class="mb-1"><strong>Boost Order #:</strong> <?= htmlspecialchars($order['boost_order_number'], ENT_QUOTES | ENT_HTML5) ?></p>
                <?php endif; ?>
                <?php if ($order['customer_notes']): ?>
                    <p class="mb-1"><strong>Notes:</strong> <?= htmlspecialchars($order['customer_notes'], ENT_QUOTES | ENT_HTML5) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Items -->
        <?php if (!empty($items)): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header fw-bold">Order Items</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead class="table-light"><tr><th>Item</th><th>Customisations</th><th class="text-center">Qty</th><th class="text-end">Price</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($it['item_name'], ENT_QUOTES | ENT_HTML5) ?></td>
                                <td class="small text-muted">
                                    <?php
                                    $opts = !empty($it['options_json']) ? json_decode($it['options_json'], true) : null;
                                    if ($opts):
                                        foreach ($opts as $groupName => $choice):
                                            $choiceStr = is_array($choice) ? implode(', ', $choice) : $choice;
                                    ?>
                                        <span class="badge bg-light text-dark border me-1">
                                            <?= htmlspecialchars($groupName, ENT_QUOTES | ENT_HTML5) ?>:
                                            <?= htmlspecialchars($choiceStr, ENT_QUOTES | ENT_HTML5) ?>
                                        </span>
                                    <?php
                                        endforeach;
                                    else:
                                        echo '<span class="text-muted">—</span>';
                                    endif;
                                    ?>
                                </td>
                                <td class="text-center"><?= (int)$it['quantity'] ?></td>
                                <td class="text-end"><?= formatMoney((float)$it['unit_price'] * (int)$it['quantity']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Fee Breakdown -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header fw-bold">Fee Breakdown</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-1"><span>Food Total</span><span><?= formatMoney((float)$order['food_total']) ?></span></div>
                <div class="d-flex justify-content-between mb-1"><span>Delivery Fee</span><span><?= formatMoney((float)$order['delivery_fee']) ?></span></div>
                <div class="d-flex justify-content-between mb-1"><span>Service Fee</span><span><?= formatMoney((float)$order['service_fee']) ?></span></div>
                <div class="d-flex justify-content-between mb-1 text-success fw-bold"><span>Tip (yours!)</span><span><?= formatMoney((float)$order['tip_amount']) ?></span></div>
                <hr>
                <div class="d-flex justify-content-between fw-bold"><span>Grand Total</span>
                    <span><?= formatMoney((float)$order['food_total'] + (float)$order['delivery_fee'] + (float)$order['service_fee'] + (float)$order['tip_amount']) ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <!-- Status Actions -->
        <?php if ($isCourier || $isAdmin): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header fw-bold">Actions</div>
            <div class="card-body">
                <?php if ($order['status'] === 'accepted' && $order['order_type'] === 'dining_hall'): ?>
                    <form class="event-form mb-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
                        <input type="hidden" name="event_type" value="card_collected">
                        <button type="submit" class="btn btn-primary w-100"><i class="ti ti-check me-1"></i>I Have the MORE Card</button>
                    </form>
                <?php elseif ($order['status'] === 'accepted' && $order['order_type'] === 'prepaid_pickup'): ?>
                    <form class="event-form mb-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
                        <input type="hidden" name="event_type" value="food_collected">
                        <button type="submit" class="btn btn-primary w-100"><i class="ti ti-basket me-1"></i>Food Collected</button>
                    </form>
                <?php elseif ($order['status'] === 'card_collected'): ?>
                    <form class="event-form mb-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
                        <input type="hidden" name="event_type" value="food_collected">
                        <button type="submit" class="btn btn-primary w-100"><i class="ti ti-basket me-1"></i>Food Collected</button>
                    </form>
                <?php elseif ($order['status'] === 'food_collected'): ?>
                    <form class="event-form" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
                        <input type="hidden" name="event_type" value="delivered">
                        <div class="mb-3">
                            <label class="form-label">Delivery Photo <span class="text-muted">(optional)</span></label>
                            <input type="file" class="form-control" name="photo" accept="image/*">
                        </div>
                        <button type="submit" class="btn btn-success w-100"><i class="ti ti-check-circle me-1"></i>Mark as Delivered</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0">No actions available for current status.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Timeline -->
        <div class="card shadow-sm border-0">
            <div class="card-header fw-bold">Order Timeline</div>
            <div class="card-body p-0">
                <?php if (!empty($events)): ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($events as $evt): ?>
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <strong><?= formatOrderStatus($evt['type']) ?></strong>
                                    <small class="text-muted"><?= htmlspecialchars(date('M j g:ia', strtotime($evt['timestamp'])), ENT_QUOTES | ENT_HTML5) ?></small>
                                </div>
                                <?php if ($evt['note']): ?>
                                    <small class="text-muted"><?= htmlspecialchars($evt['note'], ENT_QUOTES | ENT_HTML5) ?></small>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="p-3 mb-0 text-muted">No events yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.event-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        var btn = this.querySelector('button[type=submit]');
        if (btn) { btn.disabled = true; btn.textContent = 'Processing...'; }

        fetch('/api/order_event', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var msgEl = document.getElementById('event-message');
                if (data.success) {
                    msgEl.className = 'alert alert-success mb-3';
                    msgEl.textContent = data.message || 'Done!';
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    if (btn) { btn.disabled = false; btn.textContent = 'Retry'; }
                    msgEl.className = 'alert alert-danger mb-3';
                    msgEl.textContent = data.error || 'Action failed.';
                }
                msgEl.classList.remove('d-none');
            })
            .catch(function() {
                if (btn) { btn.disabled = false; }
                alert('Request failed. Please try again.');
            });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
