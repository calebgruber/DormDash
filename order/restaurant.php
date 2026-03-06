<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$restaurantId = (int)($_GET['id'] ?? 0);
if ($restaurantId <= 0) {
    header('Location: ' . APP_URL . '/order/');
    exit;
}

$db   = getDB();
$stmt = $db->prepare('SELECT * FROM restaurants WHERE id = ? AND active = 1');
$stmt->execute([$restaurantId]);
$restaurant = $stmt->fetch();

if (!$restaurant) {
    header('Location: ' . APP_URL . '/order/');
    exit;
}

$isDiningHall = ($restaurant['type'] === 'dining_hall');

$categories = [];
if ($isDiningHall) {
    $catStmt = $db->prepare(
        'SELECT mc.*, (SELECT COUNT(*) FROM menu_items mi WHERE mi.category_id = mc.id AND mi.active = 1) AS item_count
         FROM menu_categories mc WHERE mc.restaurant_id = ? ORDER BY mc.name ASC'
    );
    $catStmt->execute([$restaurantId]);
    $categories = $catStmt->fetchAll();

    foreach ($categories as &$cat) {
        $itemStmt = $db->prepare(
            'SELECT * FROM menu_items WHERE category_id = ? AND active = 1 ORDER BY name ASC'
        );
        $itemStmt->execute([$cat['id']]);
        $cat['items'] = $itemStmt->fetchAll();
    }
    unset($cat);
}

$csrf = generateCsrfToken();
$cartCount = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $cartCount = getCartItemCount($_SESSION['cart']);
}
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/order/">Restaurants</a></li>
        <li class="breadcrumb-item active"><?= htmlspecialchars($restaurant['name'], ENT_QUOTES | ENT_HTML5) ?></li>
    </ol>
</nav>

<div class="mb-4">
    <h2><?= htmlspecialchars($restaurant['name'], ENT_QUOTES | ENT_HTML5) ?></h2>
    <?php if ($restaurant['location']): ?>
        <p class="text-muted mb-1">📍 <?= htmlspecialchars($restaurant['location'], ENT_QUOTES | ENT_HTML5) ?></p>
    <?php endif; ?>
    <?php if ($restaurant['description']): ?>
        <p><?= htmlspecialchars($restaurant['description'], ENT_QUOTES | ENT_HTML5) ?></p>
    <?php endif; ?>
</div>

<?php if ($isDiningHall): ?>
    <!-- Cart info bar -->
    <div id="cart-bar" class="alert alert-info d-flex justify-content-between align-items-center mb-4 <?= $cartCount === 0 ? 'd-none' : '' ?>">
        <span>🛒 <strong id="cart-count-text"><?= $cartCount ?></strong> item(s) in cart</span>
        <a href="<?= APP_URL ?>/order/cart.php" class="btn btn-sm btn-primary">View Cart</a>
    </div>

    <div id="add-message" class="alert alert-success d-none mb-3"></div>

    <?php if (empty($categories)): ?>
        <div class="alert alert-info">No menu items available yet.</div>
    <?php else: ?>
        <div class="accordion" id="menuAccordion">
            <?php foreach ($categories as $idx => $cat): ?>
                <?php if (empty($cat['items'])) continue; ?>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="heading<?= $cat['id'] ?>">
                        <button class="accordion-button <?= $idx > 0 ? 'collapsed' : '' ?>"
                                type="button" data-bs-toggle="collapse"
                                data-bs-target="#collapse<?= $cat['id'] ?>"
                                aria-expanded="<?= $idx === 0 ? 'true' : 'false' ?>">
                            <?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_HTML5) ?>
                            <span class="badge bg-secondary ms-2"><?= count($cat['items']) ?></span>
                        </button>
                    </h2>
                    <div id="collapse<?= $cat['id'] ?>"
                         class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>">
                        <div class="accordion-body p-0">
                            <div class="list-group list-group-flush">
                                <?php foreach ($cat['items'] as $item): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <strong><?= htmlspecialchars($item['name'], ENT_QUOTES | ENT_HTML5) ?></strong>
                                                <?php if ($item['meal_eligible']): ?>
                                                    <span class="badge bg-success">Meal Eligible</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($item['description']): ?>
                                                <small class="text-muted"><?= htmlspecialchars($item['description'], ENT_QUOTES | ENT_HTML5) ?></small>
                                            <?php endif; ?>
                                            <?php if ((float)$item['base_price'] > 0): ?>
                                                <div class="mt-1"><strong><?= formatMoney((float)$item['base_price']) ?></strong></div>
                                            <?php endif; ?>
                                        </div>
                                        <button class="btn btn-outline-primary btn-sm ms-3 add-to-cart-btn"
                                                data-item-id="<?= (int)$item['id'] ?>">
                                            + Add
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <script>
    document.querySelectorAll('.add-to-cart-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var itemId = this.getAttribute('data-item-id');
            var formData = new FormData();
            formData.append('action', 'add');
            formData.append('item_id', itemId);
            formData.append('quantity', '1');
            formData.append('csrf_token', '<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_HTML5) ?>');

            fetch('<?= APP_URL ?>/api/cart.php', {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var msgEl = document.getElementById('add-message');
                if (data.success) {
                    var countEl = document.getElementById('cart-count-text');
                    if (countEl) countEl.textContent = data.cart_count;
                    var bar = document.getElementById('cart-bar');
                    if (bar) bar.classList.remove('d-none');
                    msgEl.textContent = data.message || 'Item added to cart!';
                    msgEl.classList.remove('d-none', 'alert-danger');
                    msgEl.classList.add('alert-success');
                } else {
                    msgEl.textContent = data.error || 'Failed to add item.';
                    msgEl.classList.remove('d-none', 'alert-success');
                    msgEl.classList.add('alert-danger');
                }
                setTimeout(function() { msgEl.classList.add('d-none'); }, 3000);
            })
            .catch(function() {
                alert('Failed to add item. Please try again.');
            });
        });
    });
    </script>

<?php else: ?>
    <!-- Prepaid pickup form -->
    <?php
    $prepaidErrors  = [];
    $prepaidSuccess = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $prepaidErrors[] = 'Invalid CSRF token.';
        } else {
            $customerName     = trim($_POST['customer_name'] ?? '');
            $boostOrderNumber = trim($_POST['boost_order_number'] ?? '');
            $estimatedTotal   = (float)($_POST['estimated_food_total'] ?? 0);

            if (empty($customerName)) $prepaidErrors[] = 'Customer name is required.';
            if ($estimatedTotal <= 0) $prepaidErrors[] = 'Please enter a valid estimated total.';

            if (empty($prepaidErrors)) {
                $_SESSION['prepaid_order'] = [
                    'restaurant_id'       => $restaurantId,
                    'restaurant_name'     => $restaurant['name'],
                    'customer_name'       => $customerName,
                    'boost_order_number'  => $boostOrderNumber,
                    'estimated_food_total'=> $estimatedTotal,
                    'order_type'          => 'prepaid_pickup',
                ];
                header('Location: ' . APP_URL . '/order/checkout.php');
                exit;
            }
        }
    }
    ?>

    <?php foreach ($prepaidErrors as $err): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($err, ENT_QUOTES | ENT_HTML5) ?></div>
    <?php endforeach; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <h4 class="card-title mb-3">Place Prepaid Pickup Order</h4>
            <p class="text-muted mb-4">Order from <strong><?= htmlspecialchars($restaurant['name'], ENT_QUOTES | ENT_HTML5) ?></strong> using the app or in-store first, then submit your order details below for courier pickup.</p>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="customer_name">Your Name (on order)</label>
                    <input type="text" class="form-control" id="customer_name" name="customer_name"
                           value="<?= htmlspecialchars($_POST['customer_name'] ?? currentUser()['name'] ?? '', ENT_QUOTES | ENT_HTML5) ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="boost_order_number">Boost / Order Number <span class="text-muted">(optional)</span></label>
                    <input type="text" class="form-control" id="boost_order_number" name="boost_order_number"
                           value="<?= htmlspecialchars($_POST['boost_order_number'] ?? '', ENT_QUOTES | ENT_HTML5) ?>">
                    <div class="form-text">If ordering via the Boost app, enter your order number here.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="estimated_food_total">Estimated Food Total ($)</label>
                    <input type="number" class="form-control" id="estimated_food_total" name="estimated_food_total"
                           min="0.01" step="0.01"
                           value="<?= htmlspecialchars($_POST['estimated_food_total'] ?? '', ENT_QUOTES | ENT_HTML5) ?>" required>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-lg">Continue to Checkout</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
