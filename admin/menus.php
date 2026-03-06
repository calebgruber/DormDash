<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db = getDB();
$errors  = [];
$success = '';

$restaurantId = (int)($_GET['restaurant_id'] ?? 0);

// Fetch all restaurants for selector
$restaurants = $db->query('SELECT id, name FROM restaurants ORDER BY name ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_category') {
            $rid  = (int)($_POST['restaurant_id'] ?? 0);
            $name = trim($_POST['category_name'] ?? '');
            if ($rid <= 0 || empty($name)) {
                $errors[] = 'Restaurant and category name are required.';
            } else {
                $db->prepare('INSERT INTO menu_categories (restaurant_id, name) VALUES (?, ?)')->execute([$rid, $name]);
                $success = 'Category added.';
                $restaurantId = $rid;
            }
        } elseif ($action === 'delete_category') {
            $catId = (int)($_POST['category_id'] ?? 0);
            $rid   = (int)($_POST['restaurant_id'] ?? 0);
            if ($catId > 0) {
                $db->prepare('DELETE FROM menu_categories WHERE id = ?')->execute([$catId]);
                $success = 'Category deleted.';
            }
            $restaurantId = $rid;
        } elseif ($action === 'add_item') {
            $catId       = (int)($_POST['category_id'] ?? 0);
            $rid         = (int)($_POST['restaurant_id'] ?? 0);
            $name        = trim($_POST['item_name'] ?? '');
            $desc        = trim($_POST['item_description'] ?? '');
            $price       = (float)($_POST['base_price'] ?? 0);
            $mealElig    = isset($_POST['meal_eligible']) ? 1 : 0;
            $active      = isset($_POST['active']) ? 1 : 0;

            if ($catId <= 0 || empty($name)) {
                $errors[] = 'Category and item name are required.';
            } else {
                $db->prepare('INSERT INTO menu_items (category_id, name, description, base_price, meal_eligible, active) VALUES (?, ?, ?, ?, ?, ?)')
                   ->execute([$catId, $name, $desc, $price, $mealElig, $active]);
                $success = 'Item added.';
            }
            $restaurantId = $rid;
        } elseif ($action === 'edit_item') {
            $itemId      = (int)($_POST['item_id'] ?? 0);
            $rid         = (int)($_POST['restaurant_id'] ?? 0);
            $name        = trim($_POST['item_name'] ?? '');
            $desc        = trim($_POST['item_description'] ?? '');
            $price       = (float)($_POST['base_price'] ?? 0);
            $mealElig    = isset($_POST['meal_eligible']) ? 1 : 0;
            $active      = isset($_POST['active']) ? 1 : 0;

            if ($itemId > 0 && !empty($name)) {
                $db->prepare('UPDATE menu_items SET name=?, description=?, base_price=?, meal_eligible=?, active=? WHERE id=?')
                   ->execute([$name, $desc, $price, $mealElig, $active, $itemId]);
                $success = 'Item updated.';
            }
            $restaurantId = $rid;
        } elseif ($action === 'delete_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $rid    = (int)($_POST['restaurant_id'] ?? 0);
            if ($itemId > 0) {
                $db->prepare('DELETE FROM menu_items WHERE id = ?')->execute([$itemId]);
                $success = 'Item deleted.';
            }
            $restaurantId = $rid;
        }
    }
}

// Fetch categories and items for selected restaurant
$categories = [];
if ($restaurantId > 0) {
    $catStmt = $db->prepare('SELECT * FROM menu_categories WHERE restaurant_id = ? ORDER BY name ASC');
    $catStmt->execute([$restaurantId]);
    $categories = $catStmt->fetchAll();

    foreach ($categories as &$cat) {
        $itemStmt = $db->prepare('SELECT * FROM menu_items WHERE category_id = ? ORDER BY name ASC');
        $itemStmt->execute([$cat['id']]);
        $cat['items'] = $itemStmt->fetchAll();
    }
    unset($cat);
}

$csrf = generateCsrfToken();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>📋 Menus</h2>
    <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn btn-outline-secondary btn-sm">← Dashboard</a>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e, ENT_QUOTES | ENT_HTML5) ?></div><?php endforeach; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES | ENT_HTML5) ?></div><?php endif; ?>

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
        <div class="card-header bg-white fw-bold">Add Category</div>
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
        <div class="card-header bg-white fw-bold">Add Menu Item</div>
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
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Description</label>
                        <input type="text" class="form-control" name="item_description">
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
                <button type="submit" class="btn btn-success mt-3">Add Item</button>
            </form>
        </div>
    </div>

    <!-- Categories and Items -->
    <?php if (empty($categories)): ?>
        <div class="alert alert-info">No categories yet. Add one above.</div>
    <?php else: ?>
        <?php foreach ($categories as $cat): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong><?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_HTML5) ?></strong>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this category and all its items?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="delete_category">
                        <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                        <input type="hidden" name="restaurant_id" value="<?= $restaurantId ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Delete Category</button>
                    </form>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($cat['items'])): ?>
                        <p class="p-3 mb-0 text-muted">No items in this category.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr><th>Name</th><th>Price</th><th>Meal Eligible</th><th>Active</th><th>Actions</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cat['items'] as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($item['name'], ENT_QUOTES | ENT_HTML5) ?></td>
                                            <td><?= formatMoney((float)$item['base_price']) ?></td>
                                            <td><?= $item['meal_eligible'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                                            <td><?= $item['active'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary"
                                                        data-bs-toggle="modal" data-bs-target="#editItemModal"
                                                        data-item-id="<?= (int)$item['id'] ?>"
                                                        data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES | ENT_HTML5) ?>"
                                                        data-description="<?= htmlspecialchars($item['description'] ?? '', ENT_QUOTES | ENT_HTML5) ?>"
                                                        data-price="<?= htmlspecialchars($item['base_price'], ENT_QUOTES | ENT_HTML5) ?>"
                                                        data-meal="<?= (int)$item['meal_eligible'] ?>"
                                                        data-active="<?= (int)$item['active'] ?>">
                                                    Edit
                                                </button>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this item?')">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                    <input type="hidden" name="action" value="delete_item">
                                                    <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                                    <input type="hidden" name="restaurant_id" value="<?= $restaurantId ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger ms-1">Delete</button>
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
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<!-- Edit Item Modal -->
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Menu Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
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
                    <button type="submit" class="btn btn-primary">Save Changes</button>
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
