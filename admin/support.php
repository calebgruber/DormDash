<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db   = getDB();
$csrf = generateCsrfToken();

// If viewing a specific thread
$orderId = (int)($_GET['order_id'] ?? 0);
$selectedOrder = null;
if ($orderId > 0) {
    $oStmt = $db->prepare(
        'SELECT o.*, r.name AS restaurant_name,
                u.name  AS customer_name_display,
                u2.name AS courier_name_display
         FROM orders o
         JOIN restaurants r ON r.id = o.restaurant_id
         JOIN users u       ON u.id  = o.customer_id
         LEFT JOIN users u2 ON u2.id = o.courier_id
         WHERE o.id = ?'
    );
    $oStmt->execute([$orderId]);
    $selectedOrder = $oStmt->fetch();
}

// Items for sticky card
$selectedItems = [];
if ($selectedOrder) {
    try {
        $iStmt = $db->prepare(
            'SELECT oi.*, mi.name AS item_name
             FROM order_items oi JOIN menu_items mi ON mi.id = oi.menu_item_id
             WHERE oi.order_id = ?'
        );
        $iStmt->execute([$orderId]);
        $selectedItems = $iStmt->fetchAll();
    } catch (Throwable $e) {}
}

// List of orders with support messages
$threads = $db->query(
    'SELECT o.id, o.created_at, r.name AS restaurant_name, u.name AS customer_name_display,
            COUNT(sm.id) AS total_msgs,
            SUM(CASE WHEN sm.is_from_admin = 0 AND sm.read_at IS NULL THEN 1 ELSE 0 END) AS unread
     FROM support_messages sm
     JOIN orders o ON o.id = sm.order_id
     JOIN restaurants r ON r.id = o.restaurant_id
     JOIN users u ON u.id = o.customer_id
     GROUP BY sm.order_id
     ORDER BY unread DESC, sm.order_id DESC'
)->fetchAll();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold"><i class="ti ti-message-circle me-2 text-primary"></i>Customer Support</h2>
    <a href="<?= APP_URL ?>/admin/dashboard" class="btn btn-outline-secondary btn-sm">
        <i class="ti ti-arrow-left me-1"></i> Dashboard
    </a>
</div>

<div class="row g-3 align-items-start">

    <!-- ── Thread list ──────────────────────────────────────────────────────── -->
    <div class="col-md-3" style="position:sticky;top:1.5rem;">
        <div class="card shadow-sm border-0">
            <div class="card-header fw-semibold">
                <i class="ti ti-list me-1"></i>Threads
            </div>
            <div class="list-group list-group-flush" style="max-height:70vh;overflow-y:auto;">
                <?php if (empty($threads)): ?>
                    <div class="list-group-item text-muted small">No support messages yet.</div>
                <?php else: ?>
                    <?php foreach ($threads as $t): ?>
                        <a href="<?= APP_URL ?>/admin/support?order_id=<?= (int)$t['id'] ?>"
                           class="list-group-item list-group-item-action <?= $orderId === (int)$t['id'] ? 'active' : '' ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-semibold small">Order #<?= (int)$t['id'] ?></div>
                                    <div class="text-muted" style="font-size:.75rem;"><?= htmlspecialchars($t['customer_name_display'], ENT_QUOTES | ENT_HTML5) ?></div>
                                    <div class="text-muted" style="font-size:.7rem;"><?= htmlspecialchars($t['restaurant_name'], ENT_QUOTES | ENT_HTML5) ?></div>
                                </div>
                                <?php if ($t['unread'] > 0): ?>
                                    <span class="badge bg-danger"><?= (int)$t['unread'] ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$selectedOrder): ?>
    <!-- Empty state -->
    <div class="col-md-9">
        <div class="card shadow-sm border-0">
            <div class="card-body text-center text-muted py-5">
                <i class="ti ti-message-circle" style="font-size:3rem;opacity:.3;"></i>
                <p class="mt-2">Select a thread from the left to view messages.</p>
            </div>
        </div>
    </div>

    <?php else: ?>

    <!-- ── Chat panel ──────────────────────────────────────────────────────── -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold">
                    Order #<?= (int)$orderId ?> — <?= htmlspecialchars($selectedOrder['customer_name_display'], ENT_QUOTES | ENT_HTML5) ?>
                </span>
                <small class="text-muted"><?= htmlspecialchars($selectedOrder['restaurant_name'], ENT_QUOTES | ENT_HTML5) ?></small>
            </div>
            <div class="card-body p-0">
                <div id="chat-box" style="height:420px;overflow-y:auto;padding:1rem;">
                    <div id="chat-messages"></div>
                    <div id="typing-indicator" class="d-none mb-3 d-flex justify-content-start">
                        <div style="max-width:75%">
                            <div class="rounded-3 px-3 py-2 chat-bubble-received">
                                <span class="chat-typing-dot"></span>
                                <span class="chat-typing-dot"></span>
                                <span class="chat-typing-dot"></span>
                            </div>
                        </div>
                    </div>
                    <div id="chat-loading" class="text-center text-muted py-4">
                        <i class="ti ti-loader-2 me-1"></i>Loading…
                    </div>
                </div>
                <div class="border-top p-3">
                    <form id="support-form" class="d-flex gap-2">
                        <input type="hidden" id="csrf-token" value="<?= htmlspecialchars($csrf) ?>">
                        <textarea class="form-control form-control-sm" id="msg-input" rows="2"
                                  placeholder="Reply as support…" style="resize:none;" required></textarea>
                        <button type="submit" class="btn btn-primary align-self-end">
                            <i class="ti ti-send"></i> Reply
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Sticky order info card ──────────────────────────────────────────── -->
    <div class="col-md-3">
        <div class="card shadow-sm border-0" style="position:sticky;top:1.5rem;">
            <div class="card-header fw-bold">
                <i class="ti ti-receipt me-2 text-primary"></i>Order Info
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">Order #</dt>
                    <dd class="col-7"><?= (int)$orderId ?></dd>

                    <dt class="col-5">Customer</dt>
                    <dd class="col-7"><?= htmlspecialchars($selectedOrder['customer_name_display'], ENT_QUOTES | ENT_HTML5) ?></dd>

                    <dt class="col-5">Restaurant</dt>
                    <dd class="col-7"><?= htmlspecialchars($selectedOrder['restaurant_name'], ENT_QUOTES | ENT_HTML5) ?></dd>

                    <dt class="col-5">Status</dt>
                    <dd class="col-7">
                        <span class="badge bg-<?= getOrderStatusBadgeClass($selectedOrder['status']) ?>">
                            <?= formatOrderStatus($selectedOrder['status']) ?>
                        </span>
                    </dd>

                    <dt class="col-5">Type</dt>
                    <dd class="col-7"><?= htmlspecialchars(ucwords(str_replace('_',' ',$selectedOrder['order_type'])), ENT_QUOTES | ENT_HTML5) ?></dd>

                    <?php if ($selectedOrder['courier_name_display']): ?>
                    <dt class="col-5">Courier</dt>
                    <dd class="col-7"><?= htmlspecialchars($selectedOrder['courier_name_display'], ENT_QUOTES | ENT_HTML5) ?></dd>
                    <?php endif; ?>

                    <dt class="col-5">Address</dt>
                    <dd class="col-7"><?= htmlspecialchars($selectedOrder['delivery_address'], ENT_QUOTES | ENT_HTML5) ?></dd>

                    <?php if ($selectedOrder['food_total'] > 0): ?>
                    <dt class="col-5">Food</dt>
                    <dd class="col-7">$<?= number_format($selectedOrder['food_total'], 2) ?></dd>
                    <?php endif; ?>

                    <dt class="col-5">Fees</dt>
                    <dd class="col-7">$<?= number_format(($selectedOrder['delivery_fee'] ?? 0) + ($selectedOrder['service_fee'] ?? 0), 2) ?></dd>

                    <dt class="col-5">Tip</dt>
                    <dd class="col-7">$<?= number_format($selectedOrder['tip_amount'] ?? 0, 2) ?></dd>

                    <dt class="col-5 fw-bold">Total</dt>
                    <dd class="col-7 fw-bold">$<?= number_format(($selectedOrder['food_total'] ?? 0) + ($selectedOrder['delivery_fee'] ?? 0) + ($selectedOrder['service_fee'] ?? 0) + ($selectedOrder['tip_amount'] ?? 0), 2) ?></dd>
                </dl>

                <?php if (!empty($selectedItems)): ?>
                <hr class="my-2">
                <div class="small fw-semibold mb-1">Items</div>
                <ul class="list-unstyled mb-0 small">
                    <?php foreach ($selectedItems as $it): ?>
                        <li>× <?= (int)$it['quantity'] ?> <?= htmlspecialchars($it['item_name'], ENT_QUOTES | ENT_HTML5) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php elseif (!empty($selectedOrder['custom_description'])): ?>
                <hr class="my-2">
                <div class="small fw-semibold mb-1">Description</div>
                <div class="small text-muted"><?= htmlspecialchars($selectedOrder['custom_description'], ENT_QUOTES | ENT_HTML5) ?></div>
                <?php endif; ?>

                <hr class="my-2">
                <a href="<?= APP_URL ?>/admin/orders?order_id=<?= (int)$orderId ?>" class="btn btn-outline-secondary btn-sm w-100">
                    <i class="ti ti-external-link me-1"></i>View in Orders
                </a>
            </div>
        </div>
    </div>

    <script>
    var ORDER_ID  = <?= json_encode($orderId) ?>;
    var IS_ADMIN  = true;
    var CSRF      = <?= json_encode($csrf) ?>;
    var lastCount = 0;

    function escHtml(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    function renderMessages(msgs, otherTyping) {
        var box = document.getElementById('chat-messages');
        document.getElementById('chat-loading').style.display = 'none';
        box.innerHTML = '';
        if (!msgs.length) {
            box.innerHTML = '<p class="text-muted text-center small py-4">No messages in this thread.</p>';
        }
        msgs.forEach(function(m) {
            var isMe = (m.is_from_admin == 1);
            var div  = document.createElement('div');
            div.className = 'mb-3 d-flex ' + (isMe ? 'justify-content-end' : 'justify-content-start');
            var ts = new Date(m.created_at.replace(' ','T')).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
            div.innerHTML =
                '<div style="max-width:75%">'
                + '<div class="rounded-3 px-3 py-2 ' + (isMe ? 'bg-primary text-white' : 'chat-bubble-received') + '">'
                + '<div class="small fw-semibold mb-1">' + escHtml(isMe ? 'Support (You)' : m.sender_name) + '</div>'
                + '<div>' + escHtml(m.message).replace(/\n/g,'<br>') + '</div>'
                + '</div>'
                + '<div class="text-muted" style="font-size:.7rem;margin-top:2px;text-align:' + (isMe ? 'right' : 'left') + '">' + ts + '</div>'
                + '</div>';
            box.appendChild(div);
        });
        var ti = document.getElementById('typing-indicator');
        ti.classList.toggle('d-none', !otherTyping);
        var cb = document.getElementById('chat-box');
        cb.scrollTop = cb.scrollHeight;
    }

    function loadMessages() {
        fetch(<?= json_encode(APP_URL) ?> + '/api/support?action=get&order_id=' + ORDER_ID)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    var changed = d.messages.length !== lastCount;
                    lastCount = d.messages.length;
                    if (changed || d.other_typing !== undefined) {
                        renderMessages(d.messages, d.other_typing);
                    }
                }
            }).catch(function() {});
    }

    var lastTypingSent = 0;
    document.getElementById('msg-input').addEventListener('input', function() {
        var now = Date.now();
        if (now - lastTypingSent > 2000) {
            lastTypingSent = now;
            var fd = new FormData();
            fd.append('action',   'typing');
            fd.append('order_id', ORDER_ID);
            fetch(<?= json_encode(APP_URL) ?> + '/api/support', { method:'POST', body:fd }).catch(function(){});
        }
    });

    document.getElementById('support-form').addEventListener('submit', function(e) {
        e.preventDefault();
        var inp = document.getElementById('msg-input');
        var msg = inp.value.trim();
        if (!msg) return;
        var fd = new FormData();
        fd.append('action',     'send');
        fd.append('order_id',   ORDER_ID);
        fd.append('message',    msg);
        fd.append('csrf_token', CSRF);
        fetch(<?= json_encode(APP_URL) ?> + '/api/support', { method:'POST', body:fd })
            .then(function(r) { return r.json(); })
            .then(function(d) { if (d.success) { inp.value = ''; lastCount = 0; loadMessages(); } });
    });

    loadMessages();
    setInterval(loadMessages, 3000);
    </script>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
