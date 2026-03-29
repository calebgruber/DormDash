<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="text-center py-5">
            <div class="text-warning mb-3"><i class="ti ti-alert-triangle" style="font-size:4rem"></i></div>
            <div class="alert alert-warning">
                <h4 class="alert-heading fw-bold">Payment Cancelled</h4>
                <p class="mb-0">Your payment was cancelled. Your order has not been placed.</p>
            </div>
            <div class="d-flex justify-content-center gap-3 mt-4 flex-wrap">
                <a href="<?= APP_URL ?>/order/checkout" class="btn btn-warning">Try Again</a>
                <a href="<?= APP_URL ?>/order/" class="btn btn-outline-secondary">Go Home</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
