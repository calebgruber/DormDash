<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

startAppSession();
$currentUser = currentUser();

// Compute cart count for badge
$cartCount = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $cartCount = getCartItemCount($_SESSION['cart']);
}

// Check if user has a courier application
$hasCourierApp = false;
if ($currentUser && $currentUser['role'] === 'customer') {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT id FROM courier_applications WHERE user_id = ? LIMIT 1');
        $stmt->execute([$currentUser['id']]);
        $hasCourierApp = (bool)$stmt->fetch();
    } catch (Exception $e) {
        $hasCourierApp = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { padding-top: 56px; }
        .navbar-brand { font-weight: 700; font-size: 1.4rem; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <div class="container">
        <a class="navbar-brand" href="<?= APP_URL ?>/index.php">DormDash 🍔</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain"
                aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <?php if (!$currentUser): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/auth/login.php">Login</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/auth/register.php">Register</a>
                    </li>
                <?php elseif ($currentUser['role'] === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/admin/dashboard.php">Admin Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/auth/logout.php">Logout</a>
                    </li>
                <?php elseif ($currentUser['role'] === 'courier'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/courier/dashboard.php">Courier Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/customer/dashboard.php">Order Food</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/auth/logout.php">Logout</a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/customer/dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/customer/orders.php">My Orders</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/customer/cart.php">
                            Cart
                            <?php if ($cartCount > 0): ?>
                                <span class="badge bg-danger rounded-pill"><?= $cartCount ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php if (!$hasCourierApp): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= APP_URL ?>/courier/apply.php">Become a Courier</a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/auth/logout.php">Logout</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
<main class="container my-4">
