<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$user = currentUser();
$db   = getDB();

// Redirect if already a courier
if ($user['role'] === 'courier') {
    header('Location: ' . APP_URL . '/deliver/');
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
        $phone = trim($_POST['phone'] ?? '');

        if (empty($phone)) $errors[] = 'Phone number is required.';

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

                // Update user phone & MORE card photo
                $updStmt = $db->prepare('UPDATE users SET phone = ?, mor_photo_path = ? WHERE id = ?');
                $updStmt->execute([$phone, $photoPath, $user['id']]);

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
            <a href="<?= APP_URL ?>/order/" class="btn btn-primary">Back to Dashboard</a>
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
                    <h5 class="card-title mb-1"><i class="ti ti-id-badge-2 me-2 text-primary"></i>What is a MORE Card?</h5>
                    <p class="text-muted small mb-0">A MORE card is your SUNY Purchase meal plan card. As a courier, you'll use your MORE card to pay for dining hall orders on behalf of customers who will reimburse you via Stripe.</p>
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

                        <div class="mb-4">
                            <label class="form-label fw-semibold" for="mor_photo">MORE Card Photo <span class="text-muted">(optional but recommended)</span></label>
                            <input type="file" class="form-control" id="mor_photo" name="mor_photo" accept="image/*">
                            <div class="form-text">Upload a photo of your MORE card to verify you have a meal plan.</div>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="ti ti-send me-1"></i>Submit Application
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
