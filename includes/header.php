<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

startAppSession();
$currentUser = currentUser();

$cartCount = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $cartCount = getCartItemCount($_SESSION['cart']);
}

$hasCourierApp = false;
if ($currentUser && $currentUser['role'] === 'customer') {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT id FROM courier_applications WHERE user_id = ? LIMIT 1');
        $stmt->execute([$currentUser['id']]);
        $hasCourierApp = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $hasCourierApp = false;
    }
}

$theme      = getAppTheme();
$logoPath   = getAppSetting('app_logo_path');
$faviconPath = getAppSetting('app_favicon_path');

// Unread support badge (admin only)
$unreadSupport = 0;
if ($currentUser && $currentUser['role'] === 'admin') {
    $unreadSupport = countUnreadSupportMessages();
}
?>
<!doctype html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <title><?= htmlspecialchars(APP_NAME, ENT_QUOTES | ENT_HTML5) ?></title>
    <?php if ($faviconPath): ?>
        <link rel="icon" href="<?= htmlspecialchars(UPLOAD_URL . $faviconPath, ENT_QUOTES | ENT_HTML5) ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.29.0/dist/tabler-icons.min.css">
    <style>
        /* ── Purple brand accent ─────────────────────────────────────── */
        :root {
            --dd-purple: #6B46C1;
            --dd-purple-dark: #553497;
            --dd-purple-light: rgba(107, 70, 193, .12);
        }
        .btn-primary,
        .bg-primary { background-color: var(--dd-purple) !important; border-color: var(--dd-purple) !important; }
        .btn-primary:hover, .btn-primary:focus, .btn-primary:active { background-color: var(--dd-purple-dark) !important; border-color: var(--dd-purple-dark) !important; }
        .text-primary  { color: var(--dd-purple) !important; }
        .border-primary { border-color: var(--dd-purple) !important; }
        a { color: var(--dd-purple); }
        a:hover { color: var(--dd-purple-dark); }
        .badge.bg-purple { background-color: var(--dd-purple) !important; }
        .nav-pills .nav-link.active { background-color: var(--dd-purple) !important; }
        .form-check-input:checked { background-color: var(--dd-purple); border-color: var(--dd-purple); }
        .progress-bar { background-color: var(--dd-purple); }

        /* ── Page preloader ──────────────────────────────────────────── */
        #dd-preloader {
            position: fixed; inset: 0;
            background: var(--tblr-bg-surface, #fff);
            display: flex; align-items: center; justify-content: center;
            z-index: 9999;
            transition: opacity .3s ease;
        }
        [data-bs-theme="dark"] #dd-preloader { background: #1a1a2e; }
        .dd-spin {
            width: 48px; height: 48px;
            border: 4px solid #e9e9e9;
            border-top-color: var(--dd-purple);
            border-radius: 50%;
            animation: dd-spin .75s linear infinite;
        }
        @keyframes dd-spin { to { transform: rotate(360deg); } }

        /* ── Misc ────────────────────────────────────────────────────── */
        .navbar-brand-text { font-weight: 800; font-size: 1.15rem; letter-spacing: -.02em; }
        .restaurant-banner { width: 100%; height: 220px; object-fit: cover; border-radius: .5rem; margin-bottom: 1.5rem; }
        .menu-item-img { width: 80px; height: 80px; object-fit: cover; border-radius: .4rem; flex-shrink: 0; }
        .card-img-top.banner-thumb { height: 160px; object-fit: cover; }
        .hover-lift { transition: transform .15s ease, box-shadow .15s ease; }
        .hover-lift:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1.5rem rgba(0,0,0,.12) !important; }
    </style>
</head>
<body class="antialiased">

<div id="dd-preloader"><div class="dd-spin"></div></div>

<div class="wrapper">
    <header class="navbar navbar-expand-md d-print-none sticky-top">
        <div class="container-xl">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#navbar-menu" aria-controls="navbar-menu"
                    aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <a href="<?= APP_URL ?>/" class="navbar-brand navbar-brand-autodark pe-0 pe-md-3">
                <?php if ($logoPath): ?>
                    <img src="<?= htmlspecialchars(UPLOAD_URL . $logoPath, ENT_QUOTES | ENT_HTML5) ?>"
                         alt="<?= htmlspecialchars(APP_NAME, ENT_QUOTES | ENT_HTML5) ?>" height="32" class="me-2">
                <?php else: ?>
                    <span class="navbar-brand-text">
                        <i class="ti ti-motorbike me-1 text-primary"></i><?= htmlspecialchars(APP_NAME, ENT_QUOTES | ENT_HTML5) ?>
                    </span>
                <?php endif; ?>
            </a>

            <!-- Right-side items -->
            <div class="navbar-nav flex-row order-md-last">

                <!-- Theme toggle -->
                <div class="nav-item me-1">
                    <a href="<?= APP_URL ?>/api/theme?redirect=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/') ?>"
                       class="nav-link px-2" title="Switch theme">
                        <i class="ti <?= $theme === 'dark' ? 'ti-sun' : 'ti-moon' ?>"></i>
                    </a>
                </div>

                <?php if ($currentUser): ?>
                    <div class="nav-item dropdown">
                        <a href="#" class="nav-link d-flex lh-1 text-reset p-0 ps-2 align-items-center gap-2"
                           data-bs-toggle="dropdown" aria-label="Open user menu">
                            <div class="d-none d-xl-block">
                                <div class="fw-semibold lh-1"><?= htmlspecialchars($currentUser['name'], ENT_QUOTES | ENT_HTML5) ?></div>
                                <div class="mt-1 small text-muted"><?= ucfirst($currentUser['role']) ?></div>
                            </div>
                            <i class="ti ti-user-circle fs-4 d-xl-none"></i>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                            <a href="<?= APP_URL ?>/auth/logout" class="dropdown-item text-danger">
                                <i class="ti ti-logout dropdown-item-icon"></i> Sign out
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Nav links -->
            <div class="collapse navbar-collapse" id="navbar-menu">
                <ul class="navbar-nav">
                    <?php if (!$currentUser): ?>
                        <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/auth/login"><i class="ti ti-login me-1"></i>Login</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/auth/register"><i class="ti ti-user-plus me-1"></i>Register</a></li>
                    <?php elseif ($currentUser['role'] === 'admin'): ?>
                        <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/admin/dashboard"><i class="ti ti-layout-dashboard me-1"></i>Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/admin/restaurants"><i class="ti ti-tools-kitchen-2 me-1"></i>Restaurants</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/admin/orders"><i class="ti ti-package me-1"></i>Orders</a></li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= APP_URL ?>/admin/support">
                                <i class="ti ti-message-circle me-1"></i>Support
                                <?php if ($unreadSupport > 0): ?>
                                    <span class="badge bg-danger ms-1"><?= $unreadSupport ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/config/"><i class="ti ti-settings me-1"></i>Settings</a></li>
                    <?php elseif ($currentUser['role'] === 'courier'): ?>
                        <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/deliver/"><i class="ti ti-bike me-1"></i>Deliveries</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/order/"><i class="ti ti-salad me-1"></i>Order Food</a></li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/order/"><i class="ti ti-salad me-1"></i>Browse</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/order/history"><i class="ti ti-clock me-1"></i>My Orders</a></li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= APP_URL ?>/order/cart">
                                <i class="ti ti-shopping-cart me-1"></i>Cart
                                <?php if ($cartCount > 0): ?>
                                    <span class="badge bg-danger ms-1"><?= $cartCount ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php if (!$hasCourierApp): ?>
                            <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/deliver/apply"><i class="ti ti-bike me-1"></i>Become a Courier</a></li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </header>

    <div class="page-wrapper">
        <div class="page-body">
            <div class="container-xl">


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
    } catch (Throwable $e) {
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
