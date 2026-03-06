<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db      = getDB();
$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $deliveryFeeType  = $_POST['delivery_fee_type'] ?? 'flat';
        $deliveryFeeValue = (float)($_POST['delivery_fee_value'] ?? 2.00);
        $serviceFeeType   = $_POST['service_fee_type'] ?? 'percent';
        $serviceFeeValue  = (float)($_POST['service_fee_value'] ?? 5.00);
        $tipSuggestionsRaw = trim($_POST['tip_suggestions'] ?? '');

        $validFeeTypes = ['flat', 'percent'];
        if (!in_array($deliveryFeeType, $validFeeTypes, true) || !in_array($serviceFeeType, $validFeeTypes, true)) {
            $errors[] = 'Invalid fee type.';
        } elseif ($deliveryFeeValue < 0 || $serviceFeeValue < 0) {
            $errors[] = 'Fee values must be non-negative.';
        } else {
            // Parse tip suggestions
            $tipParts = array_filter(array_map('trim', explode(',', $tipSuggestionsRaw)));
            $tipValues = [];
            foreach ($tipParts as $tp) {
                if (is_numeric($tp) && (float)$tp >= 0 && (float)$tp <= 1) {
                    $tipValues[] = (float)$tp;
                }
            }
            if (empty($tipValues)) $tipValues = [0.10, 0.15, 0.20, 0.25];
            $tipJson = json_encode($tipValues);

            $existing = $db->query('SELECT id FROM payment_config LIMIT 1')->fetch();
            if ($existing) {
                $db->prepare(
                    'UPDATE payment_config SET delivery_fee_type=?, delivery_fee_value=?, service_fee_type=?, service_fee_value=?, tip_suggestions=? WHERE id=?'
                )->execute([$deliveryFeeType, $deliveryFeeValue, $serviceFeeType, $serviceFeeValue, $tipJson, $existing['id']]);
            } else {
                $db->prepare(
                    'INSERT INTO payment_config (delivery_fee_type, delivery_fee_value, service_fee_type, service_fee_value, tip_suggestions) VALUES (?,?,?,?,?)'
                )->execute([$deliveryFeeType, $deliveryFeeValue, $serviceFeeType, $serviceFeeValue, $tipJson]);
            }
            $success = 'Configuration saved.';
        }
    }
}

$config = getPaymentConfig();
$tipSuggestions = json_decode($config['tip_suggestions'], true) ?? [0.10, 0.15, 0.20, 0.25];
$tipCsv = implode(', ', $tipSuggestions);
$csrf = generateCsrfToken();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>⚙️ Payment Configuration</h2>
    <a href="<?= APP_URL ?>/admin/dashboard.php" class="btn btn-outline-secondary btn-sm">← Dashboard</a>
</div>

<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e, ENT_QUOTES | ENT_HTML5) ?></div><?php endforeach; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES | ENT_HTML5) ?></div><?php endif; ?>

<div class="row justify-content-center">
    <div class="col-md-7 col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-bold">Fee Settings</div>
            <div class="card-body p-4">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                    <h6 class="fw-bold mb-3">Delivery Fee</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Type</label>
                            <select class="form-select" name="delivery_fee_type">
                                <option value="flat" <?= $config['delivery_fee_type'] === 'flat' ? 'selected' : '' ?>>Flat Amount ($)</option>
                                <option value="percent" <?= $config['delivery_fee_type'] === 'percent' ? 'selected' : '' ?>>Percentage (%)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Value</label>
                            <input type="number" class="form-control" name="delivery_fee_value"
                                   min="0" step="0.01"
                                   value="<?= htmlspecialchars($config['delivery_fee_value'], ENT_QUOTES | ENT_HTML5) ?>">
                            <div class="form-text">Use decimal value. For 5%, enter 5.00.</div>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3">Service Fee</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Type</label>
                            <select class="form-select" name="service_fee_type">
                                <option value="flat" <?= $config['service_fee_type'] === 'flat' ? 'selected' : '' ?>>Flat Amount ($)</option>
                                <option value="percent" <?= $config['service_fee_type'] === 'percent' ? 'selected' : '' ?>>Percentage (%)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Value</label>
                            <input type="number" class="form-control" name="service_fee_value"
                                   min="0" step="0.01"
                                   value="<?= htmlspecialchars($config['service_fee_value'], ENT_QUOTES | ENT_HTML5) ?>">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Tip Suggestions</label>
                        <input type="text" class="form-control" name="tip_suggestions"
                               value="<?= htmlspecialchars($tipCsv, ENT_QUOTES | ENT_HTML5) ?>">
                        <div class="form-text">Comma-separated decimals (0–1). E.g. <code>0.10, 0.15, 0.20, 0.25</code></div>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Save Configuration</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
