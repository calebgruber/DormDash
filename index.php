<?php
require_once __DIR__ . '/includes/auth.php';
startAppSession();

$user = currentUser();
if ($user) {
    if ($user['role'] === 'admin') {
        header('Location: ' . APP_URL . '/admin/dashboard');
        exit;
    } elseif ($user['role'] === 'courier' && $user['courier_approved']) {
        header('Location: ' . APP_URL . '/deliver/');
        exit;
    } else {
        header('Location: ' . APP_URL . '/order/');
        exit;
    }
}
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<!-- Hero -->
<div class="rounded-4 mb-4 overflow-hidden position-relative text-white"
     style="background:linear-gradient(135deg,#1a1a2e 0%,#2d1b69 50%,#6B46C1 100%);min-height:340px;display:flex;flex-direction:column;justify-content:center;padding:3rem 2rem;">
    <div class="row align-items-center">
        <div class="col-lg-7">
            <h1 class="display-4 fw-bold mb-3" style="text-shadow:0 2px 8px rgba(0,0,0,.3)">
                Campus food delivery,<br>by students, for students.
            </h1>
            <p class="lead mb-4 opacity-90">Order from dining halls and campus restaurants &mdash; a fellow student delivers it right to your door.</p>
            <div class="d-flex gap-3 flex-wrap">
                <a href="<?= APP_URL ?>/auth/register" class="btn btn-warning btn-lg px-4 fw-semibold">
                    <i class="ti ti-arrow-right me-1"></i> Get Started
                </a>
                <a href="<?= APP_URL ?>/auth/login" class="btn btn-outline-light btn-lg px-4">Sign In</a>
            </div>
        </div>
        <div class="col-lg-5 d-none d-lg-flex justify-content-center">
            <i class="ti ti-motorbike" style="font-size:9rem;opacity:.25;"></i>
        </div>
    </div>
</div>

<!-- Feature cards -->
<div class="row g-4 mb-5">
    <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0 hover-lift">
            <div class="card-body text-center p-4">
                <div class="mb-3">
                    <span class="avatar avatar-xl rounded-circle bg-purple-lt">
                        <i class="ti ti-salad fs-1 text-primary"></i>
                    </span>
                </div>
                <h5 class="card-title fw-bold">Order Food</h5>
                <p class="card-text text-muted">Browse campus restaurants and dining halls. Add items to your cart and check out in minutes.</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0 hover-lift">
            <div class="card-body text-center p-4">
                <div class="mb-3">
                    <span class="avatar avatar-xl rounded-circle bg-purple-lt">
                        <i class="ti ti-bike fs-1 text-primary"></i>
                    </span>
                </div>
                <h5 class="card-title fw-bold">Earn Delivering</h5>
                <p class="card-text text-muted">Apply to be a courier and earn tips delivering food across campus on your own schedule.</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0 hover-lift">
            <div class="card-body text-center p-4">
                <div class="mb-3">
                    <span class="avatar avatar-xl rounded-circle bg-purple-lt">
                        <i class="ti ti-bolt fs-1 text-primary"></i>
                    </span>
                </div>
                <h5 class="card-title fw-bold">Fast Delivery</h5>
                <p class="card-text text-muted">Students who know every shortcut on campus get your order to you quickly.</p>
            </div>
        </div>
    </div>
</div>

<!-- How it works -->
<div class="card border-0 shadow-sm mb-5">
    <div class="card-body p-4">
        <h3 class="fw-bold mb-4 text-center">How It Works</h3>
        <div class="row g-4 text-center">
            <div class="col-6 col-md-3">
                <div class="mb-2"><i class="ti ti-circle-number-1 fs-1 text-primary"></i></div>
                <p class="fw-semibold mb-0">Browse Restaurants</p>
                <p class="text-muted small">Pick from campus dining halls &amp; eateries</p>
            </div>
            <div class="col-6 col-md-3">
                <div class="mb-2"><i class="ti ti-circle-number-2 fs-1 text-primary"></i></div>
                <p class="fw-semibold mb-0">Build Your Cart</p>
                <p class="text-muted small">Add items, customize them, choose a tip</p>
            </div>
            <div class="col-6 col-md-3">
                <div class="mb-2"><i class="ti ti-circle-number-3 fs-1 text-primary"></i></div>
                <p class="fw-semibold mb-0">A Courier Accepts</p>
                <p class="text-muted small">A fellow student picks up and pays with your MORE card</p>
            </div>
            <div class="col-6 col-md-3">
                <div class="mb-2"><i class="ti ti-circle-number-4 fs-1 text-primary"></i></div>
                <p class="fw-semibold mb-0">Delivered to You</p>
                <p class="text-muted small">Hot food right at your dorm or classroom</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
