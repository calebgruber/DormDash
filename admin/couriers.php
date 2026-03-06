<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db      = getDB();
$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';
        $appId  = (int)($_POST['application_id'] ?? 0);

        if ($action === 'approve' && $appId > 0) {
            // Get the user id from the application
            $appStmt = $db->prepare('SELECT user_id FROM courier_applications WHERE id = ?');
            $appStmt->execute([$appId]);
            $app = $appStmt->fetch();
            if ($app) {
                $db->beginTransaction();
                $db->prepare('UPDATE courier_applications SET status = "approved", updated_at = NOW() WHERE id = ?')->execute([$appId]);
                $db->prepare('UPDATE users SET role = "courier", courier_approved = 1 WHERE id = ?')->execute([$app['user_id']]);
                $db->commit();
                $success = 'Courier approved.';
            }
        } elseif ($action === 'reject' && $appId > 0) {
            $db->prepare('UPDATE courier_applications SET status = "rejected", updated_at = NOW() WHERE id = ?')->execute([$appId]);
            $success = 'Application rejected.';
        }
    }
}

// Pending applications
$pending = $db->query(
    'SELECT ca.*, u.name, u.email, u.phone, u.mor_last4, u.mor_photo_path
     FROM courier_applications ca
     JOIN users u ON u.id = ca.user_id
     WHERE ca.status = "pending"
     ORDER BY ca.created_at ASC'
)->fetchAll();

// Approved couriers
$approved = $db->query(
    'SELECT u.id, u.name, u.email, u.phone, u.stripe_account_id, u.created_at
     FROM users u
     WHERE u.role = "courier" AND u.courier_approved = 1
     ORDER BY u.name ASC'
)->fetchAll();

// Rejected applications
$rejected = $db->query(
    'SELECT ca.*, u.name, u.email
     FROM courier_applications ca
     JOIN users u ON u.id = ca.user_id
     WHERE ca.status = "rejected"
     ORDER BY ca.updated_at DESC'
)->fetchAll();

$csrf = generateCsrfToken();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>🚴 Couriers</h2>
    <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn btn-outline-secondary btn-sm">← Dashboard</a>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e, ENT_QUOTES | ENT_HTML5) ?></div><?php endforeach; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES | ENT_HTML5) ?></div><?php endif; ?>

<ul class="nav nav-tabs mb-4" id="courierTabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pending-tab">
            Pending <span class="badge bg-warning text-dark"><?= count($pending) ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#approved-tab">
            Approved <span class="badge bg-success"><?= count($approved) ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#rejected-tab">
            Rejected <span class="badge bg-secondary"><?= count($rejected) ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- Pending -->
    <div class="tab-pane fade show active" id="pending-tab">
        <?php if (empty($pending)): ?>
            <div class="alert alert-info">No pending applications.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr><th>Name</th><th>Email</th><th>Phone</th><th>MOR Last4</th><th>Photo</th><th>Applied</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $app): ?>
                            <tr>
                                <td><?= htmlspecialchars($app['name'], ENT_QUOTES | ENT_HTML5) ?></td>
                                <td><?= htmlspecialchars($app['email'], ENT_QUOTES | ENT_HTML5) ?></td>
                                <td><?= htmlspecialchars($app['phone'] ?? 'N/A', ENT_QUOTES | ENT_HTML5) ?></td>
                                <td><?= htmlspecialchars($app['mor_last4'] ?? 'N/A', ENT_QUOTES | ENT_HTML5) ?></td>
                                <td>
                                    <?php if ($app['mor_photo_path']): ?>
                                        <img src="<?= UPLOAD_URL . htmlspecialchars($app['mor_photo_path'], ENT_QUOTES | ENT_HTML5) ?>"
                                             alt="MOR Photo" style="max-height:60px; max-width:80px; object-fit:cover;" class="rounded">
                                    <?php else: ?>
                                        <span class="text-muted">None</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($app['created_at'])), ENT_QUOTES | ENT_HTML5) ?></td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="application_id" value="<?= (int)$app['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                    </form>
                                    <form method="POST" class="d-inline ms-1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="application_id" value="<?= (int)$app['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Approved -->
    <div class="tab-pane fade" id="approved-tab">
        <?php if (empty($approved)): ?>
            <div class="alert alert-info">No approved couriers yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr><th>Name</th><th>Email</th><th>Phone</th><th>Stripe Account</th><th>Joined</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($approved as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['name'], ENT_QUOTES | ENT_HTML5) ?></td>
                                <td><?= htmlspecialchars($c['email'], ENT_QUOTES | ENT_HTML5) ?></td>
                                <td><?= htmlspecialchars($c['phone'] ?? 'N/A', ENT_QUOTES | ENT_HTML5) ?></td>
                                <td><?= htmlspecialchars($c['stripe_account_id'] ?? '—', ENT_QUOTES | ENT_HTML5) ?></td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($c['created_at'])), ENT_QUOTES | ENT_HTML5) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Rejected -->
    <div class="tab-pane fade" id="rejected-tab">
        <?php if (empty($rejected)): ?>
            <div class="alert alert-info">No rejected applications.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr><th>Name</th><th>Email</th><th>Rejected</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rejected as $app): ?>
                            <tr>
                                <td><?= htmlspecialchars($app['name'], ENT_QUOTES | ENT_HTML5) ?></td>
                                <td><?= htmlspecialchars($app['email'], ENT_QUOTES | ENT_HTML5) ?></td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($app['updated_at'])), ENT_QUOTES | ENT_HTML5) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
