<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email.php';
startAppSession();

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/customer/dashboard.php');
    exit;
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim(strtolower($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        $phone    = trim($_POST['phone'] ?? '');
        $dorm     = trim($_POST['dorm'] ?? '');

        if (empty($name)) $errors[] = 'Name is required.';
        if (empty($email)) {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        } elseif (strtolower(explode('@', $email)[1] ?? '') !== 'purchase.edu') {
            $errors[] = 'You must use a @purchase.edu email address.';
        }
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm) $errors[] = 'Passwords do not match.';

        if (empty($errors)) {
            try {
                $db = getDB();
                $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $errors[] = 'An account with this email already exists.';
                } else {
                    $hash  = password_hash($password, PASSWORD_DEFAULT);
                    $token = bin2hex(random_bytes(32));
                    $stmt  = $db->prepare(
                        'INSERT INTO users (name, email, password_hash, phone, dorm, email_verify_token) VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$name, $email, $hash, $phone, $dorm, $token]);
                    $userId = (int)$db->lastInsertId();

                    $user = ['id' => $userId, 'name' => $name, 'email' => $email];
                    sendVerificationEmail($user, $token);
                    $success = true;
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
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h2 class="card-title text-center mb-4">Create Account</h2>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <strong>Registration successful!</strong> Please check your @purchase.edu email to verify your account before logging in.
                    </div>
                    <div class="text-center">
                        <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-primary">Go to Login</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($errors as $err): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($err, ENT_QUOTES | ENT_HTML5) ?></div>
                    <?php endforeach; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="name">Full Name</label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES | ENT_HTML5) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="email">SUNY Purchase Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   placeholder="yourname@purchase.edu"
                                   value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES | ENT_HTML5) ?>" required>
                            <div class="form-text">Must be a @purchase.edu address.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="password">Password</label>
                            <input type="password" class="form-control" id="password" name="password"
                                   minlength="8" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="confirm_password">Confirm Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                   minlength="8" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="phone">Phone Number <span class="text-muted">(optional)</span></label>
                            <input type="tel" class="form-control" id="phone" name="phone"
                                   value="<?= htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES | ENT_HTML5) ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="dorm">Dorm / Building <span class="text-muted">(optional)</span></label>
                            <input type="text" class="form-control" id="dorm" name="dorm"
                                   value="<?= htmlspecialchars($_POST['dorm'] ?? '', ENT_QUOTES | ENT_HTML5) ?>">
                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-primary btn-lg">Create Account</button>
                        </div>
                    </form>

                    <p class="text-center mt-3 mb-0">
                        Already have an account? <a href="<?= APP_URL ?>/auth/login.php">Sign in</a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
