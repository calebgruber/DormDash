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
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<h2 class="mb-4">📦 My Orders</h2>

<?php if (empty($orders)): ?>
    <div class="alert alert-info">You haven't placed any orders yet.</div>
    <a href="<?= APP_URL ?>/customer/dashboard.php" class="btn btn-primary">Order Food</a>
<?php else: ?>
    <div class="accordion" id="ordersAccordion">
        <?php foreach ($orders as $order): ?>
            <?php
            $total = (float)$order['food_total'] + (float)$order['delivery_fee']
                   + (float)$order['service_fee'] + (float)$order['tip_amount'];
            ?>
            <div class="accordion-item mb-3 border shadow-sm">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#order-<?= $order['id'] ?>">
                        <div class="d-flex w-100 justify-content-between align-items-center pe-3">
                            <div>
                                <strong>Order #<?= (int)$order['id'] ?></strong>
                                &mdash; <?= htmlspecialchars($order['restaurant_name'], ENT_QUOTES | ENT_HTML5) ?>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge <?= getOrderStatusBadgeClass($order['status']) ?>">
                                    <?= formatOrderStatus($order['status']) ?>
                                </span>
                                <span class="text-muted small"><?= formatMoney($total) ?></span>
                                <span class="text-muted small"><?= htmlspecialchars(date('M j, Y', strtotime($order['created_at'])), ENT_QUOTES | ENT_HTML5) ?></span>
                            </div>
                        </div>
                    </button>
                </h2>
                <div id="order-<?= $order['id'] ?>" class="accordion-collapse collapse">
                    <div class="accordion-body">
                        <?php
                        // Fetch order items
                        $itemStmt = $db->prepare(
                            'SELECT oi.*, mi.name AS item_name
                             FROM order_items oi
                             JOIN menu_items mi ON mi.id = oi.menu_item_id
                             WHERE oi.order_id = ?'
                        );
                        $itemStmt->execute([$order['id']]);
                        $items = $itemStmt->fetchAll();

                        // Fetch order events
                        $evtStmt = $db->prepare(
                            'SELECT oe.*, u.name AS courier_name
                             FROM order_events oe
                             LEFT JOIN users u ON u.id = oe.courier_id
                             WHERE oe.order_id = ?
                             ORDER BY oe.timestamp ASC'
                        );
                        $evtStmt->execute([$order['id']]);
                        $events = $evtStmt->fetchAll();
                        ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <h6 class="fw-bold mb-2">Items</h6>
                                <?php if (!empty($items)): ?>
                                    <ul class="list-unstyled">
                                        <?php foreach ($items as $it): ?>
                                            <li class="d-flex justify-content-between">
                                                <span><?= htmlspecialchars($it['item_name'], ENT_QUOTES | ENT_HTML5) ?> &times;<?= (int)$it['quantity'] ?></span>
                                                <span><?= formatMoney((float)$it['unit_price'] * (int)$it['quantity']) ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php elseif ($order['order_type'] === 'prepaid_pickup'): ?>
                                    <p class="text-muted">Prepaid pickup – <?= formatMoney((float)$order['food_total']) ?></p>
                                    <?php if ($order['boost_order_number']): ?>
                                        <p class="small">Boost #: <?= htmlspecialchars($order['boost_order_number'], ENT_QUOTES | ENT_HTML5) ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <hr>
                                <div class="small">
                                    <div class="d-flex justify-content-between"><span>Food Total</span><span><?= formatMoney((float)$order['food_total']) ?></span></div>
                                    <div class="d-flex justify-content-between"><span>Delivery Fee</span><span><?= formatMoney((float)$order['delivery_fee']) ?></span></div>
                                    <div class="d-flex justify-content-between"><span>Service Fee</span><span><?= formatMoney((float)$order['service_fee']) ?></span></div>
                                    <div class="d-flex justify-content-between"><span>Tip</span><span><?= formatMoney((float)$order['tip_amount']) ?></span></div>
                                    <div class="d-flex justify-content-between fw-bold"><span>Total</span><span><?= formatMoney($total) ?></span></div>
                                </div>
                                <?php if ($order['delivery_address']): ?>
                                    <p class="mt-2 small text-muted">📍 <?= htmlspecialchars($order['delivery_address'], ENT_QUOTES | ENT_HTML5) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <h6 class="fw-bold mb-2">Order Timeline</h6>
                                <?php if (!empty($events)): ?>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($events as $evt): ?>
                                            <li class="list-group-item px-0 py-2">
                                                <div class="d-flex justify-content-between">
                                                    <span><strong><?= formatOrderStatus($evt['type']) ?></strong>
                                                    <?php if ($evt['courier_name']): ?>
                                                        <small class="text-muted"> — <?= htmlspecialchars($evt['courier_name'], ENT_QUOTES | ENT_HTML5) ?></small>
                                                    <?php endif; ?>
                                                    </span>
                                                    <small class="text-muted"><?= htmlspecialchars(date('M j g:ia', strtotime($evt['timestamp'])), ENT_QUOTES | ENT_HTML5) ?></small>
                                                </div>
                                                <?php if ($evt['note']): ?>
                                                    <small class="text-muted"><?= htmlspecialchars($evt['note'], ENT_QUOTES | ENT_HTML5) ?></small>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-muted small">No events yet.</p>
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
