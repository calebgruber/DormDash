<?php
require_once __DIR__ . '/../includes/auth.php';
startAppSession();

if (isLoggedIn()) {
    $u = currentUser();
    if ($u['role'] === 'admin') {
        header('Location: ' . APP_URL . '/admin/dashboard');
    } elseif ($u['role'] === 'courier' && $u['courier_approved']) {
        header('Location: ' . APP_URL . '/deliver/');
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
                    } elseif ($user['role'] === 'courier' && $user['courier_approved']) {
                        header('Location: ' . APP_URL . '/deliver/');
                    } else {
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
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h2 class="card-title text-center mb-4">Sign In</h2>

                <?php if ($verified): ?>
                    <div class="alert alert-success">Email verified! You can now log in.</div>
                <?php endif; ?>

                <?php foreach ($errors as $err): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($err, ENT_QUOTES | ENT_HTML5) ?></div>
                <?php endforeach; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="email">Email</label>
                        <input type="email" class="form-control" id="email" name="email"
                               placeholder="yourname@purchase.edu"
                               value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES | ENT_HTML5) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="password">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>

                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary btn-lg">Sign In</button>
                    </div>
                </form>

                <p class="text-center mt-3 mb-0">
                    No account? <a href="<?= APP_URL ?>/auth/register.php">Register</a>
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
