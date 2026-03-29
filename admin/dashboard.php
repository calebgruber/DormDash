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

<h2 class="mb-4"><i class="ti ti-settings me-2 text-primary"></i>Admin Dashboard</h2>

<!-- Stats Cards -->
<div class="row g-4 mb-5">
    <?php
    $stats = [
        ['value' => (int)$ordersToday,           'label' => 'Orders Today',         'icon' => 'ti-shopping-bag', 'color' => '#6B46C1', 'bg' => 'rgba(107,70,193,.12)'],
        ['value' => (int)$pendingApps,            'label' => 'Pending Applications', 'icon' => 'ti-user-check',   'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,.12)'],
        ['value' => (int)$activeOrders,           'label' => 'Active Orders',        'icon' => 'ti-motorbike',    'color' => '#0ea5e9', 'bg' => 'rgba(14,165,233,.12)'],
        ['value' => formatMoney((float)$revenue), 'label' => 'Total Revenue',        'icon' => 'ti-cash',         'color' => '#10b981', 'bg' => 'rgba(16,185,129,.12)'],
    ];
    foreach ($stats as $s): ?>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100 dd-stat-card">
                <div class="dd-stat-accent" style="background-color:<?= $s['color'] ?>;"></div>
                <div class="card-body d-flex align-items-center justify-content-between ps-4">
                    <div>
                        <div class="dd-stat-value"><?= is_string($s['value']) ? htmlspecialchars($s['value'], ENT_QUOTES | ENT_HTML5) : (int)$s['value'] ?></div>
                        <div class="dd-stat-label"><?= htmlspecialchars($s['label'], ENT_QUOTES | ENT_HTML5) ?></div>
                    </div>
                    <div class="dd-stat-icon ms-3" style="background-color:<?= $s['bg'] ?>; color:<?= $s['color'] ?>;">
                        <i class="ti <?= htmlspecialchars($s['icon']) ?>"></i>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Navigation Cards -->
<h4 class="mb-3">Management</h4>
<div class="row g-3">
    <?php
    $navItems = [
        ['url' => 'restaurants', 'icon' => 'ti-tools-kitchen-2', 'title' => 'Restaurants', 'desc' => 'Add, edit, or toggle campus restaurants'],
        ['url' => 'menus',       'icon' => 'ti-clipboard-list', 'title' => 'Menus',       'desc' => 'Manage menu categories and items'],
        ['url' => 'couriers',    'icon' => 'ti-bike',           'title' => 'Couriers',    'desc' => 'Review and approve courier applications'],
        ['url' => 'orders',      'icon' => 'ti-package',        'title' => 'Orders',      'desc' => 'View and manage all orders'],
        ['url' => 'support',     'icon' => 'ti-message-circle', 'title' => 'Support',     'desc' => 'Customer support messages'],
        ['url' => '../config/',  'icon' => 'ti-settings',       'title' => 'Settings',    'desc' => 'Configure fees, emails, and app settings'],
    ];
    foreach ($navItems as $nav): ?>
        <div class="col-sm-6 col-lg-4">
            <a href="<?= APP_URL ?>/admin/<?= $nav['url'] ?>" class="text-decoration-none">
                <div class="card shadow-sm border-0 h-100 hover-card">
                    <div class="card-body">
                        <div class="mb-2"><i class="ti <?= htmlspecialchars($nav['icon']) ?> fs-1 text-primary"></i></div>
                        <h5 class="card-title"><?= $nav['title'] ?></h5>
                        <p class="card-text text-muted"><?= $nav['desc'] ?></p>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
    <div class="col-sm-6 col-lg-4">
        <a href="<?= APP_URL ?>/config/" class="text-decoration-none">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    
                    <h5 class="card-title">App Settings</h5>
                    <p class="card-text text-muted">Theme, database config, Stripe keys, and migrations</p>
                </div>
            </div>
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
