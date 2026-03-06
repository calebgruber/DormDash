<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db     = getDB();
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'toggle') {
            $rid = (int)($_POST['restaurant_id'] ?? 0);
            if ($rid > 0) {
                $db->prepare('UPDATE restaurants SET active = NOT active WHERE id = ?')->execute([$rid]);
                $success = 'Restaurant status updated.';
            }
        } elseif ($action === 'add') {
            $name        = trim($_POST['name'] ?? '');
            $type        = $_POST['type'] ?? 'other';
            $location    = trim($_POST['location'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $validTypes  = ['dining_hall', 'einstein', 'boba', 'other'];

            if (empty($name)) {
                $errors[] = 'Name is required.';
            } elseif (!in_array($type, $validTypes, true)) {
                $errors[] = 'Invalid type.';
            } else {
                $stmt = $db->prepare('INSERT INTO restaurants (name, type, location, description) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $type, $location, $description]);
                $success = 'Restaurant added.';
            }
        } elseif ($action === 'edit') {
            $rid         = (int)($_POST['restaurant_id'] ?? 0);
            $name        = trim($_POST['name'] ?? '');
            $type        = $_POST['type'] ?? 'other';
            $location    = trim($_POST['location'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $validTypes  = ['dining_hall', 'einstein', 'boba', 'other'];

            if ($rid <= 0 || empty($name)) {
                $errors[] = 'Invalid restaurant data.';
            } elseif (!in_array($type, $validTypes, true)) {
                $errors[] = 'Invalid type.';
            } else {
                $stmt = $db->prepare('UPDATE restaurants SET name=?, type=?, location=?, description=? WHERE id=?');
                $stmt->execute([$name, $type, $location, $description, $rid]);
                $success = 'Restaurant updated.';
            }
        }
    }
}

$stmt = $db->query('SELECT * FROM restaurants ORDER BY name ASC');
$restaurants = $stmt->fetchAll();
$csrf = generateCsrfToken();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>🍽️ Restaurants</h2>
    <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn btn-outline-secondary btn-sm">← Dashboard</a>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e, ENT_QUOTES | ENT_HTML5) ?></div><?php endforeach; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES | ENT_HTML5) ?></div><?php endif; ?>

<!-- Restaurants Table -->
<div class="card shadow-sm border-0 mb-5">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-dark">
                    <tr><th>Name</th><th>Type</th><th>Location</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($restaurants as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['name'], ENT_QUOTES | ENT_HTML5) ?></td>
                            <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $r['type'])), ENT_QUOTES | ENT_HTML5) ?></td>
                            <td><?= htmlspecialchars($r['location'] ?? '', ENT_QUOTES | ENT_HTML5) ?></td>
                            <td>
                                <?php if ($r['active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="restaurant_id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $r['active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                        <?= $r['active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                                <button type="button" class="btn btn-sm btn-outline-primary ms-1"
                                        data-bs-toggle="modal" data-bs-target="#editModal"
                                        data-id="<?= (int)$r['id'] ?>"
                                        data-name="<?= htmlspecialchars($r['name'], ENT_QUOTES | ENT_HTML5) ?>"
                                        data-type="<?= htmlspecialchars($r['type'], ENT_QUOTES | ENT_HTML5) ?>"
                                        data-location="<?= htmlspecialchars($r['location'] ?? '', ENT_QUOTES | ENT_HTML5) ?>"
                                        data-description="<?= htmlspecialchars($r['description'] ?? '', ENT_QUOTES | ENT_HTML5) ?>">
                                    Edit
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Restaurant Form -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white fw-bold">Add New Restaurant</div>
    <div class="card-body">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Name</label>
                    <input type="text" class="form-control" name="name" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Type</label>
                    <select class="form-select" name="type">
                        <option value="dining_hall">Dining Hall</option>
                        <option value="einstein">Einstein</option>
                        <option value="boba">Boba</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Location</label>
                    <input type="text" class="form-control" name="location">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Description</label>
                    <input type="text" class="form-control" name="description">
                </div>
            </div>
            <button type="submit" class="btn btn-success mt-3">Add Restaurant</button>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Restaurant</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="restaurant_id" id="edit-id">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name</label>
                        <input type="text" class="form-control" name="name" id="edit-name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Type</label>
                        <select class="form-select" name="type" id="edit-type">
                            <option value="dining_hall">Dining Hall</option>
                            <option value="einstein">Einstein</option>
                            <option value="boba">Boba</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Location</label>
                        <input type="text" class="form-control" name="location" id="edit-location">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea class="form-control" name="description" id="edit-description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('editModal').addEventListener('show.bs.modal', function(event) {
    var btn = event.relatedTarget;
    document.getElementById('edit-id').value = btn.getAttribute('data-id');
    document.getElementById('edit-name').value = btn.getAttribute('data-name');
    document.getElementById('edit-location').value = btn.getAttribute('data-location');
    document.getElementById('edit-description').value = btn.getAttribute('data-description');
    var typeSelect = document.getElementById('edit-type');
    typeSelect.value = btn.getAttribute('data-type');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
