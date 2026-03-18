<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email.php';
requireLogin();

startAppSession(); // already called by requireLogin → auth.php

$user    = currentUser();
$db      = getDB();
$success = false;
$errors  = [];

// Resend verification email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    if (!empty($user['email_verified'])) {
        header('Location: ' . APP_URL . '/order/');
        exit;
    }
    // Generate new token
    $token = bin2hex(random_bytes(32));
    $db->prepare('UPDATE users SET email_verify_token = ? WHERE id = ?')->execute([$token, $user['id']]);
    sendVerificationEmail($user['email'], $user['name'], $token);
    $success = true;
}
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="row justify-content-center">
    <div class="col-md-6 text-center">
        <?php if (!empty($user['email_verified'])): ?>
            <div class="alert alert-success">
                <i class="ti ti-circle-check me-2"></i>Your email is already verified!
            </div>
            <a href="<?= APP_URL ?>/order/" class="btn btn-primary">Order Food</a>
        <?php else: ?>
            <i class="ti ti-mail text-primary" style="font-size:4rem;"></i>
            <h3 class="fw-bold mt-3 mb-2">Verify Your Email</h3>
            <p class="text-muted mb-4">
                Please verify your <strong>@purchase.edu</strong> email before placing an order.<br>
                Check your inbox for a verification link.
            </p>

            <?php if (!empty($_SESSION['flash_error'])): ?>
                <div class="alert alert-warning">
                    <i class="ti ti-alert-triangle me-2"></i><?= htmlspecialchars($_SESSION['flash_error'], ENT_QUOTES | ENT_HTML5) ?>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="ti ti-mail me-2"></i>Verification email resent! Check your inbox.
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <button type="submit" name="action" value="resend" class="btn btn-primary">
                    <i class="ti ti-send me-1"></i>Resend Verification Email
                </button>
            </form>
            <p class="text-muted small mt-3">
                Didn't receive it? Check your spam folder or contact support.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
