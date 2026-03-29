<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db = getDB();
$errors  = [];
$success = '';

$restaurantId = (int)($_GET['restaurant_id'] ?? $_POST['restaurant_id'] ?? 0);

// Fetch all restaurants for selector
$restaurants = $db->query('SELECT id, name FROM restaurants ORDER BY name ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';
        $restaurantId = (int)($_POST['restaurant_id'] ?? $restaurantId);

        if ($action === 'add_category') {
            $rid  = (int)($_POST['restaurant_id'] ?? 0);
            $name = trim($_POST['category_name'] ?? '');
            if ($rid <= 0 || empty($name)) {
                $errors[] = 'Restaurant and category name are required.';
            } else {
                $db->prepare('INSERT INTO menu_categories (restaurant_id, name) VALUES (?, ?)')->execute([$rid, $name]);
                $success = 'Category added.';
            }
        } elseif ($action === 'delete_category') {
            $catId = (int)($_POST['category_id'] ?? 0);
            if ($catId > 0) {
                $db->prepare('DELETE FROM menu_categories WHERE id = ?')->execute([$catId]);
                $success = 'Category deleted.';
            }
        } elseif ($action === 'add_item') {
            $catId    = (int)($_POST['category_id'] ?? 0);
            $name     = trim($_POST['item_name'] ?? '');
            $desc     = trim($_POST['item_description'] ?? '');
            $price    = (float)($_POST['base_price'] ?? 0);
            $mealElig = isset($_POST['meal_eligible']) ? 1 : 0;
            $active   = isset($_POST['active']) ? 1 : 0;

            if ($catId <= 0 || empty($name)) {
                $errors[] = 'Category and item name are required.';
            } else {
                $imgPath = null;
                if (!empty($_FILES['item_image']['tmp_name']) && $_FILES['item_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $imgPath = uploadFile($_FILES['item_image'], UPLOAD_DIR);
                    if ($imgPath === null) $errors[] = 'Invalid item image.';
                }
                if (empty($errors)) {
                    $db->prepare(
                        'INSERT INTO menu_items (category_id, name, description, base_price, meal_eligible, active, image_path)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$catId, $name, $desc, $price, $mealElig, $active, $imgPath]);
                    $success = 'Item added.';
                }
            }
        } elseif ($action === 'edit_item') {
            $itemId   = (int)($_POST['item_id'] ?? 0);
            $name     = trim($_POST['item_name'] ?? '');
            $desc     = trim($_POST['item_description'] ?? '');
            $price    = (float)($_POST['base_price'] ?? 0);
            $mealElig = isset($_POST['meal_eligible']) ? 1 : 0;
            $active   = isset($_POST['active']) ? 1 : 0;

            if ($itemId > 0 && !empty($name)) {
                $imgSql = '';
                $params = [$name, $desc, $price, $mealElig, $active];
                if (!empty($_FILES['item_image']['tmp_name']) && $_FILES['item_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $imgPath = uploadFile($_FILES['item_image'], UPLOAD_DIR);
                    if ($imgPath) { $imgSql = ', image_path = ?'; $params[] = $imgPath; }
                }
                $params[] = $itemId;
                $db->prepare("UPDATE menu_items SET name=?, description=?, base_price=?, meal_eligible=?, active=? {$imgSql} WHERE id=?")
                   ->execute($params);
                $success = 'Item updated.';
            }
        } elseif ($action === 'delete_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            if ($itemId > 0) {
                $db->prepare('DELETE FROM menu_items WHERE id = ?')->execute([$itemId]);
                $success = 'Item deleted.';
            }

        // ── Option groups ────────────────────────────────────────────────
        } elseif ($action === 'add_option_group') {
            $itemId   = (int)($_POST['item_id'] ?? 0);
            $ogName   = trim($_POST['og_name'] ?? '');
            $ogType   = ($_POST['og_type'] ?? 'single') === 'multiple' ? 'multiple' : 'single';
            $ogReq    = !empty($_POST['og_required']) ? 1 : 0;
            if ($itemId > 0 && $ogName) {
                $db->prepare(
                    'INSERT INTO item_option_groups (menu_item_id, name, type, required) VALUES (?, ?, ?, ?)'
                )->execute([$itemId, $ogName, $ogType, $ogReq]);
                $success = 'Option group added.';
            }

        } elseif ($action === 'delete_option_group') {
            $ogId = (int)($_POST['og_id'] ?? 0);
            if ($ogId > 0) {
                $db->prepare('DELETE FROM item_option_groups WHERE id = ?')->execute([$ogId]);
                $success = 'Option group deleted.';
            }

        } elseif ($action === 'add_option_choice') {
            $ogId      = (int)($_POST['og_id'] ?? 0);
            $chName    = trim($_POST['choice_name'] ?? '');
            $chPrice   = (float)($_POST['choice_price'] ?? 0);
            if ($ogId > 0 && $chName) {
                $db->prepare(
                    'INSERT INTO item_option_choices (option_group_id, name, price_modifier) VALUES (?, ?, ?)'
                )->execute([$ogId, $chName, $chPrice]);
                $success = 'Choice added.';
            }

        } elseif ($action === 'delete_option_choice') {
            $chId = (int)($_POST['choice_id'] ?? 0);
            if ($chId > 0) {
                $db->prepare('DELETE FROM item_option_choices WHERE id = ?')->execute([$chId]);
                $success = 'Choice deleted.';
            }
        }
    }
}


// Fetch categories, items, and option groups for selected restaurant
$categories = [];
if ($restaurantId > 0) {
    $catStmt = $db->prepare('SELECT * FROM menu_categories WHERE restaurant_id = ? ORDER BY name ASC');
    $catStmt->execute([$restaurantId]);
    $categories = $catStmt->fetchAll();

    foreach ($categories as &$cat) {
        $itemStmt = $db->prepare('SELECT * FROM menu_items WHERE category_id = ? ORDER BY name ASC');
        $itemStmt->execute([$cat['id']]);
        $items = $itemStmt->fetchAll();
        foreach ($items as &$item) {
            $item['option_groups'] = getItemOptions((int)$item['id']);
        }
        unset($item);
        $cat['items'] = $items;
    }
    unset($cat);
}

$csrf = generateCsrfToken();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold"><i class="ti ti-clipboard-list me-2 text-primary"></i>Menus</h2>
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

<!-- Restaurant Selector -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="" class="d-flex gap-3 align-items-end">
            <div class="flex-grow-1">
                <label class="form-label fw-semibold">Select Restaurant</label>
                <select class="form-select" name="restaurant_id" onchange="this.form.submit()">
                    <option value="">-- Choose a restaurant --</option>
                    <?php foreach ($restaurants as $r): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= $restaurantId === (int)$r['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['name'], ENT_QUOTES | ENT_HTML5) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($restaurantId > 0): ?>
    <!-- Add Category -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header fw-bold">Add Category</div>
        <div class="card-body">
            <form method="POST" class="d-flex gap-3 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="add_category">
                <input type="hidden" name="restaurant_id" value="<?= $restaurantId ?>">
                <div class="flex-grow-1">
                    <input type="text" class="form-control" name="category_name" placeholder="Category name" required>
                </div>
                <button type="submit" class="btn btn-success">Add Category</button>
            </form>
        </div>
    </div>

    <!-- Add Item Form -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header fw-bold">Add Menu Item</div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="restaurant_id" value="<?= $restaurantId ?>">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Category</label>
                        <select class="form-select" name="category_id" required>
                            <option value="">Select...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_HTML5) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Item Name</label>
                        <input type="text" class="form-control" name="item_name" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Price ($)</label>
                        <input type="number" class="form-control" name="base_price" min="0" step="0.01" value="0.00">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Description</label>
                        <input type="text" class="form-control" name="item_description">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Photo</label>
                        <input type="file" class="form-control" name="item_image" accept="image/*">
                    </div>
                    <div class="col-md-12 d-flex gap-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="meal_eligible" id="new-meal-eligible" checked>
                            <label class="form-check-label" for="new-meal-eligible">Meal Eligible</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="active" id="new-active" checked>
                            <label class="form-check-label" for="new-active">Active</label>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-success mt-3">
                    <i class="ti ti-plus me-1"></i>Add Item
                </button>
            </form>
        </div>
    </div>

    <!-- Categories and Items -->
    <?php if (empty($categories)): ?>
        <div class="alert alert-info">No categories yet. Add one above.</div>
    <?php else: ?>
        <?php foreach ($categories as $cat): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><i class="ti ti-tag me-2 text-primary"></i><?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_HTML5) ?></strong>
                    <form method="POST" class="d-inline"
                          onsubmit="return confirm('Delete this category and all its items?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="delete_category">
                        <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                        <input type="hidden" name="restaurant_id" value="<?= $restaurantId ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="ti ti-trash me-1"></i>Delete Category
                        </button>
                    </form>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($cat['items'])): ?>
                        <p class="p-3 mb-0 text-muted">No items in this category.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr><th>Photo</th><th>Name</th><th>Price</th><th>Meal</th><th>Active</th><th>Options</th><th></th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cat['items'] as $item): ?>
                                        <tr>
                                            <td>
                                                <?php if ($item['image_path']): ?>
                                                    <img src="<?= htmlspecialchars(UPLOAD_URL . $item['image_path'], ENT_QUOTES | ENT_HTML5) ?>"
                                                         width="50" height="50" style="object-fit:cover;border-radius:.3rem;">
                                                <?php else: ?>
                                                    <div class="bg-secondary-lt rounded d-flex align-items-center justify-content-center" style="width:50px;height:50px;">
                                                        <i class="ti ti-photo text-muted"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-semibold"><?= htmlspecialchars($item['name'], ENT_QUOTES | ENT_HTML5) ?></td>
                                            <td><?= formatMoney((float)$item['base_price']) ?></td>
                                            <td><?= $item['meal_eligible'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                                            <td><?= $item['active'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                                            <td>
                                                <span class="badge bg-secondary"><?= count($item['option_groups']) ?> groups</span>
                                                <button type="button" class="btn btn-xs btn-outline-info ms-1"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#optionsModal<?= (int)$item['id'] ?>">
                                                    <i class="ti ti-adjustments-horizontal"></i>
                                                </button>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary"
                                                        data-bs-toggle="modal" data-bs-target="#editItemModal"
                                                        data-item-id="<?= (int)$item['id'] ?>"
                                                        data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES | ENT_HTML5) ?>"
                                                        data-description="<?= htmlspecialchars($item['description'] ?? '', ENT_QUOTES | ENT_HTML5) ?>"
                                                        data-price="<?= htmlspecialchars($item['base_price'], ENT_QUOTES | ENT_HTML5) ?>"
                                                        data-meal="<?= (int)$item['meal_eligible'] ?>"
                                                        data-active="<?= (int)$item['active'] ?>">
                                                    <i class="ti ti-pencil"></i>
                                                </button>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this item?')">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                    <input type="hidden" name="action" value="delete_item">
                                                    <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                                    <input type="hidden" name="restaurant_id" value="<?= $restaurantId ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger ms-1">
                                                        <i class="ti ti-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Options modals for each item -->
            <?php foreach ($cat['items'] as $item): ?>
            <div class="modal fade" id="optionsModal<?= (int)$item['id'] ?>" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="ti ti-adjustments-horizontal me-2"></i>Customizations — <?= htmlspecialchars($item['name'], ENT_QUOTES | ENT_HTML5) ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <?php foreach ($item['option_groups'] as $og): ?>
                                <div class="card mb-3 border">
                                    <div class="card-header d-flex justify-content-between py-2">
                                        <span class="fw-semibold">
                                            <?= htmlspecialchars($og['name'], ENT_QUOTES | ENT_HTML5) ?>
                                            <span class="badge bg-secondary ms-1"><?= $og['type'] ?></span>
                                            <?php if ($og['required']): ?><span class="badge bg-danger ms-1">Required</span><?php endif; ?>
                                        </span>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="delete_option_group">
                                            <input type="hidden" name="og_id" value="<?= (int)$og['id'] ?>">
                                            <input type="hidden" name="restaurant_id" value="<?= $restaurantId ?>">
                                            <button class="btn btn-xs btn-outline-danger" onclick="return confirm('Delete group?')">
                                                <i class="ti ti-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                    <div class="card-body p-2">
                                        <?php foreach ($og['choices'] as $ch): ?>
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <span><?= htmlspecialchars($ch['name'], ENT_QUOTES | ENT_HTML5) ?>
                                                    <?php if ((float)$ch['price_modifier'] != 0): ?>
                                                        <small class="text-muted">(<?= (float)$ch['price_modifier'] >= 0 ? '+' : '' ?><?= formatMoney((float)$ch['price_modifier']) ?>)</small>
                                                    <?php endif; ?>
                                                </span>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                    <input type="hidden" name="action" value="delete_option_choice">
                                                    <input type="hidden" name="choice_id" value="<?= (int)$ch['id'] ?>">
                                                    <input type="hidden" name="restaurant_id" value="<?= $restaurantId ?>">
                                                    <button class="btn btn-xs btn-outline-danger">
                                                        <i class="ti ti-x"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                        <!-- Add choice -->
                                        <form method="POST" class="d-flex gap-2 mt-2">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="add_option_choice">
                                            <input type="hidden" name="og_id" value="<?= (int)$og['id'] ?>">
                                            <input type="hidden" name="restaurant_id" value="<?= $restaurantId ?>">
                                            <input type="text" class="form-control form-control-sm" name="choice_name" placeholder="Choice name" required>
                                            <input type="number" class="form-control form-control-sm" name="choice_price"
                                                   placeholder="Price modifier" step="0.01" value="0" style="width:100px">
                                            <button class="btn btn-sm btn-success flex-shrink-0">
                                                <i class="ti ti-plus"></i> Add
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Add new option group -->
                            <div class="card border-dashed">
                                <div class="card-body p-3">
                                    <p class="fw-semibold mb-2">Add Option Group</p>
                                    <form method="POST" class="row g-2">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="action" value="add_option_group">
                                        <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                        <input type="hidden" name="restaurant_id" value="<?= $restaurantId ?>">
                                        <div class="col-md-5">
                                            <input type="text" class="form-control form-control-sm" name="og_name" placeholder="Group name (e.g. Size)" required>
                                        </div>
                                        <div class="col-auto">
                                            <select class="form-select form-select-sm" name="og_type">
                                                <option value="single">Single choice (radio)</option>
                                                <option value="multiple">Multiple choice (checkbox)</option>
                                            </select>
                                        </div>
                                        <div class="col-auto d-flex align-items-center">
                                            <div class="form-check me-2">
                                                <input type="checkbox" class="form-check-input" name="og_required" id="og-req-<?= (int)$item['id'] ?>">
                                                <label class="form-check-label" for="og-req-<?= (int)$item['id'] ?>">Required</label>
                                            </div>
                                            <button class="btn btn-sm btn-primary"><i class="ti ti-plus me-1"></i>Add Group</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<!-- Edit Item Modal -->
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ti ti-pencil me-2"></i>Edit Menu Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="edit_item">
                    <input type="hidden" name="item_id" id="edit-item-id">
                    <input type="hidden" name="restaurant_id" value="<?= $restaurantId ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name</label>
                        <input type="text" class="form-control" name="item_name" id="edit-item-name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea class="form-control" name="item_description" id="edit-item-description" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Price ($)</label>
                        <input type="number" class="form-control" name="base_price" id="edit-item-price" min="0" step="0.01">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Photo</label>
                        <input type="file" class="form-control" name="item_image" accept="image/*">
                        <div class="form-text">Leave blank to keep existing photo.</div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="meal_eligible" id="edit-item-meal">
                        <label class="form-check-label" for="edit-item-meal">Meal Eligible</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="active" id="edit-item-active">
                        <label class="form-check-label" for="edit-item-active">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="ti ti-check me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('editItemModal').addEventListener('show.bs.modal', function(event) {
    var btn = event.relatedTarget;
    document.getElementById('edit-item-id').value    = btn.getAttribute('data-item-id');
    document.getElementById('edit-item-name').value  = btn.getAttribute('data-name');
    document.getElementById('edit-item-description').value = btn.getAttribute('data-description');
    document.getElementById('edit-item-price').value = btn.getAttribute('data-price');
    document.getElementById('edit-item-meal').checked   = btn.getAttribute('data-meal') === '1';
    document.getElementById('edit-item-active').checked = btn.getAttribute('data-active') === '1';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
