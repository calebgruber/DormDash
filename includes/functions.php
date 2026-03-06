<?php
require_once __DIR__ . '/db.php';

function formatMoney(float $amount): string {
    return '$' . number_format($amount, 2);
}

function calculateFees(float $foodTotal, array $config): array {
    if ($config['delivery_fee_type'] === 'percent') {
        $deliveryFee = $foodTotal * ((float)$config['delivery_fee_value'] / 100);
    } else {
        $deliveryFee = (float)$config['delivery_fee_value'];
    }

    if ($config['service_fee_type'] === 'percent') {
        $serviceFee = $foodTotal * ((float)$config['service_fee_value'] / 100);
    } else {
        $serviceFee = (float)$config['service_fee_value'];
    }

    return [
        'delivery_fee' => round($deliveryFee, 2),
        'service_fee'  => round($serviceFee, 2),
    ];
}

function sanitize(string $input): string {
    return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function uploadFile(array $fileArray, string $destDir): ?string {
    if (!isset($fileArray['tmp_name']) || $fileArray['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($fileArray['tmp_name']);

    if (strpos($mimeType, 'image/') !== 0) {
        return null;
    }

    $ext = pathinfo($fileArray['name'], PATHINFO_EXTENSION);
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower($ext);
    if (!in_array($ext, $allowedExts, true)) {
        return null;
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = rtrim($destDir, '/') . '/' . $filename;

    if (!move_uploaded_file($fileArray['tmp_name'], $destPath)) {
        return null;
    }

    return $filename;
}

function getPaymentConfig(): array {
    $db = getDB();
    $stmt = $db->query('SELECT * FROM payment_config LIMIT 1');
    $config = $stmt->fetch();
    if (!$config) {
        return [
            'id'                => 1,
            'delivery_fee_type' => 'flat',
            'delivery_fee_value'=> 2.00,
            'service_fee_type'  => 'percent',
            'service_fee_value' => 5.00,
            'tip_suggestions'   => '[0.10, 0.15, 0.20, 0.25]',
        ];
    }
    return $config;
}

function formatOrderStatus(string $status): string {
    $map = [
        'open'              => 'Waiting for Courier',
        'accepted'          => 'Courier Accepted',
        'card_collected'    => 'Card Collected',
        'food_collected'    => 'Food Collected',
        'delivered'         => 'Delivered',
        'cancelled'         => 'Cancelled',
    ];
    return $map[$status] ?? ucfirst($status);
}

function getOrderStatusBadgeClass(string $status): string {
    $map = [
        'open'           => 'bg-warning text-dark',
        'accepted'       => 'bg-info text-dark',
        'card_collected' => 'bg-purple text-white',
        'food_collected' => 'bg-purple text-white',
        'delivered'      => 'bg-success',
        'cancelled'      => 'bg-danger',
    ];
    return $map[$status] ?? 'bg-secondary';
}

function getCartTotal(array $cart): float {
    $total = 0.0;
    foreach ($cart as $item) {
        $total += (float)$item['unit_price'] * (int)$item['quantity'];
    }
    return $total;
}

function getCartItemCount(array $cart): int {
    $count = 0;
    foreach ($cart as $item) {
        $count += (int)$item['quantity'];
    }
    return $count;
}

function getAppTheme(): string {
    startAppSession();
    if (isset($_SESSION['theme']) && in_array($_SESSION['theme'], ['light', 'dark'], true)) {
        return $_SESSION['theme'];
    }
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT `value` FROM app_settings WHERE `key` = 'theme' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && in_array($row['value'], ['light', 'dark'], true)) {
            return $row['value'];
        }
    } catch (Throwable $e) {
        // Table may not exist yet
    }
    return 'light';
}

function setGlobalTheme(string $theme): bool {
    if (!in_array($theme, ['light', 'dark'], true)) {
        return false;
    }
    try {
        $db = getDB();
        $db->prepare(
            "INSERT INTO app_settings (`key`, `value`) VALUES ('theme', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
        )->execute([$theme]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// ── New helpers ────────────────────────────────────────────────────────────

/**
 * Read a single app_settings value.
 */
function getAppSetting(string $key, string $default = ''): string {
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $row  = $stmt->fetch();
        return ($row && $row['value'] !== null) ? (string)$row['value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * Write a single app_settings value.
 */
function setAppSetting(string $key, string $value): bool {
    try {
        $db = getDB();
        $db->prepare(
            "INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
        )->execute([$key, $value]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Write an error (or other level) to the app_error_logs table.
 * Silently swallows all exceptions so logging never crashes the app.
 */
function logAppError(string $message, string $level = 'error', array $context = []): void {
    try {
        $db     = getDB();
        $url    = isset($_SERVER['REQUEST_URI']) ? substr($_SERVER['REQUEST_URI'], 0, 500) : null;
        $userId = null;
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            $userId = (int)$_SESSION['user_id'];
        }
        $db->prepare(
            'INSERT INTO app_error_logs (level, message, context, url, user_id)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $level,
            $message,
            !empty($context) ? json_encode($context) : null,
            $url,
            $userId,
        ]);
    } catch (Throwable $e) {
        error_log('DormDash logAppError failed: ' . $e->getMessage());
    }
}

/**
 * Return open/closed status for a restaurant based on restaurant_hours table.
 * If no hours rows exist for today the restaurant is considered always-open.
 */
function getRestaurantStatus(int $restaurantId): array {
    $open = ['status' => 'open', 'label' => 'Open', 'orders_accepted' => true, 'class' => 'success'];
    try {
        $db  = getDB();
        $dow = (int)date('w'); // 0 = Sunday
        $stmt = $db->prepare(
            'SELECT * FROM restaurant_hours WHERE restaurant_id = ? AND day_of_week = ? LIMIT 1'
        );
        $stmt->execute([$restaurantId, $dow]);
        $hours = $stmt->fetch();

        if (!$hours) {
            return $open; // No hours configured → always open
        }

        if ($hours['is_closed'] || !$hours['open_time'] || !$hours['close_time']) {
            return ['status' => 'closed', 'label' => 'Closed Today', 'orders_accepted' => false, 'class' => 'danger'];
        }

        $now      = time();
        $today    = date('Y-m-d');
        $openTs   = strtotime($today . ' ' . $hours['open_time']);
        $closeTs  = strtotime($today . ' ' . $hours['close_time']);

        if ($now < $openTs) {
            $openFmt = date('g:ia', $openTs);
            return ['status' => 'not_open', 'label' => "Opens at {$openFmt}", 'orders_accepted' => false, 'class' => 'secondary'];
        }
        if ($now >= $closeTs) {
            return ['status' => 'closed', 'label' => 'Closed', 'orders_accepted' => false, 'class' => 'danger'];
        }

        $warnMin   = max(1, (int)getAppSetting('closing_warning_minutes', '45'));
        $cutoffMin = max(1, (int)getAppSetting('order_cutoff_minutes',    '15'));
        $cutoffTs  = $closeTs - ($cutoffMin  * 60);
        $warnTs    = $closeTs - ($warnMin    * 60);

        if ($now >= $cutoffTs) {
            return ['status' => 'orders_closed', 'label' => 'Orders Closed', 'orders_accepted' => false, 'class' => 'danger'];
        }
        if ($now >= $warnTs) {
            $minLeft = (int)ceil(($cutoffTs - $now) / 60);
            return ['status' => 'closing_soon', 'label' => "Closing soon ({$minLeft}m)", 'orders_accepted' => true, 'class' => 'warning'];
        }

        $closeFmt = date('g:ia', $closeTs);
        return ['status' => 'open', 'label' => "Open · closes {$closeFmt}", 'orders_accepted' => true, 'class' => 'success'];
    } catch (Throwable $e) {
        return $open;
    }
}

/**
 * Check the cart against defined meal groups for a restaurant.
 * Returns an array of matched meal group rows (may be empty).
 */
function checkMealGroups(array $cart, int $restaurantId): array {
    $matched = [];
    if (empty($cart)) return $matched;
    try {
        $db = getDB();
        $gStmt = $db->prepare(
            'SELECT * FROM meal_groups WHERE restaurant_id = ? AND active = 1'
        );
        $gStmt->execute([$restaurantId]);
        $groups = $gStmt->fetchAll();

        foreach ($groups as $group) {
            $rStmt = $db->prepare(
                'SELECT * FROM meal_group_requirements WHERE meal_group_id = ?'
            );
            $rStmt->execute([$group['id']]);
            $reqs = $rStmt->fetchAll();
            if (empty($reqs)) continue;

            $allMet = true;
            foreach ($reqs as $req) {
                $qty = 0;
                foreach ($cart as $item) {
                    if ($req['menu_item_id'] && (int)$item['item_id'] === (int)$req['menu_item_id']) {
                        $qty += (int)$item['quantity'];
                    } elseif ($req['category_id'] && isset($item['category_id'])
                              && (int)$item['category_id'] === (int)$req['category_id']) {
                        $qty += (int)$item['quantity'];
                    }
                }
                if ($qty < (int)$req['min_qty']) {
                    $allMet = false;
                    break;
                }
            }
            if ($allMet) $matched[] = $group;
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $matched;
}

/**
 * Load all option groups (with their choices) for a menu item.
 */
function getItemOptions(int $menuItemId): array {
    try {
        $db = getDB();
        $gStmt = $db->prepare(
            'SELECT * FROM item_option_groups WHERE menu_item_id = ? ORDER BY sort_order, id'
        );
        $gStmt->execute([$menuItemId]);
        $groups = $gStmt->fetchAll();

        foreach ($groups as &$group) {
            $cStmt = $db->prepare(
                'SELECT * FROM item_option_choices WHERE option_group_id = ? ORDER BY sort_order, id'
            );
            $cStmt->execute([$group['id']]);
            $group['choices'] = $cStmt->fetchAll();
        }
        unset($group);
        return $groups;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Count unread support messages for the admin (messages from customers not yet read).
 */
function countUnreadSupportMessages(): int {
    try {
        $db   = getDB();
        $stmt = $db->query(
            'SELECT COUNT(*) FROM support_messages WHERE is_from_admin = 0 AND read_at IS NULL'
        );
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Count unread support messages for a customer on a specific order.
 */
function countUnreadAdminReplies(int $orderId, int $userId): int {
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM support_messages
             WHERE order_id = ? AND is_from_admin = 1 AND read_at IS NULL'
        );
        $stmt->execute([$orderId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}


