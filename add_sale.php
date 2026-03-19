<?php
require 'connection.php';

// Fetch active barbers, services, and payment methods
$barbers = $pdo->query('SELECT id, name FROM barbers WHERE is_active = 1 ORDER BY name')->fetchAll();
$services = $pdo->query('SELECT id, name, default_price FROM services WHERE is_active = 1 ORDER BY name')->fetchAll();
$paymentMethods = [];
try {
    $paymentMethods = $pdo->query('SELECT id, name FROM payment_methods WHERE is_active = 1 ORDER BY id')->fetchAll();
} catch (Throwable $e) {
    $paymentMethods = [];
}
$defaultPaymentMethod = $paymentMethods[0]['name'] ?? null;

// Promos: active and valid for today (between valid_from and valid_to)
$salesHasPromoColumns = false;
$promos = [];
try {
    $pdo->query('SELECT promo_id FROM sales LIMIT 1');
    $salesHasPromoColumns = true;
    $today = date('Y-m-d');
    $stmt = $pdo->prepare('SELECT id, name, promo_type, value FROM promos WHERE is_active = 1 AND ? BETWEEN valid_from AND valid_to ORDER BY name');
    $stmt->execute([$today]);
    $promos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$message = null;
$messageType = 'info'; // info, success, error (displayed as danger)
$day = $_GET['day'] ?? date('Y-m-d');
$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : date('Y-m-d');
$highlightSaleId = isset($_GET['highlight']) && preg_match('/^\d+$/', (string)$_GET['highlight']) ? (int)$_GET['highlight'] : null;
$salesPage = max(1, (int)($_GET['sales_page'] ?? 1));
$salesPerPageOptions = [20, 50];
$salesPerPage = (int)($_GET['sales_per_page'] ?? 20);
if (!in_array($salesPerPage, $salesPerPageOptions, true)) {
    $salesPerPage = 20;
}

// Helper: restore inventory for a service (reverse of deduct)
$restoreInventoryForService = function ($serviceId) use ($pdo) {
    try {
        $usageStmt = $pdo->prepare('
            SELECT u.inventory_item_id, u.quantity_per_service
            FROM service_inventory_usage u
            JOIN inventory_items i ON i.id = u.inventory_item_id AND i.is_active = 1
            WHERE u.service_id = ?
        ');
        $usageStmt->execute([$serviceId]);
        $usages = $usageStmt->fetchAll();
        $addStmt = $pdo->prepare('UPDATE inventory_items SET stock_qty = stock_qty + ? WHERE id = ?');
        foreach ($usages as $u) {
            $addStmt->execute([(float)$u['quantity_per_service'], (int)$u['inventory_item_id']]);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ----- Delete sale -----
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
        $saleId = (int)$_POST['id'];
        $redirectDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['day'] ?? '') ? $_POST['day'] : date('Y-m-d');
        $row = $pdo->prepare('SELECT id, service_id FROM sales WHERE id = ?');
        $row->execute([$saleId]);
        $sale = $row->fetch();
        if ($sale) {
            try {
                $pdo->beginTransaction();
                $restoreInventoryForService((int)$sale['service_id']);
                $pdo->prepare('DELETE FROM sales WHERE id = ?')->execute([$saleId]);
                $pdo->commit();
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Sale deleted. Inventory restored.'];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['flash'] = ['type' => 'error', 'text' => 'Error deleting sale: ' . $e->getMessage()];
            }
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'Sale not found.'];
        }
        header('Location: add_sale.php?day=' . urlencode($redirectDay));
        exit;
    }

    $barberId = (int)($_POST['barber_id'] ?? 0);
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
    $notes = trim($_POST['notes'] ?? '');
    $promoId = isset($_POST['promo_id']) && $_POST['promo_id'] !== '' ? (int)$_POST['promo_id'] : null;
    $originalPrice = isset($_POST['original_price']) && $_POST['original_price'] !== '' ? (float)$_POST['original_price'] : null;
    $discountAmount = isset($_POST['discount_amount']) && $_POST['discount_amount'] !== '' ? (float)$_POST['discount_amount'] : null;
    $saleId = isset($_POST['sale_id']) && $_POST['sale_id'] !== '' ? (int)$_POST['sale_id'] : null;

    // ----- Update sale -----
    if ($saleId && $barberId && $serviceId && $price >= 0) {
        $existing = $pdo->prepare('SELECT id, service_id FROM sales WHERE id = ?');
        $existing->execute([$saleId]);
        $existingSale = $existing->fetch();
        if (!$existingSale) {
            $message = 'Sale not found.';
            $messageType = 'error';
        } else {
            $oldServiceId = (int)$existingSale['service_id'];
            try {
                $pdo->beginTransaction();
                if ($oldServiceId !== $serviceId) {
                    $restoreInventoryForService($oldServiceId);
                    $usageStmt = $pdo->prepare('
                        SELECT u.inventory_item_id, u.quantity_per_service, i.item_name, i.stock_qty
                        FROM service_inventory_usage u
                        JOIN inventory_items i ON i.id = u.inventory_item_id AND i.is_active = 1
                        WHERE u.service_id = ?
                    ');
                    $usageStmt->execute([$serviceId]);
                    $usages = $usageStmt->fetchAll();
                    $insufficientItem = null;
                    foreach ($usages as $u) {
                        $required = (float)$u['quantity_per_service'];
                        $stock = (float)$u['stock_qty'];
                        if ($stock < $required) {
                            $insufficientItem = $u;
                            break;
                        }
                    }
                    if ($insufficientItem !== null) {
                        $pdo->rollBack();
                        $message = 'Cannot change service: insufficient stock for ' . htmlspecialchars($insufficientItem['item_name']) . '.';
                    } else {
                        $deductStmt = $pdo->prepare('UPDATE inventory_items SET stock_qty = stock_qty - ? WHERE id = ?');
                        foreach ($usages as $u) {
                            $deductStmt->execute([(float)$u['quantity_per_service'], (int)$u['inventory_item_id']]);
                        }
                    }
                }
                if (!isset($insufficientItem) || $insufficientItem === null) {
                    if ($salesHasPromoColumns) {
                        $pdo->prepare('
                            UPDATE sales SET barber_id = ?, service_id = ?, price = ?, payment_method = ?, notes = ?, promo_id = ?, original_price = ?, discount_amount = ?
                            WHERE id = ?
                        ')->execute([$barberId, $serviceId, $price, $paymentMethod, $notes, $promoId ?: null, $originalPrice, $discountAmount, $saleId]);
                    } else {
                        $pdo->prepare('
                            UPDATE sales SET barber_id = ?, service_id = ?, price = ?, payment_method = ?, notes = ?
                            WHERE id = ?
                        ')->execute([$barberId, $serviceId, $price, $paymentMethod, $notes, $saleId]);
                    }
                    $pdo->commit();
                    $redirectDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['day'] ?? '') ? $_POST['day'] : date('Y-m-d');
                    $_SESSION['flash'] = ['type' => 'success', 'text' => 'Sale updated.'];
                    header('Location: add_sale.php?day=' . urlencode($redirectDay));
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = 'Error updating sale: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
    // ----- Create sale -----
    elseif (!$saleId && $barberId && $serviceId && $price >= 0) {
        try {
            $pdo->beginTransaction();

            $usageStmt = $pdo->prepare('
                SELECT u.inventory_item_id, u.quantity_per_service, i.item_name, i.stock_qty
                FROM service_inventory_usage u
                JOIN inventory_items i ON i.id = u.inventory_item_id AND i.is_active = 1
                WHERE u.service_id = ?
            ');
            $usageStmt->execute([$serviceId]);
            $usages = $usageStmt->fetchAll();

            $insufficientItem = null;
            foreach ($usages as $u) {
                $required = (float)$u['quantity_per_service'];
                $stock = (float)$u['stock_qty'];
                if ($stock < $required) {
                    $insufficientItem = $u;
                    break;
                }
            }

            if ($insufficientItem !== null) {
                $pdo->rollBack();
                $message = 'Cannot add sale: insufficient stock for ' . $insufficientItem['item_name'] . ' (need ' . $insufficientItem['quantity_per_service'] . ', have ' . $insufficientItem['stock_qty'] . ').';
                $messageType = 'error';
            } else {
                if ($salesHasPromoColumns && ($promoId || $originalPrice !== null || $discountAmount !== null)) {
                    $stmt = $pdo->prepare('
                        INSERT INTO sales (barber_id, service_id, price, payment_method, notes, promo_id, original_price, discount_amount)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([$barberId, $serviceId, $price, $paymentMethod, $notes, $promoId ?: null, $originalPrice, $discountAmount]);
                } else {
                    $stmt = $pdo->prepare('
                        INSERT INTO sales (barber_id, service_id, price, payment_method, notes)
                        VALUES (?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([$barberId, $serviceId, $price, $paymentMethod, $notes]);
                }

                $deductStmt = $pdo->prepare('UPDATE inventory_items SET stock_qty = stock_qty - ? WHERE id = ?');
                foreach ($usages as $u) {
                    $deductStmt->execute([(float)$u['quantity_per_service'], (int)$u['inventory_item_id']]);
                }

                $pdo->commit();
                $newSaleId = (int)$pdo->lastInsertId();
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Sale added successfully.'];
                $redirectDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['day'] ?? '') ? $_POST['day'] : date('Y-m-d');
                header('Location: add_sale.php?day=' . urlencode($redirectDay) . '&highlight=' . $newSaleId);
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (strpos($e->getMessage(), 'service_inventory_usage') !== false || strpos($e->getMessage(), "doesn't exist") !== false) {
                if ($salesHasPromoColumns && ($promoId || $originalPrice !== null || $discountAmount !== null)) {
                    $stmt = $pdo->prepare('INSERT INTO sales (barber_id, service_id, price, payment_method, notes, promo_id, original_price, discount_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$barberId, $serviceId, $price, $paymentMethod, $notes, $promoId ?: null, $originalPrice, $discountAmount]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO sales (barber_id, service_id, price, payment_method, notes) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$barberId, $serviceId, $price, $paymentMethod, $notes]);
                }
                $newSaleId = (int)$pdo->lastInsertId();
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Sale added successfully. (Run files/sql/service_inventory_usage.sql to enable inventory deduction.)'];
                $redirectDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['day'] ?? '') ? $_POST['day'] : date('Y-m-d');
                header('Location: add_sale.php?day=' . urlencode($redirectDay) . '&highlight=' . $newSaleId);
                exit;
            } else {
                $message = 'Error adding sale: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif (!$saleId) {
        $message = 'Please fill all required fields.';
        $messageType = 'info';
    }
}

// Daily per-barber service breakdown (for payroll / report) — after POST so new sale is included
$dayStart = $day . ' 00:00:00';
$dayEnd = $day . ' 23:59:59';

$stmt = $pdo->prepare('
    SELECT
        b.id AS barber_id,
        b.name AS barber_name,
        b.percentage_share,
        sv.id AS service_id,
        sv.name AS service_name,
        COUNT(s.id) AS service_count,
        COALESCE(SUM(s.price), 0) AS service_sales
    FROM sales s
    JOIN barbers b ON s.barber_id = b.id
    JOIN services sv ON s.service_id = sv.id
    WHERE s.sale_datetime BETWEEN ? AND ?
    GROUP BY b.id, b.name, b.percentage_share, sv.id, sv.name
    ORDER BY b.name, sv.name
');
$stmt->execute([$dayStart, $dayEnd]);
$breakdownRows = $stmt->fetchAll();

$breakdownByBarber = [];
foreach ($breakdownRows as $r) {
    $bid = (int)$r['barber_id'];
    if (!isset($breakdownByBarber[$bid])) {
        $breakdownByBarber[$bid] = [
            'barber_id' => $bid,
            'barber_name' => (string)$r['barber_name'],
            'percentage_share' => (float)$r['percentage_share'],
            'services' => [],
            'total_count' => 0,
            'total_sales' => 0.0,
        ];
    }
    $count = (int)$r['service_count'];
    $sales = (float)$r['service_sales'];
    $breakdownByBarber[$bid]['services'][] = [
        'service_name' => (string)$r['service_name'],
        'count' => $count,
        'sales' => $sales,
    ];
    $breakdownByBarber[$bid]['total_count'] += $count;
    $breakdownByBarber[$bid]['total_sales'] += $sales;
}

// Last sale (for "Repeat last" and quick defaults)
$lastSale = null;
$lastSaleRow = $pdo->query('SELECT barber_id, service_id, price, payment_method FROM sales ORDER BY sale_datetime DESC LIMIT 1')->fetch();
if ($lastSaleRow) {
    $lastSale = [
        'barber_id' => (int)$lastSaleRow['barber_id'],
        'service_id' => (int)$lastSaleRow['service_id'],
        'price' => (float)$lastSaleRow['price'],
        'payment_method' => (string)($lastSaleRow['payment_method'] ?? ''),
    ];
}

// Individual sales for the selected day (for edit/delete list, paginated)
$salesCountStmt = $pdo->prepare('
    SELECT COUNT(*) AS total_rows
    FROM sales s
    WHERE s.sale_datetime BETWEEN ? AND ?
');
$salesCountStmt->execute([$dayStart, $dayEnd]);
$salesTotalRows = (int)($salesCountStmt->fetch()['total_rows'] ?? 0);
$salesTotalPages = max(1, (int)ceil($salesTotalRows / $salesPerPage));
if ($salesPage > $salesTotalPages) {
    $salesPage = $salesTotalPages;
}
$salesOffset = ($salesPage - 1) * $salesPerPage;
$salesForDayStmt = $pdo->prepare('
    SELECT s.id, s.sale_datetime, s.barber_id, b.name AS barber_name, s.service_id, sv.name AS service_name, s.price, s.payment_method, s.notes
    FROM sales s
    JOIN barbers b ON s.barber_id = b.id
    JOIN services sv ON s.service_id = sv.id
    WHERE s.sale_datetime BETWEEN ? AND ?
    ORDER BY s.sale_datetime DESC
    LIMIT ? OFFSET ?
');
$salesForDayStmt->bindValue(1, $dayStart);
$salesForDayStmt->bindValue(2, $dayEnd);
$salesForDayStmt->bindValue(3, $salesPerPage, PDO::PARAM_INT);
$salesForDayStmt->bindValue(4, $salesOffset, PDO::PARAM_INT);
$salesForDayStmt->execute();
$salesForDay = $salesForDayStmt->fetchAll();

// Edit mode: load sale to pre-fill form
$editSale = null;
if (isset($_GET['edit']) && $_GET['edit'] !== '') {
    $editId = (int)$_GET['edit'];
    $editCols = 'id, barber_id, service_id, price, payment_method, notes';
    if ($salesHasPromoColumns) {
        $editCols .= ', promo_id, original_price, discount_amount';
    }
    $editStmt = $pdo->prepare('SELECT ' . $editCols . ' FROM sales WHERE id = ?');
    $editStmt->execute([$editId]);
    $editSale = $editStmt->fetch();
}
?>
<?php include 'partials/header.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="bb-page-title">Add Sale</h1>
        <p class="bb-page-subtitle">Record a haircut or service. Choose barber and service; price auto-fills but can be edited.</p>
    </div>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="offcanvas" data-bs-target="#bbBreakdownDrawer" aria-controls="bbBreakdownDrawer">
        <i class="bi bi-calendar-day"></i> Daily breakdown
    </button>
</div>

<?php if ($message): ?>
    <?php
    $alertClass = $messageType === 'error' ? 'alert-danger' : ($messageType === 'success' ? 'alert-success' : 'alert-info');
    $alertIcon = $messageType === 'error' ? 'bi-exclamation-triangle-fill' : ($messageType === 'success' ? 'bi-check-circle-fill' : 'bi-info-circle-fill');
    ?>
    <div class="alert <?php echo $alertClass; ?> py-2 small d-flex align-items-center gap-2 mb-3" role="alert">
        <i class="bi <?php echo $alertIcon; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="bb-section-card card">
    <div class="card-body">
        <h5 class="bb-section-title mb-3"><i class="bi bi-plus-circle"></i> New sale</h5>

        <?php if ($lastSale && count($services) > 0): ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <span class="text-muted small me-1">Quick add:</span>
            <button type="button" id="bbRepeatLastBtn" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-arrow-repeat"></i> Repeat last sale
            </button>
            <?php
            $quickServices = array_slice($services, 0, 6);
            foreach ($quickServices as $sv):
                $price = (float)($sv['default_price'] ?? 0);
            ?>
            <button type="button" class="btn btn-sm btn-outline-secondary bb-quick-service" data-service-id="<?php echo (int)$sv['id']; ?>" data-price="<?php echo htmlspecialchars($price); ?>">
                <?php echo htmlspecialchars($sv['name']); ?> (₱<?php echo number_format($price, 0); ?>)
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="post" id="bbAddSaleForm" class="row g-3">
            <?php if ($editSale): ?>
                <input type="hidden" name="sale_id" value="<?php echo (int)$editSale['id']; ?>">
                <input type="hidden" name="day" value="<?php echo htmlspecialchars($day); ?>">
            <?php endif; ?>
            <div class="col-12 col-md-4">
                <label class="form-label"><i class="bi bi-person-badge text-muted me-1"></i> Barber</label>
                <select name="barber_id" id="bbBarberSelect" class="form-select form-select-sm" required autofocus aria-describedby="bbBarberError">
                    <option value="">Select barber</option>
                    <?php foreach ($barbers as $barber): ?>
                        <option value="<?php echo $barber['id']; ?>"<?php echo ($editSale && (int)$editSale['barber_id'] === (int)$barber['id']) ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($barber['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="invalid-feedback" id="bbBarberError">Please select a barber.</div>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label"><i class="bi bi-scissors text-muted me-1"></i> Service</label>
                <select name="service_id" id="bbServiceSelect" class="form-select form-select-sm" required aria-describedby="bbServiceError">
                    <option value="">Select service</option>
                    <?php foreach ($services as $service): ?>
                        <option
                            value="<?php echo $service['id']; ?>"
                            data-price="<?php echo htmlspecialchars($service['default_price']); ?>"
                            <?php echo ($editSale && (int)$editSale['service_id'] === (int)$service['id']) ? ' selected' : ''; ?>
                        >
                            <?php echo htmlspecialchars($service['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="invalid-feedback" id="bbServiceError">Please select a service.</div>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label"><i class="bi bi-currency-exchange text-muted me-1"></i> Price (amount to charge)</label>
                <input
                    type="number"
                    name="price"
                    id="bbPriceInput"
                    class="form-control form-control-sm"
                    step="0.01"
                    min="0"
                    required
                    value="<?php echo $editSale ? htmlspecialchars((string)($editSale['price'] ?? '')) : ''; ?>"
                    aria-describedby="bbPriceHint bbPriceError"
                >
                <input type="hidden" name="original_price" id="bbOriginalPrice" value="<?php echo $editSale && isset($editSale['original_price']) ? htmlspecialchars((string)$editSale['original_price']) : ''; ?>">
                <input type="hidden" name="discount_amount" id="bbDiscountAmount" value="<?php echo $editSale && isset($editSale['discount_amount']) ? htmlspecialchars((string)$editSale['discount_amount']) : ''; ?>">
                <div class="form-text small" id="bbPriceHint">Auto-fills from service. With a promo selected, updates to discounted amount. Use 0 or more; negative values are invalid.</div>
                <div class="invalid-feedback" id="bbPriceError">Enter a valid price (0 or more).</div>
            </div>
            <?php if ($salesHasPromoColumns && !empty($promos)): ?>
            <div class="col-12 col-md-4">
                <label class="form-label"><i class="bi bi-tag text-muted me-1"></i> Promo</label>
                <select name="promo_id" id="bbPromoSelect" class="form-select form-select-sm">
                    <option value="">— No promo —</option>
                    <?php foreach ($promos as $pr): ?>
                        <option value="<?php echo (int)$pr['id']; ?>"
                            data-type="<?php echo htmlspecialchars($pr['promo_type']); ?>"
                            data-value="<?php echo htmlspecialchars($pr['value']); ?>"
                            <?php echo ($editSale && isset($editSale['promo_id']) && (int)$editSale['promo_id'] === (int)$pr['id']) ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($pr['name']); ?>
                            (<?php
                            if ($pr['promo_type'] === 'free') echo 'Free';
                            elseif ($pr['promo_type'] === 'percent_off') echo (float)$pr['value'] . '% off';
                            else echo '₱' . number_format((float)$pr['value'], 0) . ' off';
                            ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-12 col-md-4">
                <label class="form-label"><i class="bi bi-wallet2 text-muted me-1"></i> Payment method</label>
                <select name="payment_method" class="form-select form-select-sm">
                    <option value="">— Select or leave empty —</option>
                    <?php foreach ($paymentMethods as $pm): ?>
                        <option value="<?php echo htmlspecialchars($pm['name']); ?>"<?php echo (($editSale && isset($editSale['payment_method']) && $pm['name'] === $editSale['payment_method']) || (!$editSale && $defaultPaymentMethod !== null && $pm['name'] === $defaultPaymentMethod)) ? ' selected' : ''; ?>><?php echo htmlspecialchars($pm['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($paymentMethods)): ?>
                    <div class="form-text small"><a href="payment_methods.php">Add payment methods</a> to show them here.</div>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-8">
                <label class="form-label"><i class="bi bi-chat-left-text text-muted me-1"></i> Notes</label>
                <input
                    type="text"
                    name="notes"
                    class="form-control form-control-sm"
                    placeholder="Optional"
                    value="<?php echo $editSale ? htmlspecialchars((string)($editSale['notes'] ?? '')) : ''; ?>"
                >
            </div>
            <div class="col-12 d-flex flex-wrap gap-2 align-items-center pt-1">
                <button type="submit" id="bbSubmitSaleBtn" class="btn btn-bb-primary"><i class="bi bi-check-lg"></i> <span class="bb-submit-text"><?php echo $editSale ? 'Save changes' : 'Save sale'; ?></span></button>
                <?php if ($editSale): ?>
                    <a href="add_sale.php?day=<?php echo urlencode($day); ?>" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i> Cancel edit</a>
                <?php endif; ?>
                <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Dashboard</a>
                <span class="text-muted small ms-1"><kbd>Enter</kbd> = submit</span>
            </div>
        </form>
    </div>
</div>

<!-- Sales for selected day: edit / delete -->
<div class="bb-section-card card mt-4">
    <div class="card-body">
        <h5 class="bb-section-title mb-3"><i class="bi bi-list-check"></i> Sales for <?php echo htmlspecialchars($day); ?></h5>
        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-sm-7">
                <label class="form-label small mb-1">Date</label>
                <input type="date" name="day" value="<?php echo htmlspecialchars($day); ?>" class="form-control form-control-sm">
            </div>
            <div class="col-sm-3">
                <label class="form-label small mb-1">Rows</label>
                <select name="sales_per_page" class="form-select form-select-sm">
                    <option value="20"<?php echo $salesPerPage === 20 ? ' selected' : ''; ?>>20</option>
                    <option value="50"<?php echo $salesPerPage === 50 ? ' selected' : ''; ?>>50</option>
                </select>
            </div>
            <div class="col-sm-2">
                <button class="btn btn-sm btn-bb-primary w-100" type="submit"><i class="bi bi-eye"></i> View</button>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th>Time</th>
                    <th>Barber</th>
                    <th>Service</th>
                    <th class="text-end">Price</th>
                    <th class="text-end" style="width: 1%;">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($salesForDay)): ?>
                    <tr>
                        <td colspan="5" class="p-0">
                            <div class="bb-empty py-3"><i class="bi bi-inbox"></i><p>No sales for this day.</p></div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($salesForDay as $s): ?>
                        <?php $rowId = (int)$s['id']; $isHighlight = ($highlightSaleId !== null && $highlightSaleId === $rowId); ?>
                        <tr id="sale-row-<?php echo $rowId; ?>"<?php if ($isHighlight): ?> class="table-success bb-row-highlight"<?php endif; ?>>
                            <td class="text-muted"><?php echo date('H:i', strtotime($s['sale_datetime'])); ?></td>
                            <td><?php echo htmlspecialchars($s['barber_name']); ?></td>
                            <td><?php echo htmlspecialchars($s['service_name']); ?></td>
                            <td class="text-end fw-semibold">₱<?php echo number_format((float)$s['price'], 2); ?></td>
                            <td class="text-end">
                                <a href="add_sale.php?<?php echo htmlspecialchars(http_build_query(['day' => $day, 'sales_page' => $salesPage, 'sales_per_page' => $salesPerPage, 'edit' => (int)$s['id']])); ?>" class="btn btn-sm btn-outline-secondary" title="Edit" aria-label="Edit sale"><i class="bi bi-pencil"></i></a>
                                <button type="button" class="btn btn-sm btn-outline-danger ms-1" title="Delete" aria-label="Delete sale" data-bs-toggle="modal" data-bs-target="#bbDeleteSaleModal" data-sale-id="<?php echo (int)$s['id']; ?>" data-sale-desc="<?php echo htmlspecialchars(date('H:i', strtotime($s['sale_datetime'])) . ' ' . $s['barber_name'] . ' – ' . $s['service_name'] . ' ₱' . number_format((float)$s['price'], 2)); ?>"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($salesTotalRows > $salesPerPage): ?>
            <?php
            $salesPrevParams = ['day' => $day, 'sales_per_page' => $salesPerPage, 'sales_page' => max(1, $salesPage - 1)];
            $salesNextParams = ['day' => $day, 'sales_per_page' => $salesPerPage, 'sales_page' => min($salesTotalPages, $salesPage + 1)];
            ?>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                <span class="text-muted small">
                    Showing <?php echo $salesTotalRows > 0 ? ($salesOffset + 1) : 0; ?>-<?php echo min($salesOffset + count($salesForDay), $salesTotalRows); ?> of <?php echo $salesTotalRows; ?>
                </span>
                <div class="btn-group btn-group-sm" role="group" aria-label="Sales pagination">
                    <a class="btn btn-outline-secondary<?php echo $salesPage <= 1 ? ' disabled' : ''; ?>" href="add_sale.php?<?php echo htmlspecialchars(http_build_query($salesPrevParams)); ?>">Prev</a>
                    <span class="btn btn-outline-secondary disabled">Page <?php echo $salesPage; ?> / <?php echo $salesTotalPages; ?></span>
                    <a class="btn btn-outline-secondary<?php echo $salesPage >= $salesTotalPages ? ' disabled' : ''; ?>" href="add_sale.php?<?php echo htmlspecialchars(http_build_query($salesNextParams)); ?>">Next</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Delete sale confirmation modal -->
<div class="modal fade" id="bbDeleteSaleModal" tabindex="-1" aria-labelledby="bbDeleteSaleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="bbDeleteSaleModalLabel"><i class="bi bi-trash text-danger me-1"></i> Delete sale?</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-2 small">
                <p class="mb-2" id="bbDeleteSaleDesc">This sale will be removed. Inventory will be restored.</p>
                <p class="text-muted mb-0">This cannot be undone.</p>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="post" id="bbDeleteSaleForm" class="d-inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="bbDeleteSaleId">
                    <input type="hidden" name="day" value="<?php echo htmlspecialchars($day); ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i> Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('bbDeleteSaleModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget;
        if (!btn) return;
        var id = btn.getAttribute('data-sale-id');
        var desc = btn.getAttribute('data-sale-desc');
        document.getElementById('bbDeleteSaleId').value = id || '';
        var descEl = document.getElementById('bbDeleteSaleDesc');
        if (descEl) descEl.textContent = desc ? ('Delete: ' + desc + '. Inventory will be restored.') : 'This sale will be removed. Inventory will be restored.';
    });
})();
</script>

<!-- Daily services breakdown drawer -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="bbBreakdownDrawer" aria-labelledby="bbBreakdownDrawerLabel" style="width: 100%; max-width: 700px;">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="bbBreakdownDrawerLabel">
            <i class="bi bi-calendar-day"></i> Daily breakdown
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <p class="bb-section-subtitle mb-3">Counts per service per barber for the selected date (payroll/reporting).</p>

        <form method="get" class="mb-3">
            <input type="hidden" name="open" value="breakdown">
            <div class="d-flex align-items-end gap-2">
                <div class="flex-grow-1">
                    <label class="form-label small mb-1">Date</label>
                    <input type="date" name="day" value="<?php echo htmlspecialchars($day); ?>" class="form-control form-control-sm">
                </div>
                <button class="btn btn-sm btn-bb-primary" type="submit"><i class="bi bi-eye"></i> View</button>
            </div>
        </form>

        <?php if (!$breakdownByBarber): ?>
            <div class="bb-empty"><i class="bi bi-inbox"></i><p>No sales recorded for this day.</p></div>
        <?php else: ?>
            <div class="accordion" id="bbDailyBreakdown">
                <?php foreach ($breakdownByBarber as $bid => $b): ?>
                    <?php
                    $earnings = ($b['total_sales'] ?: 0) * (($b['percentage_share'] ?: 0) / 100);
                    $headingId = 'bbDailyHeading' . $bid;
                    $collapseId = 'bbDailyCollapse' . $bid;
                    ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="<?php echo htmlspecialchars($headingId); ?>">
                            <button
                                class="accordion-button collapsed"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#<?php echo htmlspecialchars($collapseId); ?>"
                                aria-expanded="false"
                                aria-controls="<?php echo htmlspecialchars($collapseId); ?>"
                            >
                                <div class="w-100 d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($b['barber_name']); ?></div>
                                    <div class="small text-muted">
                                        <span class="me-2">Services: <span class="fw-semibold"><?php echo (int)$b['total_count']; ?></span></span>
                                        <span class="me-2">Sales: <span class="fw-semibold">₱<?php echo number_format($b['total_sales'] ?: 0, 2); ?></span></span>
                                        <span>Earnings (<?php echo number_format($b['percentage_share'] ?: 0, 2); ?>%): <span class="fw-semibold">₱<?php echo number_format($earnings, 2); ?></span></span>
                                    </div>
                                </div>
                            </button>
                        </h2>
                        <div
                            id="<?php echo htmlspecialchars($collapseId); ?>"
                            class="accordion-collapse collapse"
                            aria-labelledby="<?php echo htmlspecialchars($headingId); ?>"
                            data-bs-parent="#bbDailyBreakdown"
                        >
                            <div class="accordion-body py-2">
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                        <tr>
                                            <th>Service</th>
                                            <th class="text-end">Count</th>
                                            <th class="text-end">Sales</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($b['services'] as $sv): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($sv['service_name']); ?></td>
                                                <td class="text-end"><?php echo (int)$sv['count']; ?></td>
                                                <td class="text-end">₱<?php echo number_format($sv['sales'] ?: 0, 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var params = new URLSearchParams(window.location.search);
    if (params.get('open') === 'breakdown') {
        var drawer = document.getElementById('bbBreakdownDrawer');
        if (drawer) {
            var offcanvas = new bootstrap.Offcanvas(drawer);
            offcanvas.show();
        }
    }
})();
</script>
<?php if ($highlightSaleId): ?>
<script>
(function () {
    var row = document.getElementById('sale-row-<?php echo $highlightSaleId; ?>');
    if (row) {
        row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        setTimeout(function () {
            row.classList.remove('table-success', 'bb-row-highlight');
        }, 3500);
    }
})();
</script>
<?php endif; ?>
<script>
window.bbLastSale = <?php echo $lastSale ? json_encode($lastSale) : 'null'; ?>;

(function () {
    var form = document.getElementById('bbAddSaleForm');
    var barberSelect = form && form.querySelector('select[name="barber_id"]');
    var serviceSelect = form && form.querySelector('select[name="service_id"]');
    var priceInput = form && form.querySelector('input[name="price"]');
    var paymentSelect = form && form.querySelector('select[name="payment_method"]');
    var submitBtn = document.getElementById('bbSubmitSaleBtn');
    var submitText = submitBtn && submitBtn.querySelector('.bb-submit-text');

    if (!form || !barberSelect || !serviceSelect || !priceInput) return;

    function validateBarber() {
        var ok = barberSelect.value.trim() !== '';
        barberSelect.classList.toggle('is-invalid', !ok);
        return ok;
    }
    function validateService() {
        var ok = serviceSelect.value.trim() !== '';
        serviceSelect.classList.toggle('is-invalid', !ok);
        return ok;
    }
    function validatePrice() {
        var val = parseFloat(priceInput.value);
        var ok = !isNaN(val) && val >= 0;
        priceInput.classList.toggle('is-invalid', !ok);
        return ok;
    }
    function validateAll() {
        var a = validateBarber();
        var b = validateService();
        var c = validatePrice();
        return a && b && c;
    }

    barberSelect.addEventListener('blur', validateBarber);
    serviceSelect.addEventListener('blur', validateService);
    priceInput.addEventListener('blur', validatePrice);
    priceInput.addEventListener('input', function () { if (priceInput.classList.contains('is-invalid')) validatePrice(); });

    form.addEventListener('submit', function (e) {
        if (!validateAll()) {
            e.preventDefault();
            return;
        }
        if (submitBtn && submitText) {
            submitBtn.disabled = true;
            submitText.textContent = 'Saving…';
        }
    });

    // Enter = submit (except when in select, so dropdown can be used)
    form.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.tagName === 'SELECT') return;
        if (e.key === 'Enter') {
            e.preventDefault();
            form.submit();
        }
    });

    // Auto-focus barber on load (backup; autofocus attribute also set)
    if (barberSelect && !form.querySelector(':focus')) {
        barberSelect.focus();
    }

    // Auto-fill price when service changes; store as original for promo calculation
    serviceSelect.addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        var price = opt.getAttribute('data-price');
        if (price) priceInput.value = price;
        var origInput = form.querySelector('input[name="original_price"]');
        if (origInput) origInput.value = price || '';
        applyPromoToPrice(form);
    });

    // When promo changes, recalculate final price from original
    var promoSelect = form.querySelector('select[name="promo_id"]');
    if (promoSelect) {
        promoSelect.addEventListener('change', function () { applyPromoToPrice(form); });
    }

    function applyPromoToPrice(f) {
        var priceIn = f.querySelector('input[name="price"]');
        var origIn = f.querySelector('input[name="original_price"]');
        var discIn = f.querySelector('input[name="discount_amount"]');
        var promoSel = f.querySelector('select[name="promo_id"]');
        if (!priceIn || !origIn || !discIn) return;
        var original = parseFloat(priceIn.value) || parseFloat(origIn.value) || 0;
        if (original && !origIn.value) origIn.value = String(original);
        if (!promoSel || promoSel.value === '') {
            discIn.value = '';
            origIn.value = '';
            return;
        }
        var opt = promoSel.options[promoSel.selectedIndex];
        var type = opt.getAttribute('data-type');
        var val = parseFloat(opt.getAttribute('data-value')) || 0;
        var finalPrice = original;
        if (type === 'percent_off') finalPrice = Math.max(0, original * (1 - val / 100));
        else if (type === 'amount_off') finalPrice = Math.max(0, original - val);
        else if (type === 'free') finalPrice = 0;
        priceIn.value = (Math.round(finalPrice * 100) / 100).toFixed(2);
        origIn.value = String(original);
        discIn.value = (Math.round((original - finalPrice) * 100) / 100).toFixed(2);
    }

    // Repeat last sale: fill form and submit
    var repeatBtn = document.getElementById('bbRepeatLastBtn');
    if (repeatBtn && window.bbLastSale) {
        repeatBtn.addEventListener('click', function () {
            var last = window.bbLastSale;
            barberSelect.value = String(last.barber_id);
            serviceSelect.value = String(last.service_id);
            serviceSelect.dispatchEvent(new Event('change'));
            priceInput.value = String(last.price);
            if (paymentSelect) paymentSelect.value = last.payment_method;
            form.submit();
        });
    }

    // Quick service: set service + price + last barber & payment, focus barber
    document.querySelectorAll('.bb-quick-service').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var serviceId = btn.getAttribute('data-service-id');
            var price = btn.getAttribute('data-price');
            serviceSelect.value = serviceId;
            serviceSelect.dispatchEvent(new Event('change'));
            priceInput.value = price || '';
            if (window.bbLastSale) {
                barberSelect.value = String(window.bbLastSale.barber_id);
                if (paymentSelect) paymentSelect.value = window.bbLastSale.payment_method;
            }
            barberSelect.focus();
        });
    });
})();
</script>

<?php include 'partials/footer.php'; ?>

