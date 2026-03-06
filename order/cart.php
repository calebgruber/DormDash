<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$cart = $_SESSION['cart'] ?? [];
$csrf = generateCsrfToken();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<h2 class="mb-4">🛒 Your Cart</h2>

<?php if (empty($cart)): ?>
    <div class="alert alert-info">Your cart is empty.</div>
    <a href="<?= APP_URL ?>/order/" class="btn btn-primary">Browse Restaurants</a>
<?php else: ?>
    <div id="cart-message" class="alert d-none mb-3"></div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Item</th>
                            <th class="text-center" style="width:120px;">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Subtotal</th>
                            <th class="text-center">Remove</th>
                        </tr>
                    </thead>
                    <tbody id="cart-tbody">
                        <?php foreach ($cart as $itemId => $item): ?>
                            <tr id="row-<?= (int)$itemId ?>">
                                <td class="align-middle"><?= htmlspecialchars($item['name'], ENT_QUOTES | ENT_HTML5) ?></td>
                                <td class="align-middle text-center">
                                    <div class="input-group input-group-sm" style="max-width:100px; margin:auto;">
                                        <button class="btn btn-outline-secondary qty-btn" type="button"
                                                data-item-id="<?= (int)$itemId ?>"
                                                data-action="decrease">-</button>
                                        <input type="number" class="form-control text-center qty-input"
                                               value="<?= (int)$item['quantity'] ?>" min="1" max="99"
                                               data-item-id="<?= (int)$itemId ?>" readonly>
                                        <button class="btn btn-outline-secondary qty-btn" type="button"
                                                data-item-id="<?= (int)$itemId ?>"
                                                data-action="increase">+</button>
                                    </div>
                                </td>
                                <td class="align-middle text-end"><?= formatMoney((float)$item['unit_price']) ?></td>
                                <td class="align-middle text-end subtotal-cell" data-item-id="<?= (int)$itemId ?>">
                                    <?= formatMoney((float)$item['unit_price'] * (int)$item['quantity']) ?>
                                </td>
                                <td class="align-middle text-center">
                                    <button class="btn btn-outline-danger btn-sm remove-btn"
                                            data-item-id="<?= (int)$itemId ?>">✕</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td colspan="3" class="text-end">Food Total:</td>
                            <td class="text-end" id="food-total"><?= formatMoney(getCartTotal($cart)) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex gap-3 flex-wrap">
        <a href="<?= APP_URL ?>/order/checkout.php" class="btn btn-success btn-lg">Proceed to Checkout →</a>
        <a href="<?= APP_URL ?>/order/" class="btn btn-outline-secondary">Continue Shopping</a>
    </div>

    <script>
    var csrfToken = '<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_HTML5) ?>';
    var appUrl = '<?= APP_URL ?>';

    function cartRequest(data, callback) {
        var formData = new FormData();
        Object.keys(data).forEach(function(k) { formData.append(k, data[k]); });
        formData.append('csrf_token', csrfToken);
        fetch('/api/cart', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(callback)
            .catch(function() { showMsg('Request failed.', false); });
    }

    function showMsg(msg, ok) {
        var el = document.getElementById('cart-message');
        el.textContent = msg;
        el.className = 'alert mb-3 ' + (ok ? 'alert-success' : 'alert-danger');
        setTimeout(function() { el.classList.add('d-none'); }, 3000);
    }

    function updateFoodTotal() {
        var total = 0;
        document.querySelectorAll('[data-item-id]').forEach(function(inp) {
            if (!inp.classList.contains('qty-input')) return;
            var itemId = inp.getAttribute('data-item-id');
            var qty = parseInt(inp.value, 10);
            var subtotalCell = document.querySelector('.subtotal-cell[data-item-id="' + itemId + '"]');
            if (subtotalCell) {
                var unitPrice = parseFloat(subtotalCell.getAttribute('data-unit-price') || 0);
                total += unitPrice * qty;
            }
        });
    }

    // Cache unit prices from existing subtotals
    document.querySelectorAll('.qty-input').forEach(function(inp) {
        var itemId = inp.getAttribute('data-item-id');
        var qty = parseInt(inp.value, 10);
        var subtotalCell = document.querySelector('.subtotal-cell[data-item-id="' + itemId + '"]');
        if (subtotalCell) {
            var subtotalText = subtotalCell.textContent.replace('$', '').trim();
            var subtotal = parseFloat(subtotalText) || 0;
            subtotalCell.setAttribute('data-unit-price', (subtotal / qty).toFixed(2));
        }
    });

    document.querySelectorAll('.qty-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var itemId = this.getAttribute('data-item-id');
            var action = this.getAttribute('data-action');
            var inp = document.querySelector('.qty-input[data-item-id="' + itemId + '"]');
            var qty = parseInt(inp.value, 10);
            qty = (action === 'increase') ? qty + 1 : Math.max(1, qty - 1);

            cartRequest({ action: 'update', item_id: itemId, quantity: qty }, function(data) {
                if (data.success) {
                    inp.value = qty;
                    var subtotalCell = document.querySelector('.subtotal-cell[data-item-id="' + itemId + '"]');
                    if (subtotalCell) {
                        var up = parseFloat(subtotalCell.getAttribute('data-unit-price') || 0);
                        subtotalCell.textContent = '$' + (up * qty).toFixed(2);
                    }
                    document.getElementById('food-total').textContent = '$' + data.cart_total;
                } else {
                    showMsg(data.error || 'Update failed.', false);
                }
            });
        });
    });

    document.querySelectorAll('.remove-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var itemId = this.getAttribute('data-item-id');
            cartRequest({ action: 'remove', item_id: itemId }, function(data) {
                if (data.success) {
                    var row = document.getElementById('row-' + itemId);
                    if (row) row.remove();
                    document.getElementById('food-total').textContent = '$' + data.cart_total;
                    if (data.cart_count === 0) location.reload();
                } else {
                    showMsg(data.error || 'Remove failed.', false);
                }
            });
        });
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
