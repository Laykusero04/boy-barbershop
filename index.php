<?php
require 'connection.php';

// --- Insight preferences (configurable owner pay % and target years) ---
$ownerPayPercent = 70;
$insightTargetYears = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_insight_settings') {
    $pct = (int)($_POST['insight_owner_pay_percent'] ?? 70);
    if ($pct >= 1 && $pct <= 100) {
        try {
            $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
            $stmt->execute(['insight_owner_pay_percent', (string)$pct]);
        } catch (Throwable $e) {}
        $ownerPayPercent = $pct;
    }
    $years = (int)($_POST['insight_target_years'] ?? 0);
    if ($years >= 1 && $years <= 100) {
        try {
            $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
            $stmt->execute(['insight_target_years', (string)$years]);
        } catch (Throwable $e) {}
        $insightTargetYears = $years;
    } elseif ($years === 0) {
        try {
            $stmt = $pdo->prepare('DELETE FROM settings WHERE `key` = ?');
            $stmt->execute(['insight_target_years']);
        } catch (Throwable $e) {}
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
    $stmt->execute(['insight_owner_pay_percent']);
    $row = $stmt->fetch();
    $ownerPayPercent = $row ? (int)$row['value'] : 70;
    if ($ownerPayPercent < 1 || $ownerPayPercent > 100) $ownerPayPercent = 70;
} catch (Throwable $e) {
    $ownerPayPercent = 70;
}
try {
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
    $stmt->execute(['insight_target_years']);
    $row = $stmt->fetch();
    $insightTargetYears = $row ? (int)$row['value'] : 0;
} catch (Throwable $e) {
    $insightTargetYears = 0;
}

// Today range
$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 23:59:59');
// Month range
$monthStart = date('Y-m-01 00:00:00');
$monthEnd = date('Y-m-t 23:59:59');

// Total sales today
$stmt = $pdo->prepare('SELECT SUM(price) AS total_sales, COUNT(*) AS total_transactions FROM sales WHERE sale_datetime BETWEEN ? AND ?');
$stmt->execute([$todayStart, $todayEnd]);
$today = $stmt->fetch() ?: ['total_sales' => 0, 'total_transactions' => 0];

// Total barber share today
$stmt = $pdo->prepare('
    SELECT SUM(s.price * (b.percentage_share / 100)) AS total_share
    FROM sales s
    JOIN barbers b ON s.barber_id = b.id
    WHERE s.sale_datetime BETWEEN ? AND ?
');
$stmt->execute([$todayStart, $todayEnd]);
$todayShare = $stmt->fetch()['total_share'] ?? 0;

// Total expenses today (if table exists)
$todayExpenses = 0;
try {
    $stmt = $pdo->prepare('SELECT SUM(amount) AS total FROM expenses WHERE expense_date = ?');
    $stmt->execute([date('Y-m-d')]);
    $todayExpenses = $stmt->fetch()['total'] ?? 0;
} catch (Throwable $e) {
    $todayExpenses = 0;
}

// Monthly totals
$stmt = $pdo->prepare('SELECT SUM(price) AS total_sales FROM sales WHERE sale_datetime BETWEEN ? AND ?');
$stmt->execute([$monthStart, $monthEnd]);
$monthSales = $stmt->fetch()['total_sales'] ?? 0;

$stmt = $pdo->prepare('SELECT COUNT(*) AS total_transactions FROM sales WHERE sale_datetime BETWEEN ? AND ?');
$stmt->execute([$monthStart, $monthEnd]);
$monthCustomers = $stmt->fetch()['total_transactions'] ?? 0;

$stmt = $pdo->prepare('
    SELECT SUM(s.price * (b.percentage_share / 100)) AS total_share
    FROM sales s
    JOIN barbers b ON s.barber_id = b.id
    WHERE s.sale_datetime BETWEEN ? AND ?
');
$stmt->execute([$monthStart, $monthEnd]);
$monthShare = $stmt->fetch()['total_share'] ?? 0;

$monthExpenses = 0;
try {
    $stmt = $pdo->prepare('SELECT SUM(amount) AS total FROM expenses WHERE expense_date BETWEEN ? AND ?');
    $stmt->execute([date('Y-m-01'), date('Y-m-t')]);
    $monthExpenses = $stmt->fetch()['total'] ?? 0;
} catch (Throwable $e) {
    $monthExpenses = 0;
}

$todayProfit = ($today['total_sales'] ?: 0) - ($todayShare ?: 0) - ($todayExpenses ?: 0);
$monthProfit = ($monthSales ?: 0) - ($monthShare ?: 0) - ($monthExpenses ?: 0);

// Top barbers
$stmt = $pdo->prepare('
    SELECT b.name, SUM(s.price) AS total_sales
    FROM sales s
    JOIN barbers b ON s.barber_id = b.id
    WHERE s.sale_datetime BETWEEN ? AND ?
    GROUP BY b.id, b.name
    ORDER BY total_sales DESC
    LIMIT 1
');
$stmt->execute([$todayStart, $todayEnd]);
$topToday = $stmt->fetch();

$stmt = $pdo->prepare('
    SELECT b.name, SUM(s.price) AS total_sales
    FROM sales s
    JOIN barbers b ON s.barber_id = b.id
    WHERE s.sale_datetime BETWEEN ? AND ?
    GROUP BY b.id, b.name
    ORDER BY total_sales DESC
    LIMIT 1
');
$stmt->execute([$monthStart, $monthEnd]);
$topMonth = $stmt->fetch();

// All-time totals for ROI
$stmt = $pdo->query('SELECT SUM(price) AS total_sales FROM sales');
$allSales = $stmt->fetch()['total_sales'] ?? 0;

$stmt = $pdo->query('
    SELECT SUM(s.price * (b.percentage_share / 100)) AS total_share
    FROM sales s
    JOIN barbers b ON s.barber_id = b.id
');
$allShare = $stmt->fetch()['total_share'] ?? 0;

$allExpenses = 0;
try {
    $stmt = $pdo->query('SELECT SUM(amount) AS total FROM expenses');
    $allExpenses = $stmt->fetch()['total'] ?? 0;
} catch (Throwable $e) {
    $allExpenses = 0;
}

$allProfit = ($allSales ?: 0) - ($allShare ?: 0) - ($allExpenses ?: 0);

$totalInvestment = 0;
try {
    $stmt = $pdo->query('SELECT SUM(cost) AS total FROM investments');
    $totalInvestment = $stmt->fetch()['total'] ?? 0;
} catch (Throwable $e) {
    $totalInvestment = 0;
}

$roiPercent = 0;
$roiProgress = 0;
if (($totalInvestment ?: 0) > 0) {
    $roiPercent = ($allProfit / $totalInvestment) * 100;
    $roiProgress = min(100, max(0, ($allProfit / $totalInvestment) * 100));
}

// --- Insights: monthly aggregates for suggestions (last 12 months) ---
$insightsMonths = [];
try {
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(sale_datetime, '%Y-%m') AS ym, SUM(price) AS total_sales
        FROM sales
        GROUP BY ym
        ORDER BY ym DESC
        LIMIT 12
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $insightsMonths[$row['ym']] = [
            'sales' => (float)($row['total_sales'] ?? 0),
            'share' => 0,
            'expenses' => 0,
            'profit' => 0,
        ];
    }
    // Barber share by month
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(s.sale_datetime, '%Y-%m') AS ym,
               SUM(s.price * (b.percentage_share / 100)) AS total_share
        FROM sales s
        JOIN barbers b ON s.barber_id = b.id
        GROUP BY ym
        ORDER BY ym DESC
        LIMIT 12
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($insightsMonths[$row['ym']])) {
            $insightsMonths[$row['ym']]['share'] = (float)($row['total_share'] ?? 0);
        }
    }
    // Expenses by month
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym, SUM(amount) AS total
        FROM expenses
        GROUP BY ym
        ORDER BY ym DESC
        LIMIT 12
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($insightsMonths[$row['ym']])) {
            $insightsMonths[$row['ym']]['expenses'] = (float)($row['total'] ?? 0);
        }
    }
    // Include months that have only expenses (no sales)
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym, SUM(amount) AS total
        FROM expenses
        GROUP BY ym
        ORDER BY ym DESC
        LIMIT 12
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($insightsMonths[$row['ym']])) {
            $insightsMonths[$row['ym']] = ['sales' => 0, 'share' => 0, 'expenses' => (float)($row['total'] ?? 0), 'profit' => 0];
        }
    }
    krsort($insightsMonths, SORT_STRING);
    $insightsMonths = array_slice($insightsMonths, 0, 12, true);
    foreach ($insightsMonths as $ym => &$data) {
        $data['profit'] = $data['sales'] - $data['share'] - $data['expenses'];
    }
    unset($data);
} catch (Throwable $e) {
    $insightsMonths = [];
}

$avgMonthlySales = 0;
$avgMonthlyExpenses = 0;
$avgMonthlyProfit = 0;
$suggestedOwnerPay = 0;
$monthsToPayback = null;
$paybackFormatted = null; // e.g. "52 years, 11 months, 5 days"
$goalRequiredProfit = null;  // monthly profit needed to recover in target years
$goalRequiredSales = null;   // approx monthly sales at current margin
$insightTipSales = null;
$insightTipExpenses = null;
$insightMonthCount = count($insightsMonths);

if ($insightMonthCount > 0) {
    $avgMonthlySales = array_sum(array_column($insightsMonths, 'sales')) / $insightMonthCount;
    $avgMonthlyExpenses = array_sum(array_column($insightsMonths, 'expenses')) / $insightMonthCount;
    $avgMonthlyProfit = array_sum(array_column($insightsMonths, 'profit')) / $insightMonthCount;
    // Suggest owner pay: configurable % of average monthly profit
    $suggestedOwnerPay = max(0, $avgMonthlyProfit * ($ownerPayPercent / 100));
    if (($totalInvestment ?: 0) > 0 && $avgMonthlyProfit > 0) {
        $totalMonths = $totalInvestment / $avgMonthlyProfit;
        $monthsToPayback = (int) ceil($totalMonths);
        // Format as years, months, days (≈30 days per fractional month)
        $wholeMonths = (int) floor($totalMonths);
        $years = (int) floor($wholeMonths / 12);
        $months = $wholeMonths % 12;
        $days = (int) round(($totalMonths - $wholeMonths) * 30);
        if ($days >= 30) {
            $days = 0;
            $months++;
            if ($months >= 12) {
                $months = 0;
                $years++;
            }
        }
        $parts = [];
        if ($years > 0) {
            $parts[] = $years . ' ' . ($years === 1 ? 'year' : 'years');
        }
        if ($months > 0) {
            $parts[] = $months . ' ' . ($months === 1 ? 'month' : 'months');
        }
        if ($days > 0 || empty($parts)) {
            $parts[] = $days . ' ' . ($days === 1 ? 'day' : 'days');
        }
        $paybackFormatted = implode(', ', $parts);
    }
    // Goal: "recover in X years" → required monthly profit and (approx) sales
    if ($insightTargetYears >= 1 && ($totalInvestment ?: 0) > 0) {
        $monthsToTarget = $insightTargetYears * 12;
        $goalRequiredProfit = $totalInvestment / $monthsToTarget;
        if ($avgMonthlyProfit > 0 && $avgMonthlySales > 0) {
            $profitMarginRatio = $avgMonthlyProfit / $avgMonthlySales;
            $goalRequiredSales = $profitMarginRatio > 0 ? $goalRequiredProfit / $profitMarginRatio : null;
        }
    }
    // Compare this month to average
    $thisYm = date('Y-m');
    if (isset($insightsMonths[$thisYm])) {
        $thisMonth = $insightsMonths[$thisYm];
        if ($avgMonthlySales > 0 && $thisMonth['sales'] != $avgMonthlySales) {
            $pct = (($thisMonth['sales'] - $avgMonthlySales) / $avgMonthlySales) * 100;
            $insightTipSales = $pct > 0
                ? round($pct, 0) . '% above your average monthly sales'
                : round(abs($pct), 0) . '% below your average monthly sales';
        }
        if ($avgMonthlyExpenses > 0 && $thisMonth['expenses'] != $avgMonthlyExpenses) {
            $pct = (($thisMonth['expenses'] - $avgMonthlyExpenses) / $avgMonthlyExpenses) * 100;
            $insightTipExpenses = $pct > 0
                ? round($pct, 0) . '% above your average monthly expenses'
                : round(abs($pct), 0) . '% below your average monthly expenses';
        }
    }
}

// --- Smart alerts (passive intelligence) ---
$smartAlerts = [];
$todaySales = (float)($today['total_sales'] ?? 0);

// Daily target (from Analytics / Peak)
$dailyTarget = 0;
try {
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
    $stmt->execute(['daily_target']);
    $r = $stmt->fetch();
    $dailyTarget = $r ? (float)$r['value'] : 0;
} catch (Throwable $e) {}

if ($dailyTarget > 0 && $todaySales < $dailyTarget) {
    $short = $dailyTarget - $todaySales;
    $smartAlerts[] = [
        'type' => 'target',
        'message' => 'You are below daily target (₱' . number_format($short, 0) . ' to go).',
        'link' => 'analytics.php?section=peak',
        'icon' => 'bi-bullseye',
    ];
}

// Low sales today vs average (last 30 days average daily sales)
try {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(price), 0) AS total FROM sales WHERE sale_datetime >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)');
    $stmt->execute();
    $last30Total = (float)($stmt->fetch()['total'] ?? 0);
    $avgDailySales = $last30Total / 30;
    if ($avgDailySales > 0 && $todaySales > 0 && $todaySales < $avgDailySales * 0.7) {
        $pct = round((1 - $todaySales / $avgDailySales) * 100);
        $smartAlerts[] = [
            'type' => 'sales',
            'message' => 'Low sales today vs average (' . $pct . '% below your usual).',
            'link' => 'add_sale.php',
            'icon' => 'bi-graph-down',
        ];
    }
} catch (Throwable $e) {}

// Inventory low
try {
    $lowStock = $pdo->query('SELECT item_name, stock_qty, low_stock_threshold FROM inventory_items WHERE is_active = 1 AND stock_qty <= low_stock_threshold')->fetchAll();
    if (!empty($lowStock)) {
        $names = array_map(function ($r) { return $r['item_name'] . ' (' . $r['stock_qty'] . ')'; }, array_slice($lowStock, 0, 3));
        $smartAlerts[] = [
            'type' => 'inventory',
            'message' => 'Inventory low: ' . implode(', ', $names) . (count($lowStock) > 3 ? ' +' . (count($lowStock) - 3) . ' more' : '') . '.',
            'link' => 'inventory.php',
            'icon' => 'bi-box-seam',
        ];
    }
} catch (Throwable $e) {}

// Expenses unusually high (this month vs avg monthly)
if ($insightMonthCount > 0 && $avgMonthlyExpenses > 0 && isset($insightsMonths[date('Y-m')])) {
    $thisMonthExp = (float)$insightsMonths[date('Y-m')]['expenses'];
    if ($thisMonthExp > $avgMonthlyExpenses * 1.2) {
        $pct = round((($thisMonthExp - $avgMonthlyExpenses) / $avgMonthlyExpenses) * 100);
        $smartAlerts[] = [
            'type' => 'expenses',
            'message' => 'Expenses unusually high this month (' . $pct . '% above average).',
            'link' => 'expenses.php',
            'icon' => 'bi-receipt',
        ];
    }
}

// List of today's sales
$stmt = $pdo->prepare('
    SELECT s.id, s.price, s.sale_datetime, b.name AS barber_name, sv.name AS service_name
    FROM sales s
    JOIN barbers b ON s.barber_id = b.id
    JOIN services sv ON s.service_id = sv.id
    WHERE s.sale_datetime BETWEEN ? AND ?
    ORDER BY s.sale_datetime DESC
');
$stmt->execute([$todayStart, $todayEnd]);
$salesToday = $stmt->fetchAll();

// Barber earnings for today
$stmt = $pdo->prepare('
    SELECT
        b.id,
        b.name,
        b.percentage_share,
        SUM(s.price) AS total_sales
    FROM barbers b
    LEFT JOIN sales s
        ON s.barber_id = b.id
        AND s.sale_datetime BETWEEN ? AND ?
    WHERE b.is_active = 1
    GROUP BY b.id, b.name, b.percentage_share
    ORDER BY b.name
');
$stmt->execute([$todayStart, $todayEnd]);
$earnings = $stmt->fetchAll();
?>
<?php include 'partials/header.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="bb-page-title">Dashboard</h1>
        <p class="bb-page-subtitle">Overview and quick actions. Use Reports for print-ready summaries.</p>
    </div>
    <a href="reports.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-text"></i> Reports</a>
</div>

<?php if (!empty($smartAlerts)): ?>
<div class="mb-4">
    <div class="card border-0 shadow-sm">
        <div class="card-body py-2 px-3">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <span class="text-muted small"><i class="bi bi-bell me-1"></i> Alerts</span>
            </div>
            <ul class="list-unstyled mb-0 small">
                <?php foreach ($smartAlerts as $i => $alert): ?>
                <li class="d-flex align-items-center gap-2 py-1 <?php echo $i < count($smartAlerts) - 1 ? 'border-bottom border-secondary border-opacity-25' : ''; ?>">
                    <i class="bi <?php echo htmlspecialchars($alert['icon']); ?> text-warning"></i>
                    <span class="flex-grow-1"><?php echo htmlspecialchars($alert['message']); ?></span>
                    <?php if (!empty($alert['link'])): ?>
                    <a href="<?php echo htmlspecialchars($alert['link']); ?>" class="btn btn-sm btn-outline-secondary">View</a>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Today -->
<div class="bb-section-card card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h5 class="bb-section-title mb-0"><i class="bi bi-calendar-day"></i> Today</h5>
            <span class="bb-section-subtitle"><?php echo htmlspecialchars(date('F j, Y')); ?></span>
        </div>
        <div class="bb-stat-grid">
            <div class="bb-stat-card">
                <div class="bb-stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-people"></i></div>
                <div class="bb-stat-label">Customers</div>
                <div class="bb-stat-value"><?php echo (int)$today['total_transactions']; ?></div>
            </div>
            <div class="bb-stat-card">
                <div class="bb-stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-currency-exchange"></i></div>
                <div class="bb-stat-label">Total sales</div>
                <div class="bb-stat-value">₱<?php echo number_format($today['total_sales'] ?: 0, 2); ?></div>
            </div>
            <div class="bb-stat-card">
                <div class="bb-stat-icon bg-secondary bg-opacity-10 text-secondary"><i class="bi bi-person-check"></i></div>
                <div class="bb-stat-label">Barber share</div>
                <div class="bb-stat-value">₱<?php echo number_format($todayShare ?: 0, 2); ?></div>
            </div>
            <div class="bb-stat-card">
                <div class="bb-stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-receipt"></i></div>
                <div class="bb-stat-label">Expenses</div>
                <div class="bb-stat-value">₱<?php echo number_format($todayExpenses ?: 0, 2); ?></div>
            </div>
            <div class="bb-stat-card">
                <div class="bb-stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="bb-stat-label">Net profit</div>
                <div class="bb-stat-value">₱<?php echo number_format($todayProfit, 2); ?></div>
            </div>
            <div class="bb-stat-card">
                <div class="bb-stat-icon bg-info bg-opacity-10 text-info"><i class="bi bi-trophy"></i></div>
                <div class="bb-stat-label">Top barber</div>
                <div class="bb-stat-value"><?php echo htmlspecialchars($topToday['name'] ?? '—'); ?></div>
                <div class="bb-stat-value"><small>₱<?php echo number_format($topToday['total_sales'] ?? 0, 2); ?></small></div>
            </div>
        </div>
    </div>
</div>

<!-- This Month -->
<div class="bb-section-card card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h5 class="bb-section-title mb-0"><i class="bi bi-calendar-month"></i> This month</h5>
            <span class="bb-section-subtitle"><?php echo htmlspecialchars(date('F Y')); ?></span>
        </div>
        <div class="bb-stat-grid">
            <div class="bb-stat-card">
                <div class="bb-stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-currency-exchange"></i></div>
                <div class="bb-stat-label">Total sales</div>
                <div class="bb-stat-value">₱<?php echo number_format($monthSales ?: 0, 2); ?></div>
            </div>
            <div class="bb-stat-card">
                <div class="bb-stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-receipt"></i></div>
                <div class="bb-stat-label">Total expenses</div>
                <div class="bb-stat-value">₱<?php echo number_format($monthExpenses ?: 0, 2); ?></div>
            </div>
            <div class="bb-stat-card">
                <div class="bb-stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="bb-stat-label">Net profit</div>
                <div class="bb-stat-value">₱<?php echo number_format($monthProfit, 2); ?></div>
            </div>
            <div class="bb-stat-card">
                <div class="bb-stat-icon bg-info bg-opacity-10 text-info"><i class="bi bi-trophy"></i></div>
                <div class="bb-stat-label">Top barber</div>
                <div class="bb-stat-value"><?php echo htmlspecialchars($topMonth['name'] ?? '—'); ?></div>
                <div class="bb-stat-value"><small>₱<?php echo number_format($topMonth['total_sales'] ?? 0, 2); ?></small></div>
            </div>
        </div>
    </div>
</div>

<!-- Quick actions -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <a href="add_sale.php" class="text-decoration-none text-body">
            <div class="bb-action-card">
                <div class="bb-action-icon"><i class="bi bi-plus-circle"></i></div>
                <div class="bb-action-title">Add sale</div>
                <div class="bb-action-desc">Record a new haircut or service with barber, service, and payment.</div>
                <span class="btn btn-sm btn-bb-primary"><i class="bi bi-plus-lg"></i> Add sale</span>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="expenses.php" class="text-decoration-none text-body">
            <div class="bb-action-card">
                <div class="bb-action-icon"><i class="bi bi-receipt"></i></div>
                <div class="bb-action-title">Expenses</div>
                <div class="bb-action-desc">Track rent, supplies, and other costs by date and category.</div>
                <span class="btn btn-sm btn-outline-secondary">Go to Expenses</span>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="reports.php" class="text-decoration-none text-body">
            <div class="bb-action-card">
                <div class="bb-action-icon"><i class="bi bi-file-earmark-text"></i></div>
                <div class="bb-action-title">Reports</div>
                <div class="bb-action-desc">Daily, weekly, and monthly reports plus barber payroll.</div>
                <span class="btn btn-sm btn-outline-secondary">Open reports</span>
            </div>
        </a>
    </div>
</div>

<!-- ROI -->
<div class="bb-section-card card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h5 class="bb-section-title mb-0"><i class="bi bi-piggy-bank"></i> ROI (all-time)</h5>
            <a href="investments.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-plus-lg"></i> Investments</a>
        </div>
        <div class="bb-stat-grid">
            <div class="bb-stat-card">
                <div class="bb-stat-icon bg-secondary bg-opacity-10 text-secondary"><i class="bi bi-wallet2"></i></div>
                <div class="bb-stat-label">Total investment</div>
                <div class="bb-stat-value">₱<?php echo number_format($totalInvestment ?: 0, 2); ?></div>
            </div>
            <div class="bb-stat-card">
                <div class="bb-stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-currency-exchange"></i></div>
                <div class="bb-stat-label">Total profit earned</div>
                <div class="bb-stat-value">₱<?php echo number_format($allProfit ?: 0, 2); ?></div>
            </div>
            <div class="bb-stat-card">
                <div class="bb-stat-icon bg-info bg-opacity-10 text-info"><i class="bi bi-percent"></i></div>
                <div class="bb-stat-label">ROI</div>
                <div class="bb-stat-value"><?php echo number_format($roiPercent, 2); ?>%</div>
            </div>
        </div>
        <div class="mt-3">
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span>Progress to getting your money back</span>
                <span><?php echo number_format($roiProgress, 0); ?>%</span>
            </div>
            <div class="progress" role="progressbar" aria-valuenow="<?php echo (int)$roiProgress; ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar bg-primary" style="width: <?php echo (float)$roiProgress; ?>%"></div>
            </div>
            <?php if (($totalInvestment ?: 0) <= 0): ?>
                <p class="form-text small mb-0 mt-1">Add investment items in <a href="investments.php">Investments</a> to enable ROI.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Insights / Suggestions (based on sales & expenses) -->
<div class="bb-section-card card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h5 class="bb-section-title mb-0"><i class="bi bi-lightbulb"></i> Insights & suggestions</h5>
            <?php if ($insightMonthCount > 0): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#insight-customize" aria-expanded="false">
                <i class="bi bi-gear"></i> Customize
            </button>
            <?php endif; ?>
        </div>
        <p class="text-muted small mb-3">Based on your last <?php echo $insightMonthCount; ?> month(s) of sales and expenses. Use this to decide owner pay and targets.</p>

        <?php if ($insightMonthCount > 0): ?>
        <div class="collapse mb-3" id="insight-customize">
            <div class="p-3 rounded bb-insight-card bb-insight-card-neutral">
                <form method="post" action="">
                    <input type="hidden" name="action" value="save_insight_settings">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Owner pay suggestion</label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="insight_owner_pay_percent" class="form-control" min="1" max="100" value="<?php echo (int)$ownerPayPercent; ?>" aria-label="Percent of average profit">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="form-text small">% of average monthly profit (e.g. 20 or 70)</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Goal: recover investment in</label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="insight_target_years" class="form-control" min="0" max="100" value="<?php echo $insightTargetYears ?: ''; ?>" placeholder="e.g. 10" aria-label="Target years">
                                <span class="input-group-text">years</span>
                            </div>
                            <div class="form-text small">Set 0 to hide goal. Shows required profit/sales.</div>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-sm btn-bb-primary"><i class="bi bi-check-lg"></i> Save</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($insightMonthCount === 0): ?>
            <p class="mb-0 text-muted">Record more <a href="add_sale.php">sales</a> and <a href="expenses.php">expenses</a> to see suggestions here.</p>
        <?php else: ?>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="p-3 rounded bb-insight-card bb-insight-card-neutral">
                        <div class="small text-muted mb-1">Average monthly net profit</div>
                        <div class="h5 mb-0">₱<?php echo number_format($avgMonthlyProfit, 2); ?></div>
                        <div class="small text-muted">(sales − barber share − expenses)</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 rounded bg-success bg-opacity-10 border border-success border-opacity-25">
                        <div class="small text-muted mb-1">Suggested monthly owner pay</div>
                        <div class="h5 mb-0 text-success">₱<?php echo number_format($suggestedOwnerPay, 2); ?></div>
                        <div class="small text-muted"><?php echo (int)$ownerPayPercent; ?>% of avg profit<?php echo $ownerPayPercent === 70 ? ' (default buffer)' : ''; ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 rounded bg-primary bg-opacity-10 border border-primary border-opacity-25">
                        <div class="small text-muted mb-1">Estimated time to recover investment</div>
                        <?php if ($paybackFormatted !== null): ?>
                            <div class="h5 mb-0 text-primary">~<?php echo htmlspecialchars($paybackFormatted); ?></div>
                            <div class="small text-muted">At current average profit rate</div>
                        <?php else: ?>
                            <div class="h5 mb-0 text-secondary">—</div>
                            <div class="small text-muted">Add investments &amp; profit to estimate</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($insightTargetYears >= 1 && $goalRequiredProfit !== null): ?>
            <div class="p-3 rounded bg-primary bg-opacity-10 border border-primary border-opacity-25 mb-3">
                <div class="small text-muted mb-1">To recover your investment in <strong><?php echo $insightTargetYears; ?> year<?php echo $insightTargetYears === 1 ? '' : 's'; ?></strong> you need:</div>
                <ul class="mb-0 small">
                    <li><strong>₱<?php echo number_format($goalRequiredProfit, 2); ?></strong> net profit per month</li>
                    <?php if ($goalRequiredSales !== null): ?>
                    <li>About <strong>₱<?php echo number_format($goalRequiredSales, 2); ?></strong> sales per month (at your current profit margin)</li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
            <ul class="list-unstyled mb-0 small">
                <?php if ($insightTipSales !== null): ?>
                    <li class="mb-1"><i class="bi bi-graph-up text-info me-2"></i>This month’s sales are <strong><?php echo htmlspecialchars($insightTipSales); ?></strong>.</li>
                <?php endif; ?>
                <?php if ($insightTipExpenses !== null): ?>
                    <li class="mb-1"><i class="bi bi-receipt text-warning me-2"></i>This month’s expenses are <strong><?php echo htmlspecialchars($insightTipExpenses); ?></strong>.</li>
                <?php endif; ?>
                <?php if ($avgMonthlyProfit < 0 && $insightMonthCount > 0): ?>
                    <li class="mb-1 text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Average monthly profit is negative. Focus on cutting expenses or increasing sales before taking owner pay.</li>
                <?php endif; ?>
                <?php if ($insightMonthCount > 0 && !$insightTipSales && !$insightTipExpenses): ?>
                    <li class="text-muted"><i class="bi bi-check2 me-2"></i>This month is in line with your recent averages.</li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="bb-section-card card h-100">
            <div class="card-body">
                <h5 class="bb-section-title mb-3"><i class="bi bi-list-check"></i> Today’s sales</h5>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Time</th>
                            <th>Barber</th>
                            <th>Service</th>
                            <th class="text-end">Price</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$salesToday): ?>
                            <tr>
                                <td colspan="4" class="p-0">
                                    <div class="bb-empty">
                                        <i class="bi bi-cart-x"></i>
                                        <p>No sales recorded yet today.</p>
                                        <a href="add_sale.php" class="btn btn-sm btn-bb-primary mt-2"><i class="bi bi-plus-lg"></i> Add sale</a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($salesToday as $sale): ?>
                                <tr>
                                    <td><?php echo date('H:i', strtotime($sale['sale_datetime'])); ?></td>
                                    <td><?php echo htmlspecialchars($sale['barber_name']); ?></td>
                                    <td><?php echo htmlspecialchars($sale['service_name']); ?></td>
                                    <td class="text-end fw-semibold">₱<?php echo number_format($sale['price'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="bb-section-card card h-100">
            <div class="card-body">
                <h5 class="bb-section-title mb-3"><i class="bi bi-person-badge"></i> Barber earnings (today)</h5>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Barber</th>
                            <th class="text-end">Sales</th>
                            <th class="text-end">Share %</th>
                            <th class="text-end">Earnings</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($earnings as $row): ?>
                            <?php
                            $totalSales = $row['total_sales'] ?: 0;
                            $earningsAmount = $totalSales * ($row['percentage_share'] / 100);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td class="text-end">₱<?php echo number_format($totalSales, 2); ?></td>
                                <td class="text-end"><?php echo number_format($row['percentage_share'], 2); ?>%</td>
                                <td class="text-end fw-semibold">₱<?php echo number_format($earningsAmount, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>

