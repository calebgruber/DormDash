<?php
require_once __DIR__ . '/../includes/auth.php';
startAppSession();

if (isLoggedIn()) {
    $u = currentUser();
    if ($u['role'] === 'admin') {
        header('Location: ' . APP_URL . '/admin/dashboard');
    } else {
        header('Location: ' . APP_URL . '/order/');
    }
    exit;
}

$errors  = [];
$success = false;
$verified = isset($_GET['verified']) && $_GET['verified'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        $email    = trim(strtolower($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $errors[] = 'Email and password are required.';
        } else {
            try {
                $db = getDB();
                $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if (!$user || !password_verify($password, $user['password_hash'])) {
                    $errors[] = 'Invalid email or password.';
                } elseif (!$user['email_verified']) {
                    $errors[] = 'Please verify your email address before logging in. Check your inbox.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user']    = $user;

                    if ($user['role'] === 'admin') {
                        header('Location: ' . APP_URL . '/admin/dashboard');
                    } else {
                        // Both customers and couriers go to the order page by default
                        header('Location: ' . APP_URL . '/order/');
                    }
                    exit;
                }
            } catch (PDOException $e) {
                $errors[] = 'Database error. Please try again.';
            }
        }
    }
}

$csrf = generateCsrfToken();

require_once __DIR__ . '/../includes/functions.php';
$loginBanner = getAppSetting('login_banner_path');
$appTheme    = getAppTheme();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="row g-0 align-items-stretch" style="min-height:70vh;">
    <!-- Left: banner -->
    <div class="col-md-7 d-none d-md-flex align-items-center justify-content-center rounded-start overflow-hidden"
         style="<?php if ($loginBanner): ?>background:url('<?= htmlspecialchars(UPLOAD_URL . $loginBanner, ENT_QUOTES | ENT_HTML5) ?>') center/cover no-repeat;<?php else: ?>background:linear-gradient(135deg,#6B46C1,#2d1b69);<?php endif; ?>min-height:460px;">
        <div class="text-center p-5" style="background:rgba(0,0,0,0.35);border-radius:1rem;">
            <i class="ti ti-motorbike text-white" style="font-size:4rem;"></i>
            <h2 class="text-white fw-bold mt-3 mb-2"><?= htmlspecialchars(APP_NAME, ENT_QUOTES | ENT_HTML5) ?></h2>
            <p class="text-white-50 mb-0">Campus food delivery at SUNY Purchase</p>
        </div>
    </div>

    <!-- Right: form -->
    <div class="col-md-5">
        <div class="card h-100 border-0 shadow-none rounded-0">
            <div class="card-body d-flex flex-column justify-content-center p-4 p-lg-5">
                <h2 class="fw-bold mb-1">Sign In</h2>
                <p class="text-muted mb-4 small">Welcome back to <?= htmlspecialchars(APP_NAME, ENT_QUOTES | ENT_HTML5) ?></p>

                <?php if ($verified): ?>
                    <div class="alert alert-success"><i class="ti ti-circle-check me-2"></i>Email verified! You can now log in.</div>
                <?php endif; ?>

                <?php foreach ($errors as $err): ?>
                    <div class="alert alert-danger"><i class="ti ti-alert-circle me-2"></i><?= htmlspecialchars($err, ENT_QUOTES | ENT_HTML5) ?></div>
                <?php endforeach; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="email">Email</label>
                        <input type="email" class="form-control form-control-lg" id="email" name="email"
                               placeholder="yourname@purchase.edu"
                               value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES | ENT_HTML5) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="password">Password</label>
                        <input type="password" class="form-control form-control-lg" id="password" name="password" required>
                    </div>

                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="ti ti-login me-1"></i>Sign In
                        </button>
                    </div>
                </form>

                <p class="text-center mt-4 mb-0">
                    No account? <a href="<?= APP_URL ?>/auth/register" class="fw-semibold">Register</a>
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
