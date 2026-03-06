<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db = getDB();

// Stats
$ordersToday = $db->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$pendingApps = $db->query("SELECT COUNT(*) FROM courier_applications WHERE status = 'pending'")->fetchColumn();
$activeOrders = $db->query("SELECT COUNT(*) FROM orders WHERE status IN ('open','accepted','card_collected','food_collected')")->fetchColumn();
$revenue = $db->query("SELECT COALESCE(SUM(food_total + delivery_fee + service_fee + tip_amount), 0) FROM orders WHERE payment_status = 'paid'")->fetchColumn();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<h2 class="mb-4">⚙️ Admin Dashboard</h2>

<!-- Stats Cards -->
<div class="row g-4 mb-5">
    <div class="col-sm-6 col-lg-3">
        <div class="card text-white bg-primary shadow-sm border-0 h-100">
            <div class="card-body text-center">
                <div class="fs-1 fw-bold"><?= (int)$ordersToday ?></div>
                <div>Orders Today</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-white bg-warning shadow-sm border-0 h-100">
            <div class="card-body text-center" style="color:#333!important;">
                <div class="fs-1 fw-bold"><?= (int)$pendingApps ?></div>
                <div>Pending Applications</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-white bg-info shadow-sm border-0 h-100">
            <div class="card-body text-center" style="color:#333!important;">
                <div class="fs-1 fw-bold"><?= (int)$activeOrders ?></div>
                <div>Active Orders</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-white bg-success shadow-sm border-0 h-100">
            <div class="card-body text-center">
                <div class="fs-1 fw-bold"><?= formatMoney((float)$revenue) ?></div>
                <div>Total Revenue</div>
            </div>
        </div>
    </div>
</div>

<!-- Navigation Cards -->
<h4 class="mb-3">Management</h4>
<div class="row g-3">
    <?php
    $navItems = [
        ['url' => 'restaurants.php', 'icon' => '🍽️', 'title' => 'Restaurants', 'desc' => 'Add, edit, or toggle campus restaurants'],
        ['url' => 'menus.php',       'icon' => '📋', 'title' => 'Menus',       'desc' => 'Manage menu categories and items'],
        ['url' => 'couriers.php',    'icon' => '🚴', 'title' => 'Couriers',    'desc' => 'Review and approve courier applications'],
        ['url' => 'orders.php',      'icon' => '📦', 'title' => 'Orders',      'desc' => 'View and manage all orders'],
        ['url' => 'config.php',      'icon' => '⚙️', 'title' => 'Config',      'desc' => 'Configure fees and payment settings'],
    ];
    foreach ($navItems as $nav): ?>
        <div class="col-sm-6 col-lg-4">
            <a href="<?= APP_URL ?>/admin/<?= $nav['url'] ?>" class="text-decoration-none">
                <div class="card shadow-sm border-0 h-100 hover-card">
                    <div class="card-body">
                        <div class="fs-2 mb-2"><?= $nav['icon'] ?></div>
                        <h5 class="card-title"><?= $nav['title'] ?></h5>
                        <p class="card-text text-muted"><?= $nav['desc'] ?></p>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
