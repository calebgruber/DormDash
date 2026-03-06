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
    'SELECT o.*, r.name AS restaurant_name
     FROM orders o JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.id = ?'
);
$oStmt->execute([$orderId]);
$order = $oStmt->fetch();

if (!$order || (int)$order['customer_id'] !== (int)$user['id']) {
    header('Location: ' . APP_URL . '/order/history');
    exit;
}

$csrf = generateCsrfToken();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/order/history">My Orders</a></li>
        <li class="breadcrumb-item active">Support — Order #<?= (int)$orderId ?></li>
    </ol>
</nav>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold">
                    <i class="ti ti-message-circle me-2 text-primary"></i>Order Support — #<?= (int)$orderId ?>
                </span>
                <small class="text-muted"><?= htmlspecialchars($order['restaurant_name'], ENT_QUOTES | ENT_HTML5) ?></small>
            </div>

            <div class="card-body p-0">
                <div id="chat-box"
                     style="height:400px;overflow-y:auto;padding:1rem;background:var(--tblr-bg-surface,#f9f9f9);">
                    <div id="chat-messages"></div>
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
var ORDER_ID  = <?= json_encode($orderId) ?>;
var IS_ADMIN  = false;
var lastCount = 0;

function renderMessages(msgs) {
    var box = document.getElementById('chat-messages');
    document.getElementById('chat-loading').style.display = 'none';
    box.innerHTML = '';
    if (!msgs.length) {
        box.innerHTML = '<p class="text-muted text-center small">No messages yet. Describe your issue below.</p>';
        return;
    }
    msgs.forEach(function(m) {
        var isMe = (m.is_from_admin == 1) === IS_ADMIN;
        var div  = document.createElement('div');
        div.className = 'mb-3 d-flex ' + (isMe ? 'justify-content-end' : 'justify-content-start');
        var ts = new Date(m.created_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
        div.innerHTML = '<div style="max-width:75%">'
            + '<div class="rounded-3 px-3 py-2 ' + (isMe ? 'bg-primary text-white' : 'bg-white border') + '">'
            + '<div class="small fw-semibold mb-1">' + (isMe ? 'You' : m.sender_name) + '</div>'
            + '<div>' + m.message.replace(/\n/g,'<br>') + '</div>'
            + '</div>'
            + '<div class="text-muted" style="font-size:.7rem;margin-top:2px;text-align:' + (isMe?'right':'left') + '">' + ts + '</div>'
            + '</div>';
        box.appendChild(div);
    });
    var chatBox = document.getElementById('chat-box');
    chatBox.scrollTop = chatBox.scrollHeight;
}

function loadMessages() {
    fetch('/api/support?action=get&order_id=' + ORDER_ID)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (data.messages.length !== lastCount) {
                    lastCount = data.messages.length;
                    renderMessages(data.messages);
                }
            }
        })
        .catch(function() {});
}

document.getElementById('support-form').addEventListener('submit', function(e) {
    e.preventDefault();
    var inp  = document.getElementById('msg-input');
    var csrf = document.getElementById('csrf-token').value;
    var msg  = inp.value.trim();
    if (!msg) return;

    var fd = new FormData();
    fd.append('action',      'send');
    fd.append('order_id',    ORDER_ID);
    fd.append('message',     msg);
    fd.append('csrf_token',  csrf);

    fetch('/api/support', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                inp.value = '';
                loadMessages();
            }
        });
});

loadMessages();
setInterval(loadMessages, 10000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
