<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db      = getDB();
$errors  = [];
$success = '';

$DAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

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

        } elseif ($action === 'delete') {
            $rid = (int)($_POST['restaurant_id'] ?? 0);
            if ($rid > 0) {
                $db->prepare('DELETE FROM restaurants WHERE id = ?')->execute([$rid]);
                $success = 'Restaurant deleted.';
            }

        } elseif ($action === 'add') {
            $name        = trim($_POST['name'] ?? '');
            $type        = $_POST['type'] ?? 'other';
            $location    = trim($_POST['location'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $mealSwipe   = !empty($_POST['meal_swipe_eligible']) ? 1 : 0;
            $textOrder   = !empty($_POST['allow_text_order'])    ? 1 : 0;
            $validTypes  = ['dining_hall', 'einstein', 'boba', 'other'];

            if (empty($name)) {
                $errors[] = 'Name is required.';
            } elseif (!in_array($type, $validTypes, true)) {
                $errors[] = 'Invalid type.';
            } else {
                // Handle banner upload
                $banner = null;
                if (!empty($_FILES['banner_image']['tmp_name']) && $_FILES['banner_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $banner = uploadFile($_FILES['banner_image'], UPLOAD_DIR);
                    if ($banner === null) $errors[] = 'Invalid banner image.';
                }
                if (empty($errors)) {
                    $stmt = $db->prepare(
                        'INSERT INTO restaurants (name, type, location, description, banner_image, meal_swipe_eligible, allow_text_order)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$name, $type, $location, $description, $banner, $mealSwipe, $textOrder]);
                    $success = 'Restaurant added.';
                }
            }

        } elseif ($action === 'edit') {
            $rid         = (int)($_POST['restaurant_id'] ?? 0);
            $name        = trim($_POST['name'] ?? '');
            $type        = $_POST['type'] ?? 'other';
            $location    = trim($_POST['location'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $mealSwipe   = !empty($_POST['meal_swipe_eligible']) ? 1 : 0;
            $textOrder   = !empty($_POST['allow_text_order'])    ? 1 : 0;
            $validTypes  = ['dining_hall', 'einstein', 'boba', 'other'];

            if ($rid <= 0 || empty($name)) {
                $errors[] = 'Invalid restaurant data.';
            } elseif (!in_array($type, $validTypes, true)) {
                $errors[] = 'Invalid type.';
            } else {
                $bannerSql = '';
                $params    = [$name, $type, $location, $description, $mealSwipe, $textOrder];
                if (!empty($_FILES['banner_image']['tmp_name']) && $_FILES['banner_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $banner = uploadFile($_FILES['banner_image'], UPLOAD_DIR);
                    if ($banner) { $bannerSql = ', banner_image = ?'; $params[] = $banner; }
                }
                $params[] = $rid;
                $db->prepare(
                    "UPDATE restaurants SET name=?, type=?, location=?, description=?,
                     meal_swipe_eligible=?, allow_text_order=? {$bannerSql} WHERE id=?"
                )->execute($params);
                $success = 'Restaurant updated.';
            }

        } elseif ($action === 'save_hours') {
            $rid = (int)($_POST['restaurant_id'] ?? 0);
            if ($rid > 0) {
                for ($d = 0; $d <= 6; $d++) {
                    $isClosed  = !empty($_POST["closed_{$d}"]) ? 1 : 0;
                    $openTime  = trim($_POST["open_{$d}"]  ?? '');
                    $closeTime = trim($_POST["close_{$d}"] ?? '');
                    $db->prepare(
                        'INSERT INTO restaurant_hours (restaurant_id, day_of_week, open_time, close_time, is_closed)
                         VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE open_time=VALUES(open_time), close_time=VALUES(close_time), is_closed=VALUES(is_closed)'
                    )->execute([
                        $rid, $d,
                        ($isClosed || !$openTime)  ? null : $openTime,
                        ($isClosed || !$closeTime) ? null : $closeTime,
                        $isClosed,
                    ]);
                }
                $success = 'Hours saved.';
            }
        }
    }
}

$restaurants = $db->query('SELECT * FROM restaurants ORDER BY name ASC')->fetchAll();
$csrf = generateCsrfToken();

// Pre-load hours for all restaurants
$hoursMap = [];
$hRows = $db->query('SELECT * FROM restaurant_hours')->fetchAll();
foreach ($hRows as $hr) {
    $hoursMap[$hr['restaurant_id']][$hr['day_of_week']] = $hr;
}
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="ti ti-tools-kitchen-2 me-2 text-primary"></i>Restaurants</h2>
    <a href="<?= APP_URL ?>/admin/dashboard" class="btn btn-outline-secondary btn-sm">
        <i class="ti ti-arrow-left me-1"></i> Dashboard
    </a>
</div>

<?php foreach ($errors as $e): ?>
    <div class="alert alert-danger py-2"><i class="ti ti-alert-circle me-1"></i><?= htmlspecialchars($e, ENT_QUOTES | ENT_HTML5) ?></div>
<?php endforeach; ?>
<?php if ($success): ?>
    <div class="alert alert-success py-2"><i class="ti ti-circle-check me-1"></i><?= htmlspecialchars($success, ENT_QUOTES | ENT_HTML5) ?></div>
<?php endif; ?>

<!-- Restaurants List -->
<div class="row g-3 mb-5">
    <?php foreach ($restaurants as $r): ?>
        <?php $status = getRestaurantStatus((int)$r['id']); ?>
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="row align-items-center g-3">
                        <!-- Banner thumb -->
                        <div class="col-auto">
                            <?php if ($r['banner_image']): ?>
                                <img src="<?= htmlspecialchars(UPLOAD_URL . $r['banner_image'], ENT_QUOTES | ENT_HTML5) ?>"
                                     alt="" width="80" height="60" style="object-fit:cover;border-radius:.4rem;">
                            <?php else: ?>
                                <div class="bg-purple-lt rounded d-flex align-items-center justify-content-center" style="width:80px;height:60px;">
                                    <i class="ti ti-photo fs-2 text-muted"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col">
                            <h5 class="mb-1 fw-bold"><?= htmlspecialchars($r['name'], ENT_QUOTES | ENT_HTML5) ?></h5>
                            <div class="d-flex gap-2 flex-wrap">
                                <span class="badge bg-secondary"><?= ucfirst(str_replace('_', ' ', $r['type'])) ?></span>
                                <?php if ($r['active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                                <span class="badge bg-<?= $status['class'] ?>"><?= htmlspecialchars($status['label']) ?></span>
                                <?php if ($r['meal_swipe_eligible']): ?><span class="badge bg-purple">Meal Swipe</span><?php endif; ?>
                                <?php if ($r['allow_text_order']): ?><span class="badge bg-info text-dark">Text Orders</span><?php endif; ?>
                            </div>
                            <?php if ($r['location']): ?>
                                <small class="text-muted"><i class="ti ti-map-pin me-1"></i><?= htmlspecialchars($r['location'], ENT_QUOTES | ENT_HTML5) ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto d-flex gap-2 flex-wrap">
                            <!-- Toggle active -->
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="restaurant_id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm <?= $r['active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                    <?= $r['active'] ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                            <!-- Edit -->
                            <button class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal" data-bs-target="#editModal"
                                    data-id="<?= $r['id'] ?>"
                                    data-name="<?= htmlspecialchars($r['name'], ENT_QUOTES | ENT_HTML5) ?>"
                                    data-type="<?= htmlspecialchars($r['type']) ?>"
                                    data-location="<?= htmlspecialchars($r['location'] ?? '') ?>"
                                    data-description="<?= htmlspecialchars($r['description'] ?? '') ?>"
                                    data-meal="<?= (int)$r['meal_swipe_eligible'] ?>"
                                    data-text="<?= (int)$r['allow_text_order'] ?>">
                                <i class="ti ti-pencil"></i> Edit
                            </button>
                            <!-- Hours -->
                            <button class="btn btn-sm btn-outline-info"
                                    data-bs-toggle="modal" data-bs-target="#hoursModal<?= $r['id'] ?>">
                                <i class="ti ti-clock"></i> Hours
                            </button>
                            <!-- Menus -->
                            <a href="<?= APP_URL ?>/admin/menus?restaurant_id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="ti ti-clipboard-list"></i> Menu
                            </a>
                            <!-- Delete -->
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Delete <?= htmlspecialchars($r['name'], ENT_QUOTES | ENT_HTML5) ?>? This cannot be undone.')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="restaurant_id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger">
                                    <i class="ti ti-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hours Modal -->
        <div class="modal fade" id="hoursModal<?= $r['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="ti ti-clock me-2"></i>Hours — <?= htmlspecialchars($r['name'], ENT_QUOTES | ENT_HTML5) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="">
                        <div class="modal-body">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="save_hours">
                            <input type="hidden" name="restaurant_id" value="<?= (int)$r['id'] ?>">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>Day</th><th>Closed</th><th>Open</th><th>Close</th></tr></thead>
                                    <tbody>
                                        <?php for ($d = 0; $d <= 6; $d++):
                                            $h = $hoursMap[$r['id']][$d] ?? null;
                                        ?>
                                            <tr>
                                                <td class="fw-semibold"><?= $DAYS[$d] ?></td>
                                                <td>
                                                    <input type="checkbox" class="form-check-input" name="closed_<?= $d ?>"
                                                           id="cl_<?= $r['id'] ?>_<?= $d ?>"
                                                           <?= ($h && $h['is_closed']) ? 'checked' : '' ?>>
                                                </td>
                                                <td>
                                                    <input type="time" class="form-control form-control-sm" name="open_<?= $d ?>"
                                                           style="min-width:110px"
                                                           value="<?= htmlspecialchars($h['open_time'] ?? '08:00', ENT_QUOTES | ENT_HTML5) ?>">
                                                </td>
                                                <td>
                                                    <input type="time" class="form-control form-control-sm" name="close_<?= $d ?>"
                                                           style="min-width:110px"
                                                           value="<?= htmlspecialchars($h['close_time'] ?? '22:00', ENT_QUOTES | ENT_HTML5) ?>">
                                                </td>
                                            </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="ti ti-check me-1"></i>Save Hours</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Add Restaurant -->
<div class="card shadow-sm border-0">
    <div class="card-header fw-bold"><i class="ti ti-plus me-2 text-primary"></i>Add New Restaurant</div>
    <div class="card-body">
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Name *</label>
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
                    <label class="form-label fw-semibold">Banner Image</label>
                    <input type="file" class="form-control" name="banner_image" accept="image/*">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Description</label>
                    <input type="text" class="form-control" name="description">
                </div>
                <div class="col-auto">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="meal_swipe_eligible" id="add-meal">
                        <label class="form-check-label" for="add-meal">Meal swipe eligible</label>
                    </div>
                </div>
                <div class="col-auto">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="allow_text_order" id="add-text">
                        <label class="form-check-label" for="add-text">Allow text orders (dining hall)</label>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-success mt-3">
                <i class="ti ti-plus me-1"></i> Add Restaurant
            </button>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ti ti-pencil me-2"></i>Edit Restaurant</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
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
                        <textarea class="form-control" name="description" id="edit-description" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Banner Image</label>
                        <input type="file" class="form-control" name="banner_image" accept="image/*">
                        <div class="form-text">Leave blank to keep existing banner.</div>
                    </div>
                    <div class="d-flex gap-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="meal_swipe_eligible" id="edit-meal">
                            <label class="form-check-label" for="edit-meal">Meal swipe eligible</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="allow_text_order" id="edit-text">
                            <label class="form-check-label" for="edit-text">Allow text orders</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="ti ti-check me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('editModal').addEventListener('show.bs.modal', function(e) {
    var b = e.relatedTarget;
    document.getElementById('edit-id').value          = b.dataset.id;
    document.getElementById('edit-name').value        = b.dataset.name;
    document.getElementById('edit-location').value    = b.dataset.location;
    document.getElementById('edit-description').value = b.dataset.description;
    document.getElementById('edit-type').value        = b.dataset.type;
    document.getElementById('edit-meal').checked      = b.dataset.meal === '1';
    document.getElementById('edit-text').checked      = b.dataset.text === '1';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
