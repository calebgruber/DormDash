<?php
require_once __DIR__ . '/../includes/auth.php';
startAppSession();

$token   = trim($_GET['token'] ?? '');
$message = '';
$isError = false;

if (empty($token)) {
    $isError = true;
    $message = 'Invalid verification link.';
} else {
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT id FROM users WHERE email_verify_token = ? AND email_verified = 0');
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if ($user) {
            $upd = $db->prepare('UPDATE users SET email_verified = 1, email_verify_token = NULL WHERE id = ?');
            $upd->execute([$user['id']]);
            header('Location: ' . APP_URL . '/auth/login.php?verified=1');
            exit;
        } else {
            $isError = true;
            $message = 'This verification link is invalid or has already been used.';
        }
    } catch (PDOException $e) {
        $isError = true;
        $message = 'Database error. Please try again.';
    }
}
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <?php if ($isError): ?>
            <div class="alert alert-danger">
                <strong>Verification Failed:</strong> <?= htmlspecialchars($message, ENT_QUOTES | ENT_HTML5) ?>
            </div>
            <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-primary">Go to Login</a>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
