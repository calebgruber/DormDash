<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db   = getDB();
$stmt = $db->query('SELECT * FROM restaurants WHERE active = 1 ORDER BY name ASC');
$restaurants = $stmt->fetchAll();
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold"><i class="ti ti-salad me-2 text-primary"></i>Order Food</h2>
</div>

<?php if (empty($restaurants)): ?>
    <div class="alert alert-info">
        <i class="ti ti-info-circle me-2"></i>No restaurants available at the moment. Check back soon!
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($restaurants as $r): ?>
            <?php
            $status = getRestaurantStatus((int)$r['id']);
            $typeBadge = [
                'dining_hall' => ['label' => 'Dining Hall',   'class' => 'bg-green text-white'],
                'einstein'    => ['label' => 'Einstein Bros', 'class' => 'bg-yellow text-dark'],
                'boba'        => ['label' => 'Boba Tea',      'class' => 'bg-purple text-white'],
                'other'       => ['label' => 'Other',         'class' => 'bg-secondary'],
            ];
            $badge = $typeBadge[$r['type']] ?? ['label' => ucfirst($r['type']), 'class' => 'bg-secondary'];
            ?>
            <div class="col-sm-6 col-lg-4">
                <div class="card h-100 shadow-sm border-0 hover-lift <?= $status['orders_accepted'] ? '' : 'opacity-75' ?>">
                    <?php if ($r['banner_image']): ?>
                        <img src="<?= htmlspecialchars(UPLOAD_URL . $r['banner_image'], ENT_QUOTES | ENT_HTML5) ?>"
                             alt="<?= htmlspecialchars($r['name'], ENT_QUOTES | ENT_HTML5) ?>"
                             class="card-img-top banner-thumb">
                    <?php else: ?>
                        <div class="card-img-top banner-thumb d-flex align-items-center justify-content-center"
                             style="background:linear-gradient(135deg,#6B46C1,#2d1b69);">
                            <i class="ti ti-tools-kitchen-2 text-white" style="font-size:3.5rem;opacity:.6;"></i>
                        </div>
                    <?php endif; ?>

                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="card-title mb-0 fw-bold"><?= htmlspecialchars($r['name'], ENT_QUOTES | ENT_HTML5) ?></h5>
                            <span class="badge <?= $badge['class'] ?> ms-2 flex-shrink-0"><?= $badge['label'] ?></span>
                        </div>

                        <!-- Open/closed status -->
                        <div class="mb-2">
                            <span class="badge bg-<?= $status['class'] ?>">
                                <i class="ti ti-clock me-1"></i><?= htmlspecialchars($status['label'], ENT_QUOTES | ENT_HTML5) ?>
                            </span>
                            <?php if ($r['meal_swipe_eligible']): ?>
                                <span class="badge bg-purple ms-1">
                                    <i class="ti ti-id-badge-2 me-1"></i>Meal Swipe
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($r['location']): ?>
                            <p class="card-text text-muted small mb-1">
                                <i class="ti ti-map-pin me-1"></i><?= htmlspecialchars($r['location'], ENT_QUOTES | ENT_HTML5) ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($r['description']): ?>
                            <p class="card-text text-muted small flex-grow-1"><?= htmlspecialchars($r['description'], ENT_QUOTES | ENT_HTML5) ?></p>
                        <?php endif; ?>

                        <?php if ($status['orders_accepted']): ?>
                            <a href="<?= APP_URL ?>/order/restaurant?id=<?= (int)$r['id'] ?>"
                               class="btn btn-primary mt-auto">
                                <i class="ti ti-shopping-bag me-1"></i>Order Now
                            </a>
                        <?php else: ?>
                            <button class="btn btn-secondary mt-auto" disabled>
                                <i class="ti ti-lock me-1"></i><?= htmlspecialchars($status['label'], ENT_QUOTES | ENT_HTML5) ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
