<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db     = getDB();
$errors = [];
$success = '';

$perPage   = 15;
$page      = max(1, (int)($_GET['page'] ?? 1));
$offset    = ($page - 1) * $perPage;
$filterStatus = $_GET['status'] ?? '';

$validStatuses = ['pending','open','accepted','card_collected','food_collected','delivered','cancelled'];

// Handle cancel action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action  = $_POST['action'] ?? '';
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($action === 'cancel' && $orderId > 0) {
            $db->prepare('UPDATE orders SET status = "cancelled", updated_at = NOW() WHERE id = ?')->execute([$orderId]);
            $db->prepare('INSERT INTO order_events (order_id, type) VALUES (?, "cancelled")')->execute([$orderId]);
            $success = 'Order #' . $orderId . ' cancelled.';
        }
    }
}

// Count total
$whereClause = '';
$whereParams = [];
if ($filterStatus && in_array($filterStatus, $validStatuses, true)) {
    $whereClause  = 'WHERE o.status = ?';
    $whereParams[] = $filterStatus;
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM orders o $whereClause");
$countStmt->execute($whereParams);
$totalOrders = (int)$countStmt->fetchColumn();
$totalPages  = max(1, (int)ceil($totalOrders / $perPage));
$page        = min($page, $totalPages);
$offset      = ($page - 1) * $perPage;

// Fetch orders
$orderStmt = $db->prepare(
    "SELECT o.*, r.name AS restaurant_name, u.name AS customer_display_name
     FROM orders o
     JOIN restaurants r ON r.id = o.restaurant_id
     JOIN users u ON u.id = o.customer_id
     $whereClause
     ORDER BY o.created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$orderStmt->execute($whereParams);
$orders = $orderStmt->fetchAll();

// Detail view
$detailOrder   = null;
$detailItems   = [];
$detailEvents  = [];
$detailCourier = null;
$viewId = (int)($_GET['view'] ?? 0);
if ($viewId > 0) {
    $dStmt = $db->prepare(
        'SELECT o.*, r.name AS restaurant_name, u.name AS customer_display_name
         FROM orders o
         JOIN restaurants r ON r.id = o.restaurant_id
         JOIN users u ON u.id = o.customer_id
         WHERE o.id = ?'
    );
    $dStmt->execute([$viewId]);
    $detailOrder = $dStmt->fetch();

    if ($detailOrder) {
        $iStmt = $db->prepare(
            'SELECT oi.*, mi.name AS item_name FROM order_items oi
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             WHERE oi.order_id = ?'
        );
        $iStmt->execute([$viewId]);
        $detailItems = $iStmt->fetchAll();

        $eStmt = $db->prepare(
            'SELECT oe.*, u.name AS courier_name FROM order_events oe
             LEFT JOIN users u ON u.id = oe.courier_id
             WHERE oe.order_id = ? ORDER BY oe.timestamp ASC'
        );
        $eStmt->execute([$viewId]);
        $detailEvents = $eStmt->fetchAll();

        if ($detailOrder['courier_id']) {
            $cStmt = $db->prepare('SELECT id, name, email FROM users WHERE id = ?');
            $cStmt->execute([$detailOrder['courier_id']]);
            $detailCourier = $cStmt->fetch();
        }
    }
}

$csrf = generateCsrfToken();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="ti ti-package me-2 text-primary"></i>Orders</h2>
    <a href="<?= APP_URL ?>/admin/dashboard" class="btn btn-outline-secondary btn-sm">← Dashboard</a>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e, ENT_QUOTES | ENT_HTML5) ?></div><?php endforeach; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES | ENT_HTML5) ?></div><?php endif; ?>

<?php if ($detailOrder): ?>
    <!-- Detail View -->
    <div class="mb-3">
        <a href="<?= APP_URL ?>/admin/orders" class="btn btn-outline-secondary btn-sm">← Back to Orders</a>
    </div>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-semibold d-flex justify-content-between">
                    <span>Order #<?= (int)$detailOrder['id'] ?> — <?= htmlspecialchars($detailOrder['restaurant_name'], ENT_QUOTES | ENT_HTML5) ?></span>
                    <span class="badge <?= getOrderStatusBadgeClass($detailOrder['status']) ?>"><?= formatOrderStatus($detailOrder['status']) ?></span>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong>Customer:</strong> <?= htmlspecialchars($detailOrder['customer_display_name'], ENT_QUOTES | ENT_HTML5) ?></p>
                    <p class="mb-1"><strong>Type:</strong> <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $detailOrder['order_type'])), ENT_QUOTES | ENT_HTML5) ?></p>
                    <p class="mb-1"><strong>Delivery:</strong> <?= htmlspecialchars($detailOrder['delivery_address'] ?? 'N/A', ENT_QUOTES | ENT_HTML5) ?></p>
                    <?php if ($detailCourier): ?>
                        <p class="mb-1"><strong>Courier:</strong> <?= htmlspecialchars($detailCourier['name'], ENT_QUOTES | ENT_HTML5) ?> (<?= htmlspecialchars($detailCourier['email'], ENT_QUOTES | ENT_HTML5) ?>)</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($detailItems)): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-bold">Items</div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead class="table-light"><tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Price</th></tr></thead>
                        <tbody>
                            <?php foreach ($detailItems as $it): ?>
                                <tr>
                                    <td><?= htmlspecialchars($it['item_name'], ENT_QUOTES | ENT_HTML5) ?></td>
                                    <td class="text-center"><?= (int)$it['quantity'] ?></td>
                                    <td class="text-end"><?= formatMoney((float)$it['unit_price'] * (int)$it['quantity']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-bold">Fee Breakdown</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-1"><span>Food Total</span><span><?= formatMoney((float)$detailOrder['food_total']) ?></span></div>
                    <div class="d-flex justify-content-between mb-1"><span>Delivery Fee</span><span><?= formatMoney((float)$detailOrder['delivery_fee']) ?></span></div>
                    <div class="d-flex justify-content-between mb-1"><span>Service Fee</span><span><?= formatMoney((float)$detailOrder['service_fee']) ?></span></div>
                    <div class="d-flex justify-content-between mb-1"><span>Tip</span><span><?= formatMoney((float)$detailOrder['tip_amount']) ?></span></div>
                    <hr>
                    <div class="d-flex justify-content-between fw-bold">
                        <span>Total</span>
                        <span><?= formatMoney((float)$detailOrder['food_total'] + (float)$detailOrder['delivery_fee'] + (float)$detailOrder['service_fee'] + (float)$detailOrder['tip_amount']) ?></span>
                    </div>
                </div>
            </div>

            <?php if (!in_array($detailOrder['status'], ['delivered', 'cancelled'], true)): ?>
            <form method="POST" action="/api/order_event">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="order_id" value="<?= (int)$detailOrder['id'] ?>">
                <input type="hidden" name="event_type" value="cancel">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Cancel this order?')">Cancel Order</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm border-0">
                <div class="card-header fw-bold">Timeline</div>
                <div class="card-body p-0">
                    <?php if (!empty($detailEvents)): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($detailEvents as $evt): ?>
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <strong><?= formatOrderStatus($evt['type']) ?></strong>
                                        <small class="text-muted"><?= htmlspecialchars(date('M j g:ia', strtotime($evt['timestamp'])), ENT_QUOTES | ENT_HTML5) ?></small>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="p-3 mb-0 text-muted">No events.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- Orders List -->
    <!-- Filter -->
    <form method="GET" class="mb-4 d-flex gap-3 align-items-end">
        <div>
            <label class="form-label fw-semibold">Filter by Status</label>
            <select class="form-select" name="status" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <?php foreach ($validStatuses as $s): ?>
                    <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= formatOrderStatus($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <small class="text-muted"><?= $totalOrders ?> order(s)</small>
    </form>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr><th>Order #</th><th>Customer</th><th>Restaurant</th><th>Type</th><th>Status</th><th>Total</th><th>Date</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No orders found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                                <?php $total = (float)$order['food_total'] + (float)$order['delivery_fee'] + (float)$order['service_fee'] + (float)$order['tip_amount']; ?>
                                <tr>
                                    <td><?= (int)$order['id'] ?></td>
                                    <td><?= htmlspecialchars($order['customer_display_name'], ENT_QUOTES | ENT_HTML5) ?></td>
                                    <td><?= htmlspecialchars($order['restaurant_name'], ENT_QUOTES | ENT_HTML5) ?></td>
                                    <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $order['order_type'])), ENT_QUOTES | ENT_HTML5) ?></td>
                                    <td><span class="badge <?= getOrderStatusBadgeClass($order['status']) ?>"><?= formatOrderStatus($order['status']) ?></span></td>
                                    <td><?= formatMoney($total) ?></td>
                                    <td><?= htmlspecialchars(date('M j, Y', strtotime($order['created_at'])), ENT_QUOTES | ENT_HTML5) ?></td>
                                    <td><a href="?view=<?= (int)$order['id'] ?><?= $filterStatus ? '&status=' . urlencode($filterStatus) : '' ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <nav>
            <ul class="pagination justify-content-center">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $p ?><?= $filterStatus ? '&status=' . urlencode($filterStatus) : '' ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
