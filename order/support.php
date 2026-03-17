<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$user    = currentUser();
$db      = getDB();
$orderId = (int)($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    header('Location: ' . APP_URL . '/order/history');
    exit;
}

$oStmt = $db->prepare(
    'SELECT o.*, r.name AS restaurant_name,
            u2.name AS courier_name_display
     FROM orders o
     JOIN restaurants r ON r.id = o.restaurant_id
     LEFT JOIN users u2 ON u2.id = o.courier_id
     WHERE o.id = ?'
);
$oStmt->execute([$orderId]);
$order = $oStmt->fetch();

if (!$order || (int)$order['customer_id'] !== (int)$user['id']) {
    header('Location: ' . APP_URL . '/order/history');
    exit;
}

// Fetch order items for the sidebar card
$items = [];
try {
    $iStmt = $db->prepare(
        'SELECT oi.*, mi.name AS item_name
         FROM order_items oi JOIN menu_items mi ON mi.id = oi.menu_item_id
         WHERE oi.order_id = ?'
    );
    $iStmt->execute([$orderId]);
    $items = $iStmt->fetchAll();
} catch (Throwable $e) {}

$csrf = generateCsrfToken();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/order/history">My Orders</a></li>
        <li class="breadcrumb-item active">Support — Order #<?= (int)$orderId ?></li>
    </ol>
</nav>

<div class="row g-4 align-items-start">

    <!-- ── Sticky order info card ──────────────────────────────────────────── -->
    <div class="col-md-4">
        <div class="card shadow-sm border-0" style="position:sticky;top:1.5rem;">
            <div class="card-header fw-bold">
                <i class="ti ti-receipt me-2 text-primary"></i>Order Details
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">Order #</dt>
                    <dd class="col-7"><?= (int)$orderId ?></dd>

                    <dt class="col-5">Restaurant</dt>
                    <dd class="col-7"><?= htmlspecialchars($order['restaurant_name'], ENT_QUOTES | ENT_HTML5) ?></dd>

                    <dt class="col-5">Status</dt>
                    <dd class="col-7">
                        <span class="badge bg-<?= getOrderStatusBadgeClass($order['status']) ?>">
                            <?= formatOrderStatus($order['status']) ?>
                        </span>
                    </dd>

                    <dt class="col-5">Type</dt>
                    <dd class="col-7"><?= htmlspecialchars(ucwords(str_replace('_',' ',$order['order_type'])), ENT_QUOTES | ENT_HTML5) ?></dd>

                    <?php if ($order['courier_name_display']): ?>
                    <dt class="col-5">Courier</dt>
                    <dd class="col-7"><?= htmlspecialchars($order['courier_name_display'], ENT_QUOTES | ENT_HTML5) ?></dd>
                    <?php endif; ?>

                    <dt class="col-5">Address</dt>
                    <dd class="col-7"><?= htmlspecialchars($order['delivery_address'], ENT_QUOTES | ENT_HTML5) ?></dd>

                    <?php if ($order['food_total'] > 0): ?>
                    <dt class="col-5">Food</dt>
                    <dd class="col-7">$<?= number_format($order['food_total'], 2) ?></dd>
                    <?php endif; ?>

                    <dt class="col-5">Fees</dt>
                    <dd class="col-7">$<?= number_format(($order['delivery_fee'] ?? 0) + ($order['service_fee'] ?? 0), 2) ?></dd>

                    <dt class="col-5">Tip</dt>
                    <dd class="col-7">$<?= number_format($order['tip_amount'] ?? 0, 2) ?></dd>

                    <dt class="col-5 fw-bold">Total</dt>
                    <dd class="col-7 fw-bold">$<?= number_format(($order['food_total'] ?? 0) + ($order['delivery_fee'] ?? 0) + ($order['service_fee'] ?? 0) + ($order['tip_amount'] ?? 0), 2) ?></dd>
                </dl>

                <?php if (!empty($items)): ?>
                <hr class="my-2">
                <div class="small fw-semibold mb-1">Items</div>
                <ul class="list-unstyled mb-0 small">
                    <?php foreach ($items as $it): ?>
                        <li>× <?= (int)$it['quantity'] ?> <?= htmlspecialchars($it['item_name'], ENT_QUOTES | ENT_HTML5) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php elseif (!empty($order['custom_description'])): ?>
                <hr class="my-2">
                <div class="small fw-semibold mb-1">Order Description</div>
                <div class="small text-muted"><?= htmlspecialchars($order['custom_description'], ENT_QUOTES | ENT_HTML5) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Chat ────────────────────────────────────────────────────────────── -->
    <div class="col-md-8">
        <div class="card shadow-sm border-0">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold">
                    <i class="ti ti-message-circle me-2 text-primary"></i>Support Chat
                </span>
                <small class="text-muted"><?= htmlspecialchars($order['restaurant_name'], ENT_QUOTES | ENT_HTML5) ?></small>
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
                        <i class="ti ti-loader-2 me-1"></i>Loading messages…
                    </div>
                </div>

                <div class="border-top p-3">
                    <form id="support-form" class="d-flex gap-2">
                        <input type="hidden" id="csrf-token" value="<?= htmlspecialchars($csrf) ?>">
                        <textarea class="form-control form-control-sm" id="msg-input" rows="2"
                                  placeholder="Type your message…" style="resize:none;" required></textarea>
                        <button type="submit" class="btn btn-primary align-self-end">
                            <i class="ti ti-send"></i>
                        </button>
                    </form>
                    <p class="text-muted small mt-2 mb-0">Support is monitored by our team. We'll reply as soon as possible.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var ORDER_ID     = <?= json_encode($orderId) ?>;
var IS_ADMIN     = false;
var CSRF         = <?= json_encode($csrf) ?>;
var lastCount    = 0;
var lastTypingAt = 0;
var typingTimer  = null;

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
        box.innerHTML = '<p class="text-muted text-center small py-4">No messages yet. Describe your issue below.</p>';
    }
    msgs.forEach(function(m) {
        var isMe = (m.is_from_admin == 1) === IS_ADMIN;
        var div  = document.createElement('div');
        div.className = 'mb-3 d-flex ' + (isMe ? 'justify-content-end' : 'justify-content-start');
        var ts = new Date(m.created_at.replace(' ','T') + 'Z').toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
        div.innerHTML =
            '<div style="max-width:75%">'
            + '<div class="rounded-3 px-3 py-2 ' + (isMe ? 'bg-primary text-white' : 'chat-bubble-received') + '">'
            + '<div class="small fw-semibold mb-1">' + escHtml(isMe ? 'You' : m.sender_name) + '</div>'
            + '<div>' + escHtml(m.message).replace(/\n/g,'<br>') + '</div>'
            + '</div>'
            + '<div class="text-muted" style="font-size:.7rem;margin-top:2px;text-align:' + (isMe ? 'right' : 'left') + '">' + ts + '</div>'
            + '</div>';
        box.appendChild(div);
    });
    // Typing indicator
    var ti = document.getElementById('typing-indicator');
    ti.classList.toggle('d-none', !otherTyping);

    var chatBox = document.getElementById('chat-box');
    chatBox.scrollTop = chatBox.scrollHeight;
}

function loadMessages() {
    fetch(<?= json_encode(APP_URL) ?> + '/api/support?action=get&order_id=' + ORDER_ID)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var changed = data.messages.length !== lastCount;
                lastCount = data.messages.length;
                if (changed || data.other_typing !== undefined) {
                    renderMessages(data.messages, data.other_typing);
                }
            }
        })
        .catch(function() {});
}

// Typing indicator: fire every keydown, throttled to once per 2s
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
    var inp  = document.getElementById('msg-input');
    var msg  = inp.value.trim();
    if (!msg) return;

    var fd = new FormData();
    fd.append('action',     'send');
    fd.append('order_id',   ORDER_ID);
    fd.append('message',    msg);
    fd.append('csrf_token', CSRF);

    fetch(<?= json_encode(APP_URL) ?> + '/api/support', { method:'POST', body:fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                inp.value = '';
                lastCount = 0; // Force re-render
                loadMessages();
            }
        });
});

loadMessages();
setInterval(loadMessages, 3000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
