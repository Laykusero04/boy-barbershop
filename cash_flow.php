<?php
require 'connection.php';

// Ensure drawer_counts table exists (lazy init)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS drawer_counts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            period_type VARCHAR(20) NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            actual_cash DECIMAL(12,2) NULL,
            notes VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_period (period_type, period_start)
        )
    ");
} catch (Throwable $e) {
    // Table may already exist or DB user lacks CREATE
}

// Settings helper
function cf_setting(PDO $pdo, string $key, ?string $default = null): ?string {
    try {
        $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

// Which payment method counts as physical drawer cash (default: Cash)
$cashDrawerMethod = trim(cf_setting($pdo, 'cash_drawer_payment_method', 'Cash') ?? 'Cash');
if ($cashDrawerMethod === '') {
    $cashDrawerMethod = 'Cash';
}

// Save setting: which payment method is drawer cash
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_drawer_method') {
    $method = trim((string)($_POST['cash_drawer_payment_method'] ?? ''));
    if ($method !== '') {
        try {
            $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
            $stmt->execute(['cash_drawer_payment_method', $method]);
            $cashDrawerMethod = $method;
            header('Location: cash_flow.php?saved=1');
            exit;
        } catch (Throwable $e) {}
    }
}

// Save actual cash (daily or weekly)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($action = $_POST['action'] ?? '') === 'save_actual_daily' || $action === 'save_actual_weekly')) {
    $actual = isset($_POST['actual_cash']) ? (float)$_POST['actual_cash'] : null;
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($action === 'save_actual_daily') {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['period_start'] ?? '') ? $_POST['period_start'] : date('Y-m-d');
        $start = $day;
        $end = $day;
        $periodType = 'daily';
    } else {
        $periodType = 'weekly';
        $weekStart = $_POST['period_start'] ?? '';
        $weekEnd = $_POST['period_end'] ?? '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekEnd)) {
            $start = $weekStart;
            $end = $weekEnd;
        } else {
            $start = date('Y-m-d', strtotime('monday this week'));
            $end = date('Y-m-d', strtotime('sunday this week'));
        }
    }

    try {
        $stmt = $pdo->prepare('
            INSERT INTO drawer_counts (period_type, period_start, period_end, actual_cash, notes)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE actual_cash = VALUES(actual_cash), notes = VALUES(notes)
        ');
        $stmt->execute([$periodType, $start, $end, $actual ?: null, $notes === '' ? null : $notes]);
        header('Location: cash_flow.php?count_saved=1');
        exit;
    } catch (Throwable $e) {}
}

$message = null;
if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $message = 'Drawer cash method saved.';
}
if (isset($_GET['count_saved']) && $_GET['count_saved'] === '1') {
    $message = 'Actual cash count saved.';
}

// --- Today ---
$today = date('Y-m-d');
$todayStart = $today . ' 00:00:00';
$todayEnd = $today . ' 23:59:59';

// --- This week (Mon–Sun) ---
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));
$weekStartDt = $weekStart . ' 00:00:00';
$weekEndDt = $weekEnd . ' 23:59:59';

// Breakdown by payment method (today)
$breakdownToday = [];
try {
    $stmt = $pdo->prepare("
        SELECT payment_method AS method, COUNT(*) AS cnt, SUM(price) AS total
        FROM sales
        WHERE sale_datetime BETWEEN ? AND ?
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    $stmt->execute([$todayStart, $todayEnd]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $breakdownToday[] = [
            'method' => (string)($row['method'] ?? '') ?: '—',
            'count' => (int)$row['cnt'],
            'total' => (float)$row['total'],
        ];
    }
} catch (Throwable $e) {}

// Breakdown by payment method (this week)
$breakdownWeek = [];
try {
    $stmt = $pdo->prepare("
        SELECT payment_method AS method, COUNT(*) AS cnt, SUM(price) AS total
        FROM sales
        WHERE sale_datetime BETWEEN ? AND ?
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    $stmt->execute([$weekStartDt, $weekEndDt]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $breakdownWeek[] = [
            'method' => (string)($row['method'] ?? '') ?: '—',
            'count' => (int)$row['cnt'],
            'total' => (float)$row['total'],
        ];
    }
} catch (Throwable $e) {}

// Expected cash in drawer = sales where payment method = drawer cash method
$expectedCashToday = 0;
$expectedCashWeek = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(price), 0) AS total
        FROM sales
        WHERE sale_datetime BETWEEN ? AND ? AND payment_method = ?
    ");
    $stmt->execute([$todayStart, $todayEnd, $cashDrawerMethod]);
    $expectedCashToday = (float)$stmt->fetch()['total'];

    $stmt->execute([$weekStartDt, $weekEndDt, $cashDrawerMethod]);
    $expectedCashWeek = (float)$stmt->fetch()['total'];
} catch (Throwable $e) {}

// Actual cash recorded (today and this week)
$actualCashToday = null;
$actualCashWeek = null;
$actualNotesToday = null;
$actualNotesWeek = null;
try {
    $stmt = $pdo->prepare('SELECT actual_cash, notes FROM drawer_counts WHERE period_type = ? AND period_start = ?');
    $stmt->execute(['daily', $today]);
    $row = $stmt->fetch();
    if ($row && $row['actual_cash'] !== null) {
        $actualCashToday = (float)$row['actual_cash'];
        $actualNotesToday = $row['notes'];
    }

    $stmt->execute(['weekly', $weekStart]);
    $row = $stmt->fetch();
    if ($row && $row['actual_cash'] !== null) {
        $actualCashWeek = (float)$row['actual_cash'];
        $actualNotesWeek = $row['notes'];
    }
} catch (Throwable $e) {}

// Payment method options for drawer setting (from payment_methods table)
$paymentMethodOptions = [];
try {
    $paymentMethodOptions = $pdo->query('SELECT name FROM payment_methods WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}
?>
<?php include 'partials/header.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="bb-page-title">Cash flow clarity</h1>
        <p class="bb-page-subtitle">Cash vs GCash vs others, how much cash should be in the drawer, and optional expected vs actual to prevent silent money leaks.</p>
    </div>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-house"></i> Dashboard</a>
</div>

<?php if ($message): ?>
<div class="alert alert-success py-2 small d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<!-- Setting: which payment method = drawer cash -->
<div class="bb-section-card card mb-4">
    <div class="card-body">
        <h5 class="bb-section-title mb-3"><i class="bi bi-gear"></i> Drawer cash method</h5>
        <p class="text-muted small mb-3">Choose which payment method is <strong>physical cash in the drawer</strong>. &quot;Expected cash&quot; is based on this.</p>
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="save_drawer_method">
            <div class="col-auto">
                <label class="form-label small mb-0">Payment method that goes in the drawer</label>
                <select name="cash_drawer_payment_method" class="form-select form-select-sm" style="min-width: 180px;">
                    <?php foreach ($paymentMethodOptions as $name): ?>
                    <option value="<?php echo htmlspecialchars($name); ?>"<?php echo $name === $cashDrawerMethod ? ' selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
                    <?php endforeach; ?>
                    <?php if (empty($paymentMethodOptions)): ?>
                    <option value="Cash"<?php echo $cashDrawerMethod === 'Cash' ? ' selected' : ''; ?>>Cash</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-bb-primary"><i class="bi bi-check-lg"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Cash vs GCash vs others breakdown -->
<div class="bb-section-card card mb-4">
    <div class="card-body">
        <h5 class="bb-section-title mb-3"><i class="bi bi-pie-chart"></i> Cash vs GCash vs others</h5>
        <p class="text-muted small mb-3">Breakdown of sales by payment method for today and this week.</p>

        <div class="row g-4">
            <div class="col-md-6">
                <h6 class="text-muted small text-uppercase fw-semibold mb-2">Today</h6>
                <?php if (empty($breakdownToday)): ?>
                <p class="text-muted small mb-0">No sales today yet.</p>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($breakdownToday as $b): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span><?php echo htmlspecialchars($b['method']); ?></span>
                        <span>₱<?php echo number_format($b['total'], 2); ?> <small class="text-muted">(<?php echo $b['count']; ?> sale<?php echo $b['count'] === 1 ? '' : 's'; ?>)</small></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <h6 class="text-muted small text-uppercase fw-semibold mb-2">This week (<?php echo date('M j', strtotime($weekStart)); ?> – <?php echo date('M j', strtotime($weekEnd)); ?>)</h6>
                <?php if (empty($breakdownWeek)): ?>
                <p class="text-muted small mb-0">No sales this week yet.</p>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($breakdownWeek as $b): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span><?php echo htmlspecialchars($b['method']); ?></span>
                        <span>₱<?php echo number_format($b['total'], 2); ?> <small class="text-muted">(<?php echo $b['count']; ?> sale<?php echo $b['count'] === 1 ? '' : 's'; ?>)</small></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- How much cash should be in drawer + optional actual -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="bb-section-card card h-100">
            <div class="card-body">
                <h5 class="bb-section-title mb-3"><i class="bi bi-cash-stack"></i> Today: cash in drawer</h5>
                <p class="text-muted small mb-3">Expected cash from &quot;<?php echo htmlspecialchars($cashDrawerMethod); ?>&quot; sales today. Optional: record actual count to spot shortages.</p>

                <div class="p-3 rounded bg-primary bg-opacity-10 border border-primary border-opacity-25 mb-3">
                    <div class="small text-muted mb-1">Expected cash (from sales)</div>
                    <div class="h4 mb-0 text-primary">₱<?php echo number_format($expectedCashToday, 2); ?></div>
                    <div class="small text-muted">Sum of today&apos;s &quot;<?php echo htmlspecialchars($cashDrawerMethod); ?>&quot; payments</div>
                </div>

                <?php if ($actualCashToday !== null): ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small">Actual counted</span>
                    <span class="fw-semibold">₱<?php echo number_format($actualCashToday, 2); ?></span>
                </div>
                <?php
                $diffToday = $actualCashToday - $expectedCashToday;
                $isShort = $diffToday < -0.01;
                ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small">Difference</span>
                    <span class="fw-semibold <?php echo $isShort ? 'text-danger' : 'text-success'; ?>">
                        <?php echo $diffToday >= 0 ? '+' : ''; ?>₱<?php echo number_format($diffToday, 2); ?>
                        <?php if ($isShort): ?><small>(shortage – check for leaks)</small><?php endif; ?>
                    </span>
                </div>
                <?php if ($actualNotesToday): ?>
                <p class="small text-muted mb-2">Note: <?php echo htmlspecialchars($actualNotesToday); ?></p>
                <?php endif; ?>
                <?php endif; ?>

                <form method="post" class="mt-3">
                    <input type="hidden" name="action" value="save_actual_daily">
                    <input type="hidden" name="period_start" value="<?php echo htmlspecialchars($today); ?>">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small mb-0">Actual cash counted</label>
                            <input type="number" name="actual_cash" class="form-control form-control-sm" step="0.01" min="0" placeholder="Optional" value="<?php echo $actualCashToday !== null ? (string)$actualCashToday : ''; ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-0">Notes</label>
                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional" value="<?php echo $actualNotesToday !== null ? htmlspecialchars($actualNotesToday) : ''; ?>">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-sm btn-bb-primary"><i class="bi bi-check-lg"></i> Save actual cash</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="bb-section-card card h-100">
            <div class="card-body">
                <h5 class="bb-section-title mb-3"><i class="bi bi-calendar-week"></i> This week: cash in drawer</h5>
                <p class="text-muted small mb-3">Expected cash from &quot;<?php echo htmlspecialchars($cashDrawerMethod); ?>&quot; sales this week (Mon–Sun). Optional: record actual count.</p>

                <div class="p-3 rounded bg-primary bg-opacity-10 border border-primary border-opacity-25 mb-3">
                    <div class="small text-muted mb-1">Expected cash (from sales)</div>
                    <div class="h4 mb-0 text-primary">₱<?php echo number_format($expectedCashWeek, 2); ?></div>
                    <div class="small text-muted"><?php echo date('M j', strtotime($weekStart)); ?> – <?php echo date('M j', strtotime($weekEnd)); ?></div>
                </div>

                <?php if ($actualCashWeek !== null): ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small">Actual counted</span>
                    <span class="fw-semibold">₱<?php echo number_format($actualCashWeek, 2); ?></span>
                </div>
                <?php
                $diffWeek = $actualCashWeek - $expectedCashWeek;
                $isShortWeek = $diffWeek < -0.01;
                ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small">Difference</span>
                    <span class="fw-semibold <?php echo $isShortWeek ? 'text-danger' : 'text-success'; ?>">
                        <?php echo $diffWeek >= 0 ? '+' : ''; ?>₱<?php echo number_format($diffWeek, 2); ?>
                        <?php if ($isShortWeek): ?><small>(shortage)</small><?php endif; ?>
                    </span>
                </div>
                <?php if ($actualNotesWeek): ?>
                <p class="small text-muted mb-2">Note: <?php echo htmlspecialchars($actualNotesWeek); ?></p>
                <?php endif; ?>
                <?php endif; ?>

                <form method="post" class="mt-3">
                    <input type="hidden" name="action" value="save_actual_weekly">
                    <input type="hidden" name="period_start" value="<?php echo htmlspecialchars($weekStart); ?>">
                    <input type="hidden" name="period_end" value="<?php echo htmlspecialchars($weekEnd); ?>">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small mb-0">Actual cash counted</label>
                            <input type="number" name="actual_cash" class="form-control form-control-sm" step="0.01" min="0" placeholder="Optional" value="<?php echo $actualCashWeek !== null ? (string)$actualCashWeek : ''; ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small mb-0">Notes</label>
                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional" value="<?php echo $actualNotesWeek !== null ? htmlspecialchars($actualNotesWeek) : ''; ?>">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-sm btn-bb-primary"><i class="bi bi-check-lg"></i> Save actual cash</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="bb-section-card card border-warning border-opacity-25">
    <div class="card-body">
        <h5 class="bb-section-title mb-2"><i class="bi bi-shield-exclamation"></i> Why this matters</h5>
        <p class="text-muted small mb-0">Comparing <strong>expected cash</strong> (from &quot;<?php echo htmlspecialchars($cashDrawerMethod); ?>&quot; sales) with <strong>actual cash</strong> you count in the drawer helps catch shortages early and prevents silent money leaks. Record actual counts daily or weekly.</p>
    </div>
</div>

<?php include 'partials/footer.php'; ?>
