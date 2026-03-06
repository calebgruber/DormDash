<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();
$stmt = $db->query('SELECT * FROM restaurants WHERE active = 1 ORDER BY name ASC');
$restaurants = $stmt->fetchAll();

$typeBadge = [
    'dining_hall' => ['label' => 'Dining Hall', 'class' => 'bg-success'],
    'einstein'    => ['label' => 'Einstein Bros', 'class' => 'bg-warning text-dark'],
    'boba'        => ['label' => 'Boba Tea', 'class' => ''],
    'other'       => ['label' => 'Other', 'class' => 'bg-secondary'],
];
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<h2 class="mb-4">🍔 Order Food</h2>

<?php if (empty($restaurants)): ?>
    <div class="alert alert-info">No restaurants available at the moment. Check back soon!</div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($restaurants as $r): ?>
            <?php
            $type  = $r['type'];
            $badge = $typeBadge[$type] ?? ['label' => ucfirst($type), 'class' => 'bg-secondary'];
            $style = ($type === 'boba') ? ' style="background:#6f42c1"' : '';
            ?>
            <div class="col-sm-6 col-lg-4">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="card-title mb-0"><?= htmlspecialchars($r['name'], ENT_QUOTES | ENT_HTML5) ?></h5>
                            <span class="badge <?= htmlspecialchars($badge['class']) ?>"<?= $style ?>><?= htmlspecialchars($badge['label']) ?></span>
                        </div>
                        <?php if ($r['location']): ?>
                            <p class="card-text text-muted small mb-1">
                                <i>📍 <?= htmlspecialchars($r['location'], ENT_QUOTES | ENT_HTML5) ?></i>
                            </p>
                        <?php endif; ?>
                        <?php if ($r['description']): ?>
                            <p class="card-text flex-grow-1"><?= htmlspecialchars($r['description'], ENT_QUOTES | ENT_HTML5) ?></p>
                        <?php endif; ?>
                        <a href="<?= APP_URL ?>/customer/restaurant.php?id=<?= (int)$r['id'] ?>"
                           class="btn btn-primary mt-3">Order Now</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
