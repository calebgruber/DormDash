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
        'SELECT o.*, r.name AS restaurant_name, u.name AS customer_name_display
         FROM orders o
         JOIN restaurants r ON r.id = o.restaurant_id
         JOIN users u ON u.id = o.customer_id
         WHERE o.id = ?'
    );
    $oStmt->execute([$orderId]);
    $selectedOrder = $oStmt->fetch();
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

<div class="row g-4">
    <!-- Thread list -->
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header fw-semibold">
                <i class="ti ti-list me-1"></i>Support Threads
            </div>
            <div class="list-group list-group-flush">
                <?php if (empty($threads)): ?>
                    <div class="list-group-item text-muted small">No support messages yet.</div>
                <?php else: ?>
                    <?php foreach ($threads as $t): ?>
                        <a href="<?= APP_URL ?>/admin/support?order_id=<?= (int)$t['id'] ?>"
                           class="list-group-item list-group-item-action <?= $orderId === (int)$t['id'] ? 'active' : '' ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-semibold">Order #<?= (int)$t['id'] ?></div>
                                    <small><?= htmlspecialchars($t['customer_name_display'], ENT_QUOTES | ENT_HTML5) ?></small>
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

    <!-- Chat panel -->
    <div class="col-md-8">
        <?php if (!$selectedOrder): ?>
            <div class="card shadow-sm border-0 h-100 d-flex align-items-center justify-content-center">
                <div class="text-center text-muted py-5">
                    <i class="ti ti-message-circle" style="font-size:3rem;opacity:.3;"></i>
                    <p class="mt-2">Select a thread from the left to view messages.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card shadow-sm border-0">
                <div class="card-header d-flex justify-content-between">
                    <span class="fw-bold">
                        Order #<?= (int)$orderId ?> — <?= htmlspecialchars($selectedOrder['customer_name_display'], ENT_QUOTES | ENT_HTML5) ?>
                    </span>
                    <small class="text-muted"><?= htmlspecialchars($selectedOrder['restaurant_name'], ENT_QUOTES | ENT_HTML5) ?></small>
                </div>
                <div class="card-body p-0">
                    <div id="chat-box" style="height:400px;overflow-y:auto;padding:1rem;background:var(--tblr-bg-surface,#f9f9f9);">
                        <div id="chat-messages"></div>
                        <div id="chat-loading" class="text-center text-muted py-4">
                            <i class="ti ti-loader-2 me-1"></i>Loading…
                        </div>
                    </div>
                    <div class="border-top p-3">
                        <form id="support-form" class="d-flex gap-2">
                            <input type="hidden" id="csrf-token" value="<?= htmlspecialchars($csrf) ?>">
                            <textarea class="form-control form-control-sm" id="msg-input" rows="2"
                                      placeholder="Reply…" style="resize:none;" required></textarea>
                            <button type="submit" class="btn btn-primary align-self-end">
                                <i class="ti ti-send"></i> Reply
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($selectedOrder): ?>
<script>
var ORDER_ID  = <?= json_encode($orderId) ?>;
var IS_ADMIN  = true;
var lastCount = 0;

function renderMessages(msgs) {
    var box = document.getElementById('chat-messages');
    document.getElementById('chat-loading').style.display = 'none';
    box.innerHTML = '';
    if (!msgs.length) {
        box.innerHTML = '<p class="text-muted text-center small">No messages in this thread.</p>';
        return;
    }
    msgs.forEach(function(m) {
        var isMe = (m.is_from_admin == 1);
        var div  = document.createElement('div');
        div.className = 'mb-3 d-flex ' + (isMe ? 'justify-content-end' : 'justify-content-start');
        var ts = new Date(m.created_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
        div.innerHTML = '<div style="max-width:75%">'
            + '<div class="rounded-3 px-3 py-2 ' + (isMe ? 'bg-primary text-white' : 'bg-white border') + '">'
            + '<div class="small fw-semibold mb-1">' + (isMe ? 'Support (You)' : m.sender_name) + '</div>'
            + '<div>' + m.message.replace(/\n/g,'<br>') + '</div>'
            + '</div>'
            + '<div class="text-muted" style="font-size:.7rem;margin-top:2px;text-align:' + (isMe?'right':'left') + '">' + ts + '</div>'
            + '</div>';
        box.appendChild(div);
    });
    var cb = document.getElementById('chat-box');
    cb.scrollTop = cb.scrollHeight;
}

function loadMessages() {
    fetch('/api/support?action=get&order_id=' + ORDER_ID)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success && d.messages.length !== lastCount) {
                lastCount = d.messages.length;
                renderMessages(d.messages);
            }
        });
}

document.getElementById('support-form').addEventListener('submit', function(e) {
    e.preventDefault();
    var inp  = document.getElementById('msg-input');
    var csrf = document.getElementById('csrf-token').value;
    var fd = new FormData();
    fd.append('action','send'); fd.append('order_id',ORDER_ID);
    fd.append('message',inp.value.trim()); fd.append('csrf_token',csrf);
    fetch('/api/support', { method:'POST', body:fd })
        .then(r=>r.json()).then(d=>{ if(d.success){ inp.value=''; loadMessages(); } });
});

loadMessages();
setInterval(loadMessages, 10000);
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
