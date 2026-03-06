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

// Check open status
$status = getRestaurantStatus($restaurantId);

$isDiningHall = ($restaurant['type'] === 'dining_hall');
$allowText    = (bool)$restaurant['allow_text_order'];

// Handle dining hall text order
$textErrors  = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['order_mode'] ?? '') === 'text') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $textErrors[] = 'Invalid CSRF token.';
    } else {
        $desc = trim($_POST['custom_description'] ?? '');
        if (empty($desc)) {
            $textErrors[] = 'Please describe your order.';
        } else {
            $_SESSION['text_order'] = [
                'restaurant_id'      => $restaurantId,
                'restaurant_name'    => $restaurant['name'],
                'custom_description' => $desc,
                'order_type'         => 'dining_hall_text',
            ];
            header('Location: ' . APP_URL . '/order/checkout');
            exit;
        }
    }
}

// Handle prepaid form
$prepaidErrors  = [];
$prepaidSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['order_mode'] ?? '') === 'prepaid') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $prepaidErrors[] = 'Invalid CSRF token.';
    } else {
        $customerName    = trim($_POST['customer_name']          ?? '');
        $boostOrderNum   = trim($_POST['boost_order_number']     ?? '');
        $estimatedTotal  = (float)($_POST['estimated_food_total'] ?? 0);

        if (empty($customerName))    $prepaidErrors[] = 'Customer name is required.';
        if ($estimatedTotal <= 0)    $prepaidErrors[] = 'Please enter a valid estimated total.';

        if (empty($prepaidErrors)) {
            $_SESSION['prepaid_order'] = [
                'restaurant_id'        => $restaurantId,
                'restaurant_name'      => $restaurant['name'],
                'customer_name'        => $customerName,
                'boost_order_number'   => $boostOrderNum,
                'estimated_food_total' => $estimatedTotal,
                'order_type'           => 'prepaid_pickup',
            ];
            header('Location: ' . APP_URL . '/order/checkout');
            exit;
        }
    }
}

// Load categories + items + options for dining halls
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
        $items = $itemStmt->fetchAll();
        // Attach option groups to each item
        foreach ($items as &$item) {
            $item['option_groups'] = getItemOptions((int)$item['id']);
        }
        unset($item);
        $cat['items'] = $items;
    }
    unset($cat);
}

$csrf = generateCsrfToken();
$cartCount = isset($_SESSION['cart']) ? getCartItemCount($_SESSION['cart']) : 0;
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/order/">Restaurants</a></li>
        <li class="breadcrumb-item active"><?= htmlspecialchars($restaurant['name'], ENT_QUOTES | ENT_HTML5) ?></li>
    </ol>
</nav>

<?php if ($restaurant['banner_image']): ?>
    <img src="<?= htmlspecialchars(UPLOAD_URL . $restaurant['banner_image'], ENT_QUOTES | ENT_HTML5) ?>"
         alt="" class="restaurant-banner">
<?php endif; ?>

<div class="mb-4">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <h2 class="fw-bold mb-0"><?= htmlspecialchars($restaurant['name'], ENT_QUOTES | ENT_HTML5) ?></h2>
        <span class="badge bg-<?= $status['class'] ?>">
            <i class="ti ti-clock me-1"></i><?= htmlspecialchars($status['label'], ENT_QUOTES | ENT_HTML5) ?>
        </span>
        <?php if ($restaurant['meal_swipe_eligible']): ?>
            <span class="badge bg-purple"><i class="ti ti-id-badge-2 me-1"></i>Meal Swipe Accepted</span>
        <?php endif; ?>
    </div>
    <?php if ($restaurant['location']): ?>
        <p class="text-muted mb-1 mt-2"><i class="ti ti-map-pin me-1"></i><?= htmlspecialchars($restaurant['location'], ENT_QUOTES | ENT_HTML5) ?></p>
    <?php endif; ?>
    <?php if ($restaurant['description']): ?>
        <p class="mb-0 text-muted"><?= htmlspecialchars($restaurant['description'], ENT_QUOTES | ENT_HTML5) ?></p>
    <?php endif; ?>
</div>

<?php if (!$status['orders_accepted']): ?>
    <div class="alert alert-warning">
        <i class="ti ti-clock-off me-2"></i>
        This restaurant is currently not accepting orders (<?= htmlspecialchars($status['label'], ENT_QUOTES | ENT_HTML5) ?>).
    </div>

<?php elseif ($isDiningHall): ?>
    <!-- Dining Hall: tabs for Menu Browse vs Text Order -->
    <?php $hasTabs = $allowText && !empty($categories); ?>
    <?php if ($hasTabs): ?>
        <ul class="nav nav-tabs mb-4" id="orderTabs">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#menu-tab">
                    <i class="ti ti-clipboard-list me-1"></i>Browse Menu
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#text-tab">
                    <i class="ti ti-pencil me-1"></i>Text Order
                </button>
            </li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="menu-tab">
    <?php endif; ?>

    <?php if (!empty($categories)): ?>
        <!-- Cart info bar -->
        <div id="cart-bar" class="alert alert-primary d-flex justify-content-between align-items-center mb-4 <?= $cartCount === 0 ? 'd-none' : '' ?>">
            <span><i class="ti ti-shopping-cart me-2"></i><strong id="cart-count-text"><?= $cartCount ?></strong> item(s) in cart</span>
            <a href="<?= APP_URL ?>/order/cart" class="btn btn-sm btn-primary">View Cart</a>
        </div>

        <div id="add-message" class="alert d-none mb-3"></div>

        <div class="row g-3">
            <?php foreach ($categories as $cat): ?>
                <?php if (empty($cat['items'])) continue; ?>
                <div class="col-12">
                    <h5 class="fw-bold text-primary mb-3">
                        <i class="ti ti-tag me-1"></i><?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_HTML5) ?>
                        <span class="badge bg-secondary ms-1 fw-normal"><?= count($cat['items']) ?></span>
                    </h5>
                    <div class="row g-3">
                        <?php foreach ($cat['items'] as $item): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="card border-0 shadow-sm h-100 hover-lift">
                                    <?php if ($item['image_path']): ?>
                                        <img src="<?= htmlspecialchars(UPLOAD_URL . $item['image_path'], ENT_QUOTES | ENT_HTML5) ?>"
                                             alt="" class="card-img-top" style="height:140px;object-fit:cover;">
                                    <?php endif; ?>
                                    <div class="card-body d-flex flex-column p-3">
                                        <div class="d-flex justify-content-between align-items-start mb-1">
                                            <strong class="lh-sm"><?= htmlspecialchars($item['name'], ENT_QUOTES | ENT_HTML5) ?></strong>
                                            <?php if ($item['meal_eligible']): ?>
                                                <span class="badge bg-green text-white ms-1 flex-shrink-0">Meal</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($item['description']): ?>
                                            <p class="small text-muted mb-2 flex-grow-1"><?= htmlspecialchars($item['description'], ENT_QUOTES | ENT_HTML5) ?></p>
                                        <?php endif; ?>
                                        <div class="d-flex justify-content-between align-items-center mt-auto">
                                            <?php if ((float)$item['base_price'] > 0): ?>
                                                <span class="fw-bold text-primary"><?= formatMoney((float)$item['base_price']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">Included</span>
                                            <?php endif; ?>
                                            <?php if (!empty($item['option_groups'])): ?>
                                                <button class="btn btn-primary btn-sm"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#optModal<?= (int)$item['id'] ?>">
                                                    <i class="ti ti-plus me-1"></i>Customize
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-primary btn-sm add-to-cart-btn"
                                                        data-item-id="<?= (int)$item['id'] ?>">
                                                    <i class="ti ti-plus me-1"></i>Add
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($item['option_groups'])): ?>
                            <!-- Customization Modal -->
                            <div class="modal fade" id="optModal<?= (int)$item['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title"><?= htmlspecialchars($item['name'], ENT_QUOTES | ENT_HTML5) ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body" id="optModalBody<?= (int)$item['id'] ?>">
                                            <p class="text-muted small mb-3">Customise your order:</p>
                                            <?php foreach ($item['option_groups'] as $og): ?>
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">
                                                        <?= htmlspecialchars($og['name'], ENT_QUOTES | ENT_HTML5) ?>
                                                        <?php if ($og['required']): ?><span class="badge bg-danger ms-1">Required</span><?php endif; ?>
                                                    </label>
                                                    <?php foreach ($og['choices'] as $ch): ?>
                                                        <div class="form-check">
                                                            <input class="form-check-input opt-choice"
                                                                   type="<?= $og['type'] === 'single' ? 'radio' : 'checkbox' ?>"
                                                                   name="opt_<?= (int)$item['id'] ?>_<?= (int)$og['id'] ?>"
                                                                   id="ch_<?= (int)$item['id'] ?>_<?= (int)$ch['id'] ?>"
                                                                   data-group-id="<?= (int)$og['id'] ?>"
                                                                   data-group-name="<?= htmlspecialchars($og['name'], ENT_QUOTES | ENT_HTML5) ?>"
                                                                   data-choice-id="<?= (int)$ch['id'] ?>"
                                                                   data-choice-name="<?= htmlspecialchars($ch['name'], ENT_QUOTES | ENT_HTML5) ?>"
                                                                   data-price="<?= (float)$ch['price_modifier'] ?>"
                                                                   value="<?= (int)$ch['id'] ?>">
                                                            <label class="form-check-label" for="ch_<?= (int)$item['id'] ?>_<?= (int)$ch['id'] ?>">
                                                                <?= htmlspecialchars($ch['name'], ENT_QUOTES | ENT_HTML5) ?>
                                                                <?php if ((float)$ch['price_modifier'] > 0): ?>
                                                                    <span class="text-muted">+<?= formatMoney((float)$ch['price_modifier']) ?></span>
                                                                <?php elseif ((float)$ch['price_modifier'] < 0): ?>
                                                                    <span class="text-success"><?= formatMoney((float)$ch['price_modifier']) ?></span>
                                                                <?php endif; ?>
                                                            </label>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="mt-3 fw-bold">
                                                Total: <span id="optTotal<?= (int)$item['id'] ?>"><?= formatMoney((float)$item['base_price']) ?></span>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="button" class="btn btn-primary add-customized-btn"
                                                    data-item-id="<?= (int)$item['id'] ?>"
                                                    data-base-price="<?= (float)$item['base_price'] ?>"
                                                    data-category-id="<?= (int)$item['category_id'] ?>">
                                                <i class="ti ti-shopping-cart me-1"></i>Add to Cart
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            <i class="ti ti-info-circle me-1"></i>No menu items available yet. Use the Text Order tab to place your order.
        </div>
    <?php endif; ?>

    <?php if ($hasTabs): ?>
            </div><!-- /menu-tab -->
            <div class="tab-pane fade" id="text-tab">
    <?php endif; ?>

    <?php if ($allowText || empty($categories)): ?>
        <!-- Text Order Form -->
        <div class="card shadow-sm border-0 mt-3">
            <div class="card-body">
                <h5 class="card-title fw-bold mb-1">
                    <i class="ti ti-pencil me-2 text-primary"></i>Text Order
                </h5>
                <p class="text-muted small mb-3">Type exactly what you'd like. This counts as <strong>1 meal swipe</strong>.</p>

                <?php foreach ($textErrors as $e): ?>
                    <div class="alert alert-danger py-2"><?= htmlspecialchars($e, ENT_QUOTES | ENT_HTML5) ?></div>
                <?php endforeach; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="order_mode" value="text">
                    <div class="mb-3">
                        <textarea class="form-control" name="custom_description" rows="4"
                                  placeholder="e.g. Chicken sandwich with fries and a Coke, no pickles..."
                                  required><?= htmlspecialchars($_POST['custom_description'] ?? '', ENT_QUOTES | ENT_HTML5) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-arrow-right me-1"></i>Continue to Checkout
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($hasTabs): ?>
            </div><!-- /text-tab -->
        </div><!-- /tab-content -->
    <?php endif; ?>

<?php else: ?>
    <!-- Prepaid pickup form (non-dining-hall) -->
    <?php foreach ($prepaidErrors as $e): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($e, ENT_QUOTES | ENT_HTML5) ?></div>
    <?php endforeach; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <h4 class="card-title mb-1 fw-bold">Place Prepaid Pickup Order</h4>
            <p class="text-muted mb-4">Order from <strong><?= htmlspecialchars($restaurant['name'], ENT_QUOTES | ENT_HTML5) ?></strong> using the app or in-store first, then submit for courier pickup.</p>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="order_mode" value="prepaid">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Your Name (on order)</label>
                    <input type="text" class="form-control" name="customer_name"
                           value="<?= htmlspecialchars($_POST['customer_name'] ?? currentUser()['name'] ?? '', ENT_QUOTES | ENT_HTML5) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Boost / Order Number <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" class="form-control" name="boost_order_number"
                           value="<?= htmlspecialchars($_POST['boost_order_number'] ?? '', ENT_QUOTES | ENT_HTML5) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Estimated Food Total ($)</label>
                    <input type="number" class="form-control" name="estimated_food_total"
                           min="0.01" step="0.01"
                           value="<?= htmlspecialchars($_POST['estimated_food_total'] ?? '', ENT_QUOTES | ENT_HTML5) ?>" required>
                </div>
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="ti ti-arrow-right me-1"></i>Continue to Checkout
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<script>
var CSRF = <?= json_encode($csrf) ?>;

// Simple add-to-cart (no options)
document.querySelectorAll('.add-to-cart-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        addToCart(this.dataset.itemId, {}, btn);
    });
});

// Customized add-to-cart
document.querySelectorAll('.add-customized-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var itemId    = this.dataset.itemId;
        var modal     = document.getElementById('optModalBody' + itemId);
        var options   = {};
        var extraPrice = 0;
        modal.querySelectorAll('.opt-choice:checked').forEach(function(inp) {
            var gid = inp.dataset.groupId;
            if (!options[gid]) options[gid] = {name: inp.dataset.groupName, choices: []};
            options[gid].choices.push({id: inp.dataset.choiceId, name: inp.dataset.choiceName, price: parseFloat(inp.dataset.price)});
            extraPrice += parseFloat(inp.dataset.price);
        });
        addToCart(itemId, options, btn, extraPrice);
        // Close modal
        var bsModal = bootstrap.Modal.getInstance(btn.closest('.modal'));
        if (bsModal) bsModal.hide();
    });
});

// Live price update in modals
document.querySelectorAll('.opt-choice').forEach(function(inp) {
    inp.addEventListener('change', function() {
        var modalBody = this.closest('.modal-body');
        var btn       = modalBody.closest('.modal-content').querySelector('.add-customized-btn');
        var base      = parseFloat(btn.dataset.basePrice) || 0;
        var extra     = 0;
        modalBody.querySelectorAll('.opt-choice:checked').forEach(function(c) {
            extra += parseFloat(c.dataset.price);
        });
        var totalEl = modalBody.querySelector('[id^="optTotal"]');
        if (totalEl) totalEl.textContent = '$' + (base + extra).toFixed(2);
    });
});

function addToCart(itemId, options, triggerEl, extraPrice) {
    var fd = new FormData();
    fd.append('action',      'add');
    fd.append('item_id',     itemId);
    fd.append('quantity',    '1');
    fd.append('options',     JSON.stringify(options));
    fd.append('extra_price', extraPrice || 0);
    fd.append('csrf_token',  CSRF);

    if (triggerEl) { triggerEl.disabled = true; }
    fetch('/api/cart', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (triggerEl) triggerEl.disabled = false;
            var msgEl = document.getElementById('add-message');
            if (data.success) {
                var cc = document.getElementById('cart-count-text');
                if (cc) cc.textContent = data.cart_count;
                var bar = document.getElementById('cart-bar');
                if (bar) bar.classList.remove('d-none');
                if (msgEl) {
                    msgEl.textContent = data.message || 'Added to cart!';
                    msgEl.className = 'alert alert-success mb-3';
                }
            } else {
                if (msgEl) {
                    msgEl.textContent = data.error || 'Failed to add item.';
                    msgEl.className = 'alert alert-danger mb-3';
                }
            }
            if (msgEl) setTimeout(function() { msgEl.classList.add('d-none'); }, 3000);
        })
        .catch(function() { if (triggerEl) triggerEl.disabled = false; });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
