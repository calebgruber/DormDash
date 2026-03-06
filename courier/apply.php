<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$user = currentUser();
$db   = getDB();

// Redirect if already a courier
if ($user['role'] === 'courier') {
    header('Location: ' . APP_URL . '/courier/dashboard.php');
    exit;
}

// Check for existing application
$appStmt = $db->prepare('SELECT * FROM courier_applications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
$appStmt->execute([$user['id']]);
$existingApp = $appStmt->fetch();

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } elseif ($existingApp && in_array($existingApp['status'], ['pending', 'approved'], true)) {
        $errors[] = 'You already have a pending or approved application.';
    } else {
        $phone   = trim($_POST['phone'] ?? '');
        $morLast4 = preg_replace('/\D/', '', trim($_POST['mor_last4'] ?? ''));

        if (empty($phone)) $errors[] = 'Phone number is required.';
        if (strlen($morLast4) !== 4) $errors[] = 'MOR card last 4 digits must be exactly 4 digits.';

        $photoPath = null;
        if (isset($_FILES['mor_photo']) && $_FILES['mor_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            require_once __DIR__ . '/../config/app.php';
            $photoPath = uploadFile($_FILES['mor_photo'], UPLOAD_DIR);
            if ($photoPath === null) {
                $errors[] = 'Invalid photo upload. Please upload a valid image file (JPG, PNG, etc.).';
            }
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                // Update user phone & MOR info
                $updStmt = $db->prepare('UPDATE users SET phone = ?, mor_last4 = ?, mor_photo_path = ? WHERE id = ?');
                $updStmt->execute([$phone, $morLast4, $photoPath, $user['id']]);

                // Create application
                $insStmt = $db->prepare('INSERT INTO courier_applications (user_id) VALUES (?)');
                $insStmt->execute([$user['id']]);

                $db->commit();
                refreshUser();
                $success = true;
                $existingApp = ['status' => 'pending'];
            } catch (PDOException $e) {
                $db->rollBack();
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
        <h2 class="mb-4">🚴 Become a Courier</h2>

        <?php if ($success || ($existingApp && $existingApp['status'] === 'pending')): ?>
            <div class="alert alert-success">
                <strong>Application submitted!</strong> Our team will review your application and notify you by email.
            </div>
            <a href="<?= APP_URL ?>/customer/dashboard.php" class="btn btn-primary">Back to Dashboard</a>
        <?php elseif ($existingApp && $existingApp['status'] === 'rejected'): ?>
            <div class="alert alert-warning mb-4">
                Your previous application was rejected. You may reapply below.
            </div>
        <?php endif; ?>

        <?php if (!$success && !($existingApp && $existingApp['status'] === 'pending')): ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($err, ENT_QUOTES | ENT_HTML5) ?></div>
            <?php endforeach; ?>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-1">What is a MOR Card?</h5>
                    <p class="text-muted small mb-0">A MOR (Meal on Record) card is your SUNY Purchase meal plan card. Couriers use this to pay for dining hall orders on behalf of customers. Your last 4 digits help verify your identity.</p>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="phone">Phone Number</label>
                            <input type="tel" class="form-control" id="phone" name="phone"
                                   value="<?= htmlspecialchars($_POST['phone'] ?? $user['phone'] ?? '', ENT_QUOTES | ENT_HTML5) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="mor_last4">MOR Card Last 4 Digits</label>
                            <input type="text" class="form-control" id="mor_last4" name="mor_last4"
                                   maxlength="4" pattern="\d{4}" placeholder="XXXX"
                                   value="<?= htmlspecialchars($_POST['mor_last4'] ?? '', ENT_QUOTES | ENT_HTML5) ?>" required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="mor_photo">MOR Card Photo <span class="text-muted">(optional but recommended)</span></label>
                            <input type="file" class="form-control" id="mor_photo" name="mor_photo" accept="image/*">
                            <div class="form-text">Upload a photo of your MOR card (image only). This helps verify your identity.</div>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">Submit Application</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
