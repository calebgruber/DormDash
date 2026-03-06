<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$orderId = (int)($_GET['order_id'] ?? 0);
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="text-center py-5">
            <div class="text-success mb-3"><i class="ti ti-circle-check" style="font-size:4rem"></i></div>
            <div class="alert alert-success">
                <h4 class="alert-heading fw-bold">Payment Successful!</h4>
                <p class="mb-0">Your order has been placed! A courier will accept it shortly.</p>
            </div>
            <?php if ($orderId > 0): ?>
                <p class="text-muted">Order #<?= (int)$orderId ?></p>
            <?php endif; ?>
            <div class="d-flex justify-content-center gap-3 mt-4 flex-wrap">
                <a href="<?= APP_URL ?>/order/history" class="btn btn-primary">View My Orders</a>
                <a href="<?= APP_URL ?>/order/" class="btn btn-outline-secondary">Order More Food</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
