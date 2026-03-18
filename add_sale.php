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

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $barberId = (int)($_POST['barber_id'] ?? 0);
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
    $notes = trim($_POST['notes'] ?? '');

    if ($barberId && $serviceId && $price > 0) {
        try {
            $pdo->beginTransaction();

            // Load inventory usage for this service (active items only)
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
                $message = 'Cannot add sale: insufficient stock for ' . htmlspecialchars($insufficientItem['item_name']) . ' (need ' . $insufficientItem['quantity_per_service'] . ', have ' . $insufficientItem['stock_qty'] . ').';
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO sales (barber_id, service_id, price, payment_method, notes)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmt->execute([$barberId, $serviceId, $price, $paymentMethod, $notes]);

                $deductStmt = $pdo->prepare('UPDATE inventory_items SET stock_qty = stock_qty - ? WHERE id = ?');
                foreach ($usages as $u) {
                    $deductStmt->execute([(float)$u['quantity_per_service'], (int)$u['inventory_item_id']]);
                }

                $pdo->commit();
                $message = 'Sale added successfully.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (strpos($e->getMessage(), 'service_inventory_usage') !== false || strpos($e->getMessage(), "doesn't exist") !== false) {
                $stmt = $pdo->prepare('INSERT INTO sales (barber_id, service_id, price, payment_method, notes) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$barberId, $serviceId, $price, $paymentMethod, $notes]);
                $message = 'Sale added successfully. (Run files/sql/service_inventory_usage.sql to enable inventory deduction.)';
            } else {
                $message = 'Error adding sale: ' . htmlspecialchars($e->getMessage());
            }
        }
    } else {
        $message = 'Please fill all required fields.';
    }
}

// Daily per-barber service breakdown (for payroll / report) — after POST so new sale is included
$day = $_GET['day'] ?? date('Y-m-d');
$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : date('Y-m-d');
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
    <div class="alert alert-info py-2 small d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-check-circle-fill"></i>
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
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-person-badge text-muted me-1"></i> Barber</label>
                <select name="barber_id" class="form-select form-select-sm" required autofocus>
                    <option value="">Select barber</option>
                    <?php foreach ($barbers as $barber): ?>
                        <option value="<?php echo $barber['id']; ?>">
                            <?php echo htmlspecialchars($barber['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-scissors text-muted me-1"></i> Service</label>
                <select name="service_id" class="form-select form-select-sm" required>
                    <option value="">Select service</option>
                    <?php foreach ($services as $service): ?>
                        <option
                            value="<?php echo $service['id']; ?>"
                            data-price="<?php echo htmlspecialchars($service['default_price']); ?>"
                        >
                            <?php echo htmlspecialchars($service['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-currency-exchange text-muted me-1"></i> Price</label>
                <input
                    type="number"
                    name="price"
                    class="form-control form-control-sm"
                    step="0.01"
                    min="0"
                    required
                >
                <div class="form-text small">
                    Auto-fills from service, but editable.
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-wallet2 text-muted me-1"></i> Payment method</label>
                <select name="payment_method" class="form-select form-select-sm">
                    <option value="">— Select or leave empty —</option>
                    <?php foreach ($paymentMethods as $pm): ?>
                        <option value="<?php echo htmlspecialchars($pm['name']); ?>"<?php echo ($defaultPaymentMethod !== null && $pm['name'] === $defaultPaymentMethod) ? ' selected' : ''; ?>><?php echo htmlspecialchars($pm['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($paymentMethods)): ?>
                    <div class="form-text small"><a href="payment_methods.php">Add payment methods</a> to show them here.</div>
                <?php endif; ?>
            </div>
            <div class="col-md-8">
                <label class="form-label"><i class="bi bi-chat-left-text text-muted me-1"></i> Notes</label>
                <input
                    type="text"
                    name="notes"
                    class="form-control form-control-sm"
                    placeholder="Optional"
                >
            </div>
            <div class="col-12 d-flex flex-wrap gap-2 align-items-center pt-1">
                <button type="submit" id="bbSubmitSaleBtn" class="btn btn-bb-primary"><i class="bi bi-check-lg"></i> Save sale</button>
                <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Dashboard</a>
                <span class="text-muted small ms-1"><kbd>Enter</kbd> = submit</span>
            </div>
        </form>
    </div>
</div>

<!-- Daily services breakdown drawer -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="bbBreakdownDrawer" aria-labelledby="bbBreakdownDrawerLabel" style="width: 100%; max-width: 420px;">
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
<script>
window.bbLastSale = <?php echo $lastSale ? json_encode($lastSale) : 'null'; ?>;

(function () {
    var form = document.getElementById('bbAddSaleForm');
    var barberSelect = form && form.querySelector('select[name="barber_id"]');
    var serviceSelect = form && form.querySelector('select[name="service_id"]');
    var priceInput = form && form.querySelector('input[name="price"]');
    var paymentSelect = form && form.querySelector('select[name="payment_method"]');

    if (!form || !barberSelect || !serviceSelect || !priceInput) return;

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

    // Auto-fill price when service changes
    serviceSelect.addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        var price = opt.getAttribute('data-price');
        if (price) priceInput.value = price;
    });

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

