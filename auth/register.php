<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email.php';
startAppSession();

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/order/');
    exit;
}

const DORM_BUILDINGS = [
    'Central', 'Crossroads', 'Farside', 'Outback',
    'Fort Awesome', 'Wayback', 'Commons', 'Olde', 'Alumni',
];

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        $name    = trim($_POST['name']    ?? '');
        $email   = trim(strtolower($_POST['email']   ?? ''));
        $password = $_POST['password']  ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        $phone   = trim($_POST['phone']   ?? '');
        $dorm    = trim($_POST['dorm']    ?? '');

        if (empty($name))  $errors[] = 'Full name is required.';
        if (empty($email)) {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        } elseif (strtolower(substr($email, -12)) !== 'purchase.edu') {
            $errors[] = 'You must use a @purchase.edu email address.';
        }
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm)  $errors[] = 'Passwords do not match.';
        if (!in_array($dorm, DORM_BUILDINGS, true)) $errors[] = 'Please select your dorm building.';

        // MORE card photo (required)
        $moreCardPhoto = null;
        if (empty($_FILES['more_card_photo']['tmp_name']) || $_FILES['more_card_photo']['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'A photo of your MORE card is required.';
        } else {
            require_once __DIR__ . '/../includes/functions.php';
            $moreCardPhoto = uploadFile($_FILES['more_card_photo'], UPLOAD_DIR);
            if ($moreCardPhoto === null) $errors[] = 'MORE card photo must be a valid image (JPG, PNG, etc.).';
        }

        // Selfie (required)
        $selfiePath = null;
        if (empty($_FILES['selfie']['tmp_name']) || $_FILES['selfie']['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'A selfie photo is required for identity verification.';
        } else {
            $selfiePath = uploadFile($_FILES['selfie'], UPLOAD_DIR);
            if ($selfiePath === null) $errors[] = 'Selfie must be a valid image (JPG, PNG, etc.).';
        }

        if (empty($errors)) {
            try {
                $db   = getDB();
                $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $errors[] = 'An account with this email already exists.';
                } else {
                    $hash  = password_hash($password, PASSWORD_DEFAULT);
                    $token = bin2hex(random_bytes(32));
                    $stmt  = $db->prepare(
                        'INSERT INTO users (name, email, password_hash, phone, dorm, more_card_photo, selfie_path, email_verify_token)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$name, $email, $hash, $phone, $dorm, $moreCardPhoto, $selfiePath, $token]);
                    $userId = (int)$db->lastInsertId();
                    sendVerificationEmail(['id' => $userId, 'name' => $name, 'email' => $email], $token);
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
    <div class="col-md-7 col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h2 class="card-title text-center mb-1 fw-bold">Create Account</h2>
                <p class="text-center text-muted mb-4 small">SUNY Purchase students only &mdash; @purchase.edu required</p>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="ti ti-circle-check me-2"></i>
                        <strong>Registration successful!</strong> Please check your @purchase.edu email to verify your account before logging in.
                    </div>
                    <div class="text-center">
                        <a href="<?= APP_URL ?>/auth/login" class="btn btn-primary">
                            <i class="ti ti-login me-1"></i> Go to Login
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($errors as $err): ?>
                        <div class="alert alert-danger py-2"><i class="ti ti-alert-circle me-1"></i><?= htmlspecialchars($err, ENT_QUOTES | ENT_HTML5) ?></div>
                    <?php endforeach; ?>

                    <form method="POST" action="" enctype="multipart/form-data">
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
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold" for="password">Password</label>
                                <input type="password" class="form-control" id="password" name="password" minlength="8" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold" for="confirm_password">Confirm Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="phone">Phone Number <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="tel" class="form-control" id="phone" name="phone"
                                   value="<?= htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES | ENT_HTML5) ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="dorm">Dorm Building</label>
                            <select class="form-select" id="dorm" name="dorm" required>
                                <option value="">-- Select your dorm --</option>
                                <?php foreach (DORM_BUILDINGS as $b): ?>
                                    <option value="<?= htmlspecialchars($b) ?>"
                                        <?= (($_POST['dorm'] ?? '') === $b) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($b) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <hr class="my-4">
                        <p class="fw-semibold mb-3">
                            <i class="ti ti-id-badge-2 me-1 text-primary"></i>Identity Verification
                        </p>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="more_card_photo">
                                MORE Card Photo <span class="badge bg-danger ms-1">Required</span>
                            </label>
                            <input type="file" class="form-control" id="more_card_photo" name="more_card_photo"
                                   accept="image/*" required>
                            <div class="form-text">Upload a clear photo of your SUNY Purchase MORE card (meal plan card).</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="selfie">
                                Selfie / ID Photo <span class="badge bg-danger ms-1">Required</span>
                            </label>
                            <input type="file" class="form-control" id="selfie" name="selfie"
                                   accept="image/*" required>
                            <div class="form-text">Upload a clear selfie or photo of your student ID for identity verification.</div>
                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="ti ti-user-plus me-1"></i> Create Account
                            </button>
                        </div>
                    </form>

                    <p class="text-center mt-3 mb-0 small">
                        Already have an account? <a href="<?= APP_URL ?>/auth/login">Sign in</a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
