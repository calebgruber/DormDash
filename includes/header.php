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
        /* ── Purple brand accent + page canvas ──────────────────────── */
        :root {
            --dd-purple: #6B46C1;
            --dd-purple-dark: #553497;
            --dd-purple-light: rgba(107, 70, 193, .12);
            /* Off-white page bg makes white cards pop */
            --tblr-body-bg: #f4f4f8;
        }
        :root[data-bs-theme="dark"] {
            --tblr-body-bg: #111122;
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
        .card-img-top.banner-thumb { height: 160px; object-fit: cover; border-radius: 0; }

        /* ── Card System — Framer-precision layered shadow ───────────── */
        .card {
            border-radius: 5px !important;
            border: none !important;
            box-shadow:
                rgba(0, 0, 0, 0.18) 0px 0.602187px 0.602187px -1.25px,
                rgba(0, 0, 0, 0.16) 0px 2.28853px  2.28853px  -2.5px,
                rgba(0, 0, 0, 0.06) 0px 10px       10px       -3.75px !important;
            background-color: #ffffff;
            overflow: hidden;
            transition: box-shadow .22s ease, transform .22s ease;
        }
        /* card-header: transparent + a single hairline divider (skip when a bg-* class is present) */
        .card-header:not([class*="bg-"]) {
            background-color: transparent;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            padding: 0.875rem 1.25rem;
        }
        .card-footer:not([class*="bg-"]) {
            background-color: transparent;
            border-top: 1px solid rgba(0, 0, 0, 0.06);
        }

        /* Hover lift — tighter shadow that matches the base system */
        .hover-lift  { transition: transform .22s ease, box-shadow .22s ease; }
        .hover-card  { transition: transform .22s ease, box-shadow .22s ease; }
        .hover-lift:hover,
        .hover-card:hover {
            transform: translateY(-2px);
            box-shadow:
                rgba(0, 0, 0, 0.14) 0px 1px    1px    -1.25px,
                rgba(0, 0, 0, 0.12) 0px 4.5px  4.5px  -2.5px,
                rgba(0, 0, 0, 0.09) 0px 18px   18px   -3.75px !important;
        }

        /* ── Stat card helpers (admin dashboard) ─────────────────────── */
        .dd-stat-card { position: relative; }
        .dd-stat-accent {
            position: absolute;
            top: 0; left: 0;
            width: 4px; height: 100%;
        }
        .dd-stat-value { font-size: 2rem; font-weight: 700; line-height: 1.1; color: var(--tblr-body-color); }
        .dd-stat-label { font-size: 0.8125rem; color: var(--tblr-muted, #6c757d); margin-top: 0.3rem; letter-spacing: 0.01em; }
        .dd-stat-icon {
            width: 46px; height: 46px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        /* ── Dark-mode cards ─────────────────────────────────────────── */
        [data-bs-theme="dark"] .card {
            background-color: var(--tblr-bg-surface, #24253a) !important;
            box-shadow:
                rgba(0, 0, 0, 0.50) 0px 0.602187px 0.602187px -1.25px,
                rgba(0, 0, 0, 0.42) 0px 2.28853px  2.28853px  -2.5px,
                rgba(0, 0, 0, 0.22) 0px 10px       10px       -3.75px !important;
        }
        [data-bs-theme="dark"] .card-header:not([class*="bg-"]) {
            border-bottom-color: rgba(255, 255, 255, 0.06);
        }
        [data-bs-theme="dark"] .card-footer:not([class*="bg-"]) {
            border-top-color: rgba(255, 255, 255, 0.06);
        }
        /* legacy bg-white headers still need fixing in dark mode */
        [data-bs-theme="dark"] .card-header.bg-white,
        [data-bs-theme="dark"] .card-header.bg-white.fw-bold {
            background-color: transparent !important;
            border-bottom: 1px solid rgba(255,255,255,0.06) !important;
            color: var(--tblr-body-color) !important;
        }
        [data-bs-theme="dark"] .dd-stat-value { color: var(--tblr-body-color, #e9ecef); }

        /* ── Dark-mode table header fix ──────────────────────────────── */
        [data-bs-theme="dark"] .table > thead > tr > th,
        [data-bs-theme="dark"] .table > thead > tr > td {
            color: var(--tblr-body-color) !important;
            background-color: rgba(255,255,255,.06) !important;
            border-color: rgba(255,255,255,.1) !important;
        }
        [data-bs-theme="dark"] .table > tbody > tr > td,
        [data-bs-theme="dark"] .table > tbody > tr > th {
            color: var(--tblr-body-color) !important;
            border-color: rgba(255,255,255,.07) !important;
        }

        /* ── Soft / ghost badges (semi-transparent bg, full-colour text) */
        .badge.bg-danger    { background-color: rgba(214,57,57,.15)    !important; color: #d63939 !important; }
        .badge.bg-success   { background-color: rgba(47,179,68,.15)    !important; color: #2fb344 !important; }
        .badge.bg-warning   { background-color: rgba(248,174,0,.18)    !important; color: #a07800 !important; }
        .badge.bg-info      { background-color: rgba(74,181,226,.15)   !important; color: #1a7ead !important; }
        .badge.bg-secondary { background-color: rgba(134,142,150,.18)  !important; color: #68737d !important; }
        .badge.bg-purple    { background-color: var(--dd-purple-light, rgba(139,92,246,.15)) !important; color: var(--dd-purple, #6839c6) !important; }
        .badge.bg-primary   { background-color: var(--dd-purple-light, rgba(139,92,246,.15)) !important; color: var(--dd-purple, #6839c6) !important; }
        [data-bs-theme="dark"] .badge.bg-warning   { color: #f8ae00 !important; }
        [data-bs-theme="dark"] .badge.bg-secondary { color: #9ba5af !important; }
        [data-bs-theme="dark"] .badge.bg-danger     { color: #e85656 !important; }
        [data-bs-theme="dark"] .badge.bg-info       { color: #4ab5e3 !important; }

        /* ── Chat bubbles ─────────────────────────────────────────────── */
        .chat-bubble-received {
            background-color: var(--tblr-bg-surface-secondary, #e9ecef);
            color: var(--tblr-body-color);
            border: 1px solid var(--tblr-border-color, #dee2e6);
        }
        .chat-typing-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: currentColor; animation: typingPulse 1.2s infinite; }
        .chat-typing-dot:nth-child(2) { animation-delay: .2s; }
        .chat-typing-dot:nth-child(3) { animation-delay: .4s; }
        @keyframes typingPulse { 0%,80%,100%{ opacity:.25; transform:scale(.85); } 40%{ opacity:1; transform:scale(1); } }

        /* ── Card escape hatches — let shadow-none / rounded-0 still work ── */
        .card.shadow-none { box-shadow: none !important; }
        .card.rounded-0   { border-radius: 0 !important; }
        .card.rounded     { border-radius: 4px !important; }

        /* ── Pending-order accent strip (replaces broken border-start) ── */
        .dd-card-pending { position: relative; }
        .dd-card-pending::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 4px; height: 100%;
            background-color: #f59e0b;
            border-radius: 5px 0 0 5px; /* matches .card border-radius */
            z-index: 1;
        }

        /* ── Buttons — 6 px radius, 500 weight, clean hover ────────────── */
        .btn {
            border-radius: 6px !important;
            font-weight: 500;
            letter-spacing: 0.01em;
            transition: box-shadow .18s ease, background-color .18s ease,
                        border-color .18s ease, transform .12s ease;
        }
        .btn:not(:disabled):not(.disabled):hover  { transform: translateY(-1px); }
        .btn:not(:disabled):not(.disabled):active { transform: translateY(0);    }

        /* btn-outline-secondary: subtler border, no harsh gray box */
        .btn-outline-secondary {
            border-color: rgba(0, 0, 0, 0.18) !important;
            color: var(--tblr-body-color, #333) !important;
        }
        .btn-outline-secondary:hover {
            background-color: rgba(0, 0, 0, 0.04) !important;
            border-color: rgba(0, 0, 0, 0.28) !important;
            color: var(--tblr-body-color, #333) !important;
        }
        [data-bs-theme="dark"] .btn-outline-secondary {
            border-color: rgba(255, 255, 255, 0.18) !important;
        }
        [data-bs-theme="dark"] .btn-outline-secondary:hover {
            background-color: rgba(255, 255, 255, 0.07) !important;
            border-color: rgba(255, 255, 255, 0.30) !important;
        }

        /* btn-outline-primary mirrors brand purple */
        .btn-outline-primary {
            border-color: var(--dd-purple) !important;
            color: var(--dd-purple) !important;
        }
        .btn-outline-primary:hover {
            background-color: var(--dd-purple) !important;
            color: #fff !important;
        }

        /* btn-outline-danger */
        .btn-outline-danger:hover { color: #fff !important; }

        /* Input-group buttons keep flush joins */
        .input-group > .btn { border-radius: 0 !important; }
        .input-group > .btn:first-child { border-radius: 6px 0 0 6px !important; }
        .input-group > .btn:last-child  { border-radius: 0 6px 6px 0 !important; }
        .input-group > .btn:only-child  { border-radius: 6px !important; }

        /* ── Form controls — matching 6 px radius + purple focus ────────── */
        .form-control, .form-select, .input-group-text {
            border-radius: 6px !important;
            border-color: rgba(0, 0, 0, 0.14);
            transition: border-color .18s ease, box-shadow .18s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--dd-purple) !important;
            box-shadow: 0 0 0 3px var(--dd-purple-light) !important;
        }
        .input-group .form-control:not(:first-child),
        .input-group .form-select:not(:first-child)    { border-radius: 0 6px 6px 0 !important; }
        .input-group .form-control:not(:last-child),
        .input-group .form-select:not(:last-child)     { border-radius: 6px 0 0 6px !important; }
        .input-group .input-group-text:first-child     { border-radius: 6px 0 0 6px !important; }
        .input-group .input-group-text:last-child      { border-radius: 0 6px 6px 0 !important; }
        [data-bs-theme="dark"] .form-control,
        [data-bs-theme="dark"] .form-select,
        [data-bs-theme="dark"] .input-group-text {
            border-color: rgba(255, 255, 255, 0.12);
        }

        /* ── Alerts — matching radius, no harsh border ───────────────────── */
        .alert {
            border-radius: 6px !important;
            border: none;
        }
        .alert.alert-warning  { border-left: 3px solid #f59e0b !important; }
        .alert.alert-danger   { border-left: 3px solid #d63939 !important; }
        .alert.alert-success  { border-left: 3px solid #2fb344 !important; }
        .alert.alert-info     { border-left: 3px solid #1a7ead !important; }
        .alert.alert-primary  { border-left: 3px solid var(--dd-purple) !important; }
        .alert.alert-secondary { border-left: 3px solid #68737d !important; }

        /* ── Modals — 8 px radius, no harsh border ───────────────────────── */
        .modal-content {
            border-radius: 8px !important;
            border: none !important;
            box-shadow:
                rgba(0, 0, 0, 0.12) 0px 4px 6px -1px,
                rgba(0, 0, 0, 0.08) 0px 16px 40px -4px !important;
        }
        .modal-header { border-bottom: 1px solid rgba(0, 0, 0, 0.06); }
        .modal-footer { border-top:    1px solid rgba(0, 0, 0, 0.06); }
        [data-bs-theme="dark"] .modal-header { border-bottom-color: rgba(255, 255, 255, 0.07); }
        [data-bs-theme="dark"] .modal-footer { border-top-color:    rgba(255, 255, 255, 0.07); }

        /* ── Dropdown menus — matching radius, clean shadow ─────────────── */
        .dropdown-menu {
            border-radius: 8px !important;
            border: none !important;
            box-shadow:
                rgba(0, 0, 0, 0.10) 0px 2px 4px,
                rgba(0, 0, 0, 0.08) 0px 8px 24px !important;
        }
        .dropdown-item { border-radius: 4px; margin: 1px 4px; padding-left: 10px; padding-right: 10px; }
        .dropdown-item:first-child { margin-top: 4px; }
        .dropdown-item:last-child  { margin-bottom: 4px; }
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
                        <!-- Admin preview modes -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                                <i class="ti ti-user-cog me-1"></i>Preview As
                            </a>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="<?= APP_URL ?>/order/">
                                    <i class="ti ti-user me-2"></i>Customer View
                                </a>
                                <a class="dropdown-item" href="<?= APP_URL ?>/deliver/">
                                    <i class="ti ti-bike me-2"></i>Courier View
                                </a>
                            </div>
                        </li>
                    <?php elseif ($currentUser['role'] === 'courier'): ?>
                        <!-- Courier sees customer nav by default + courier mode toggle -->
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
                        <?php if ($currentUser['courier_approved']): ?>
                        <li class="nav-item">
                            <a class="nav-link fw-semibold text-success" href="<?= APP_URL ?>/deliver/">
                                <i class="ti ti-bike me-1"></i>Courier Mode
                            </a>
                        </li>
                        <?php endif; ?>
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

