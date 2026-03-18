<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$user = currentUser();
$db   = getDB();

$stmt = $db->prepare(
    'SELECT o.*, r.name AS restaurant_name
     FROM orders o
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.customer_id = ?
     ORDER BY o.created_at DESC'
);
$stmt->execute([$user['id']]);
$orders = $stmt->fetchAll();

// Fetch items for each order
foreach ($orders as &$order) {
    try {
        $iStmt = $db->prepare(
            'SELECT oi.*, mi.name AS item_name
             FROM order_items oi
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             WHERE oi.order_id = ?'
        );
        $iStmt->execute([$order['id']]);
        $order['items'] = $iStmt->fetchAll();
    } catch (Throwable $e) {
        $order['items'] = [];
    }

    try {
        $eStmt = $db->prepare(
            'SELECT oe.*, u.name AS courier_name
             FROM order_events oe
             LEFT JOIN users u ON u.id = oe.courier_id
             WHERE oe.order_id = ?
             ORDER BY oe.timestamp ASC'
        );
        $eStmt->execute([$order['id']]);
        $order['events'] = $eStmt->fetchAll();
    } catch (Throwable $e) {
        $order['events'] = [];
    }
}
unset($order);
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold mb-0"><i class="ti ti-package me-2 text-primary"></i>My Orders</h2>
    <a href="<?= APP_URL ?>/order/" class="btn btn-primary btn-sm">
        <i class="ti ti-shopping-bag me-1"></i>Order Food
    </a>
</div>

<?php if (empty($orders)): ?>
    <div class="text-center py-5">
        <i class="ti ti-package-off text-muted" style="font-size:4rem;"></i>
        <h4 class="mt-3 text-muted">No orders yet</h4>
        <p class="text-muted">Head to a restaurant and place your first order!</p>
        <a href="<?= APP_URL ?>/order/" class="btn btn-primary mt-2">Browse Restaurants</a>
    </div>
<?php else: ?>
    <div class="row g-4">
    <?php foreach ($orders as $order): ?>
        <?php
        $total = (float)$order['food_total'] + (float)$order['delivery_fee']
               + (float)$order['service_fee'] + (float)$order['tip_amount'];
        $swipeCredit = (float)($order['meal_swipe_credit'] ?? 0);
        $netTotal    = max(0, $total - $swipeCredit);
        $statusClass = getOrderStatusBadgeClass($order['status']);
        $statusLabel = formatOrderStatus($order['status']);
        $isPending   = $order['payment_status'] === 'pending';
        ?>
        <div class="col-12">
        <div class="card shadow-sm border-0 <?= $isPending ? 'border-start border-warning border-3' : '' ?>">
            <!-- Card header row -->
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                <div>
                    <span class="fw-bold text-primary">#<?= (int)$order['id'] ?></span>
                    <span class="mx-2 text-muted">|</span>
                    <span class="fw-semibold"><?= htmlspecialchars($order['restaurant_name'], ENT_QUOTES | ENT_HTML5) ?></span>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                    <?php if ($isPending): ?>
                        <span class="badge bg-warning text-dark"><i class="ti ti-clock me-1"></i>Payment Pending</span>
                        <a href="<?= APP_URL ?>/order/checkout" class="btn btn-warning btn-sm">Complete Payment</a>
                    <?php endif; ?>
                    <span class="text-muted small">
                        <i class="ti ti-calendar me-1"></i><?= htmlspecialchars(date('M j, Y \a\t g:ia', strtotime($order['created_at'])), ENT_QUOTES | ENT_HTML5) ?>
                    </span>
                </div>
            </div>

            <div class="card-body">
                <div class="row g-3">

                    <!-- Items column -->
                    <div class="col-md-4">
                        <h6 class="fw-bold text-muted text-uppercase small mb-2">Items Ordered</h6>
                        <?php if (!empty($order['items'])): ?>
                            <ul class="list-unstyled mb-0">
                            <?php foreach ($order['items'] as $it): ?>
                                <li class="d-flex justify-content-between align-items-start mb-1">
                                    <div>
                                        <span class="fw-semibold"><?= htmlspecialchars($it['item_name'], ENT_QUOTES | ENT_HTML5) ?></span>
                                        <span class="text-muted small ms-1">&times;<?= (int)$it['quantity'] ?></span>
                                        <?php if (!empty($it['options_json'])): ?>
                                            <?php $opts = json_decode($it['options_json'], true); ?>
                                            <?php if ($opts): ?>
                                                <div class="text-muted small">
                                                    <?php foreach ($opts as $og => $choice): ?>
                                                        <span><?= htmlspecialchars($og, ENT_QUOTES | ENT_HTML5) ?>: <?= htmlspecialchars(is_array($choice) ? implode(', ', $choice) : $choice, ENT_QUOTES | ENT_HTML5) ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-muted small"><?= formatMoney((float)$it['unit_price'] * (int)$it['quantity']) ?></span>
                                </li>
                            <?php endforeach; ?>
                            </ul>
                        <?php elseif ($order['order_type'] === 'prepaid_pickup'): ?>
                            <p class="text-muted small mb-0">
                                Prepaid pickup &mdash; <?= formatMoney((float)$order['food_total']) ?>
                                <?php if ($order['boost_order_number']): ?>
                                    <br>Boost #: <?= htmlspecialchars($order['boost_order_number'], ENT_QUOTES | ENT_HTML5) ?>
                                <?php endif; ?>
                            </p>
                        <?php elseif ($order['custom_description']): ?>
                            <p class="text-muted small mb-0"><?= htmlspecialchars($order['custom_description'], ENT_QUOTES | ENT_HTML5) ?></p>
                        <?php else: ?>
                            <p class="text-muted small mb-0">No item details.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Summary column -->
                    <div class="col-md-4">
                        <h6 class="fw-bold text-muted text-uppercase small mb-2">Summary</h6>
                        <div class="small">
                            <?php if ((float)$order['food_total'] > 0): ?>
                                <div class="d-flex justify-content-between mb-1"><span>Food</span><span><?= formatMoney((float)$order['food_total']) ?></span></div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between mb-1"><span>Delivery</span><span><?= formatMoney((float)$order['delivery_fee']) ?></span></div>
                            <div class="d-flex justify-content-between mb-1"><span>Service</span><span><?= formatMoney((float)$order['service_fee']) ?></span></div>
                            <?php if ((float)$order['tip_amount'] > 0): ?>
                                <div class="d-flex justify-content-between mb-1"><span>Tip</span><span><?= formatMoney((float)$order['tip_amount']) ?></span></div>
                            <?php endif; ?>
                            <?php if ($swipeCredit > 0): ?>
                                <div class="d-flex justify-content-between mb-1 text-success"><span>Meal Swipe Credit</span><span>&minus;<?= formatMoney($swipeCredit) ?></span></div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between fw-bold mt-1 pt-1 border-top">
                                <span>Total Paid</span>
                                <span><?= formatMoney($netTotal) ?></span>
                            </div>
                        </div>
                        <?php if ($order['delivery_address']): ?>
                            <div class="mt-2 small text-muted">
                                <i class="ti ti-map-pin me-1"></i><?= htmlspecialchars($order['delivery_address'], ENT_QUOTES | ENT_HTML5) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($order['mor_payment_type']): ?>
                            <div class="mt-1 small text-muted">
                                <i class="ti ti-id-badge-2 me-1"></i>MORE: <?= htmlspecialchars($order['mor_payment_type'], ENT_QUOTES | ENT_HTML5) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ((int)($order['meal_swipes_applied'] ?? 0) > 0): ?>
                            <div class="mt-1 small text-success">
                                <i class="ti ti-star me-1"></i><?= (int)$order['meal_swipes_applied'] ?> meal swipe<?= $order['meal_swipes_applied'] > 1 ? 's' : '' ?> applied
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Timeline column -->
                    <div class="col-md-4">
                        <h6 class="fw-bold text-muted text-uppercase small mb-2">Progress</h6>
                        <?php if (!empty($order['events'])): ?>
                            <ul class="list-unstyled mb-0">
                            <?php
                            $evtIcons = [
                                'placed'       => ['ti-circle-check', 'text-success'],
                                'accepted'     => ['ti-user-check',   'text-primary'],
                                'picked_up'    => ['ti-shopping-bag', 'text-purple'],
                                'on_the_way'   => ['ti-motorbike',    'text-warning'],
                                'delivered'    => ['ti-home-check',   'text-success'],
                                'cancelled'    => ['ti-circle-x',     'text-danger'],
                            ];
                            foreach ($order['events'] as $evt):
                                [$ico, $cls] = $evtIcons[$evt['type']] ?? ['ti-circle', 'text-muted'];
                            ?>
                                <li class="d-flex align-items-start gap-2 mb-2">
                                    <i class="ti <?= $ico ?> <?= $cls ?> mt-1 flex-shrink-0"></i>
                                    <div>
                                        <div class="fw-semibold small"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $evt['type'])), ENT_QUOTES | ENT_HTML5) ?></div>
                                        <?php if (!empty($evt['courier_name'])): ?>
                                            <div class="text-muted small">by <?= htmlspecialchars($evt['courier_name'], ENT_QUOTES | ENT_HTML5) ?></div>
                                        <?php endif; ?>
                                        <div class="text-muted small"><?= htmlspecialchars(date('M j g:ia', strtotime($evt['timestamp'])), ENT_QUOTES | ENT_HTML5) ?></div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted small">No updates yet.</p>
                        <?php endif; ?>

                        <?php if ($order['customer_notes']): ?>
                            <div class="mt-2 p-2 bg-light rounded small text-muted">
                                <i class="ti ti-note me-1"></i><?= htmlspecialchars($order['customer_notes'], ENT_QUOTES | ENT_HTML5) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
