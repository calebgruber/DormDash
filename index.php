<?php
require_once __DIR__ . '/includes/auth.php';
startAppSession();

$user = currentUser();
if ($user) {
    if ($user['role'] === 'admin') {
        header('Location: ' . APP_URL . '/admin/dashboard.php');
        exit;
    } elseif ($user['role'] === 'courier' && $user['courier_approved']) {
        header('Location: ' . APP_URL . '/courier/dashboard.php');
        exit;
    } else {
        header('Location: ' . APP_URL . '/customer/dashboard.php');
        exit;
    }
}
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<div class="py-5 text-center text-white rounded-3 mb-4"
     style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); min-height: 320px; display:flex; flex-direction:column; justify-content:center;">
    <h1 class="display-4 fw-bold mb-3">DormDash 🍔</h1>
    <p class="lead mb-4">Campus food delivery, powered by students.</p>
    <div class="d-flex justify-content-center gap-3 flex-wrap">
        <a href="<?= APP_URL ?>/auth/register.php" class="btn btn-warning btn-lg px-4">Get Started</a>
        <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-outline-light btn-lg px-4">Sign In</a>
    </div>
</div>

<div class="row g-4 mb-5">
    <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-body text-center p-4">
                <div class="fs-1 mb-3">🍔</div>
                <h5 class="card-title fw-bold">Order Food</h5>
                <p class="card-text text-muted">Browse campus restaurants and get food delivered right to your dorm.</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-body text-center p-4">
                <div class="fs-1 mb-3">💰</div>
                <h5 class="card-title fw-bold">Earn Money Delivering</h5>
                <p class="card-text text-muted">Apply to be a courier and earn tips delivering food across campus.</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-body text-center p-4">
                <div class="fs-1 mb-3">⚡</div>
                <h5 class="card-title fw-bold">Fast Campus Delivery</h5>
                <p class="card-text text-muted">Orders delivered quickly by fellow students who know the campus.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
