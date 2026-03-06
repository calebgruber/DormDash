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

$theme = getAppTheme();
?>
<!doctype html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <title><?= APP_NAME ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <style>
        .navbar-brand-text { font-weight: 800; font-size: 1.2rem; letter-spacing: -0.02em; }
    </style>
</head>
<body class="antialiased">
<div class="wrapper">
    <header class="navbar navbar-expand-md d-print-none">
        <div class="container-xl">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#navbar-menu" aria-controls="navbar-menu"
                    aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <a href="<?= APP_URL ?>/index.php" class="navbar-brand navbar-brand-autodark pe-0 pe-md-3">
                <span class="navbar-brand-text">DormDash 🍔</span>
            </a>

            <!-- Right-side items: theme toggle + user dropdown -->
            <div class="navbar-nav flex-row order-md-last">

                <!-- Theme toggle -->
                <div class="nav-item me-1">
                    <a href="<?= APP_URL ?>/api/theme.php?redirect=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/') ?>"
                       class="nav-link px-2" title="Switch to <?= $theme === 'dark' ? 'light' : 'dark' ?> mode">
                        <?php if ($theme === 'dark'): ?>
                            <!-- Sun icon -->
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="5"/>
                                <path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
                            </svg>
                        <?php else: ?>
                            <!-- Moon icon -->
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                            </svg>
                        <?php endif; ?>
                    </a>
                </div>

                <?php if ($currentUser): ?>
                    <div class="nav-item dropdown">
                        <a href="#" class="nav-link d-flex lh-1 text-reset p-0 ps-2"
                           data-bs-toggle="dropdown" aria-label="Open user menu">
                            <div class="d-none d-xl-block ps-2">
                                <div class="fw-semibold"><?= htmlspecialchars($currentUser['name'], ENT_QUOTES | ENT_HTML5) ?></div>
                                <div class="mt-1 small text-muted"><?= ucfirst($currentUser['role']) ?></div>
                            </div>
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon ms-1 d-xl-none" width="20" height="20"
                                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/>
                            </svg>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                            <a href="<?= APP_URL ?>/auth/logout.php" class="dropdown-item">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon dropdown-item-icon" width="16" height="16"
                                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>
                                </svg>
                                Sign out
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Nav links -->
            <div class="collapse navbar-collapse" id="navbar-menu">
                <div class="d-flex flex-column flex-md-row flex-fill align-items-stretch align-items-md-center">
                    <ul class="navbar-nav">
                        <?php if (!$currentUser): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= APP_URL ?>/auth/login.php">
                                    <span class="nav-link-title">Login</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= APP_URL ?>/auth/register.php">
                                    <span class="nav-link-title">Register</span>
                                </a>
                            </li>
                        <?php elseif ($currentUser['role'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= APP_URL ?>/admin/dashboard.php">
                                    <span class="nav-link-title">Admin</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= APP_URL ?>/config/">
                                    <span class="nav-link-title">Settings</span>
                                </a>
                            </li>
                        <?php elseif ($currentUser['role'] === 'courier'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= APP_URL ?>/deliver/">
                                    <span class="nav-link-title">Deliveries</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= APP_URL ?>/order/">
                                    <span class="nav-link-title">Order Food</span>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= APP_URL ?>/order/">
                                    <span class="nav-link-title">Browse</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= APP_URL ?>/order/history.php">
                                    <span class="nav-link-title">My Orders</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?= APP_URL ?>/order/cart.php">
                                    <span class="nav-link-title">
                                        Cart
                                        <?php if ($cartCount > 0): ?>
                                            <span class="badge bg-red ms-1"><?= $cartCount ?></span>
                                        <?php endif; ?>
                                    </span>
                                </a>
                            </li>
                            <?php if (!$hasCourierApp): ?>
                                <li class="nav-item">
                                    <a class="nav-link" href="<?= APP_URL ?>/deliver/apply.php">
                                        <span class="nav-link-title">Become a Courier</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <div class="page-wrapper">
        <div class="page-body">
            <div class="container-xl">
