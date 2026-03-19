<?php
require 'connection.php';

// Presets / range
$preset = $_GET['preset'] ?? 'daily';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

if ($preset === 'weekly') {
    $monday = date('Y-m-d', strtotime('monday this week'));
    $sunday = date('Y-m-d', strtotime('sunday this week'));
    $from = $from !== '' ? $from : $monday;
    $to = $to !== '' ? $to : $sunday;
} elseif ($preset === 'monthly') {
    $from = $from !== '' ? $from : date('Y-m-01');
    $to = $to !== '' ? $to : date('Y-m-t');
} else { // daily
    $from = $from !== '' ? $from : date('Y-m-d');
    $to = $to !== '' ? $to : date('Y-m-d');
}

$fromDt = $from . ' 00:00:00';
$toDt = $to . ' 23:59:59';

// Sales totals
$stmt = $pdo->prepare('SELECT SUM(price) AS total_sales, COUNT(*) AS total_customers FROM sales WHERE sale_datetime BETWEEN ? AND ?');
$stmt->execute([$fromDt, $toDt]);
$salesTotals = $stmt->fetch() ?: ['total_sales' => 0, 'total_customers' => 0];

// Barber share for range
$stmt = $pdo->prepare('
    SELECT SUM(s.price * (b.percentage_share / 100)) AS total_share
    FROM sales s
    JOIN barbers b ON s.barber_id = b.id
    WHERE s.sale_datetime BETWEEN ? AND ?
');
$stmt->execute([$fromDt, $toDt]);
$shareTotals = $stmt->fetch()['total_share'] ?? 0;

// Expenses totals (range)
$expensesTotals = 0;
try {
    $stmt = $pdo->prepare('SELECT SUM(amount) AS total FROM expenses WHERE expense_date BETWEEN ? AND ?');
    $stmt->execute([$from, $to]);
    $expensesTotals = $stmt->fetch()['total'] ?? 0;
} catch (Throwable $e) {
    $expensesTotals = 0;
}

$netProfit = ($salesTotals['total_sales'] ?: 0) - ($shareTotals ?: 0) - ($expensesTotals ?: 0);

// Top barber
$stmt = $pdo->prepare('
    SELECT b.name, SUM(s.price) AS total_sales
    FROM sales s
    JOIN barbers b ON s.barber_id = b.id
    WHERE s.sale_datetime BETWEEN ? AND ?
    GROUP BY b.id, b.name
    ORDER BY total_sales DESC
    LIMIT 1
');
$stmt->execute([$fromDt, $toDt]);
$topBarber = $stmt->fetch();

// Sales list (for print-friendly)
$stmt = $pdo->prepare('
    SELECT s.sale_datetime, b.name AS barber_name, sv.name AS service_name, s.price, s.payment_method, s.notes
    FROM sales s
    JOIN barbers b ON s.barber_id = b.id
    JOIN services sv ON s.service_id = sv.id
    WHERE s.sale_datetime BETWEEN ? AND ?
    ORDER BY s.sale_datetime DESC
');
$stmt->execute([$fromDt, $toDt]);
$salesRows = $stmt->fetchAll();

// Payroll report by barber
$stmt = $pdo->prepare('
    SELECT
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
$stmt->execute([$fromDt, $toDt]);
$payroll = $stmt->fetchAll();

// Revenue by promo (if promos table and sales.promo_id exist)
$revenueByPromo = [];
try {
    $pdo->query('SELECT promo_id FROM sales LIMIT 1');
    $stmt = $pdo->prepare('
        SELECT p.name AS promo_name, COUNT(s.id) AS sale_count, COALESCE(SUM(s.price), 0) AS revenue
        FROM sales s
        JOIN promos p ON s.promo_id = p.id
        WHERE s.sale_datetime BETWEEN ? AND ?
        GROUP BY p.id, p.name
        ORDER BY revenue DESC
    ');
    $stmt->execute([$fromDt, $toDt]);
    $revenueByPromo = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}
?>

<?php include 'partials/header.php'; ?>

<div class="d-print-none d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="bb-page-title">Reports</h1>
        <p class="bb-page-subtitle">Pick a date range, then generate or print. Use for payroll and sales details.</p>
    </div>
    <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
</div>

<div class="card border-0 shadow-sm mb-3 d-print-none">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-sm-4 col-md-3">
                <label class="form-label small">Preset</label>
                <select name="preset" class="form-select form-select-sm">
                    <option value="daily" <?php echo $preset === 'daily' ? 'selected' : ''; ?>>Daily</option>
                    <option value="weekly" <?php echo $preset === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                    <option value="monthly" <?php echo $preset === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                    <option value="custom" <?php echo $preset === 'custom' ? 'selected' : ''; ?>>Custom</option>
                </select>
            </div>
            <div class="col-sm-4 col-md-3">
                <label class="form-label small">From</label>
                <input type="date" class="form-control form-control-sm" name="from" value="<?php echo htmlspecialchars($from); ?>">
            </div>
            <div class="col-sm-4 col-md-3">
                <label class="form-label small">To</label>
                <input type="date" class="form-control form-control-sm" name="to" value="<?php echo htmlspecialchars($to); ?>">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-bb-primary" type="submit"><i class="bi bi-arrow-repeat"></i> Generate</button>
                <a class="btn btn-sm btn-outline-secondary" href="reports.php">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="bb-section-card card mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h5 class="bb-section-title mb-0"><i class="bi bi-graph-up"></i> Summary</h5>
            <div class="small text-muted">
                <?php echo htmlspecialchars($from); ?> to <?php echo htmlspecialchars($to); ?>
            </div>
        </div>

        <div class="row g-3 small">
            <div class="col-sm-6 col-lg-2">
                <div class="text-muted text-uppercase mb-1">Customers</div>
                <div class="h6 mb-0"><?php echo (int)($salesTotals['total_customers'] ?? 0); ?></div>
            </div>
            <div class="col-sm-6 col-lg-2">
                <div class="text-muted text-uppercase mb-1">Sales</div>
                <div class="h6 mb-0">₱<?php echo number_format($salesTotals['total_sales'] ?: 0, 2); ?></div>
            </div>
            <div class="col-sm-6 col-lg-2">
                <div class="text-muted text-uppercase mb-1">Barber share</div>
                <div class="h6 mb-0">₱<?php echo number_format($shareTotals ?: 0, 2); ?></div>
            </div>
            <div class="col-sm-6 col-lg-2">
                <div class="text-muted text-uppercase mb-1">Expenses</div>
                <div class="h6 mb-0">₱<?php echo number_format($expensesTotals ?: 0, 2); ?></div>
            </div>
            <div class="col-sm-6 col-lg-2">
                <div class="text-muted text-uppercase mb-1">Net profit</div>
                <div class="h6 mb-0">₱<?php echo number_format($netProfit, 2); ?></div>
            </div>
            <div class="col-sm-6 col-lg-2">
                <div class="text-muted text-uppercase mb-1">Top barber</div>
                <div class="h6 mb-0"><?php echo htmlspecialchars($topBarber['name'] ?? '—'); ?></div>
                <div class="text-muted small">₱<?php echo number_format($topBarber['total_sales'] ?? 0, 2); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="bb-section-card card mb-3">
    <div class="card-body">
        <h5 class="bb-section-title mb-3"><i class="bi bi-person-badge"></i> Barber payroll</h5>
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
                <?php foreach ($payroll as $row): ?>
                    <?php
                    $totalSales = $row['total_sales'] ?: 0;
                    $earnings = $totalSales * (($row['percentage_share'] ?: 0) / 100);
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                        <td class="text-end">₱<?php echo number_format($totalSales, 2); ?></td>
                        <td class="text-end"><?php echo number_format($row['percentage_share'] ?: 0, 2); ?>%</td>
                        <td class="text-end">₱<?php echo number_format($earnings, 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (!empty($revenueByPromo)): ?>
<div class="bb-section-card card mb-3">
    <div class="card-body">
        <h5 class="bb-section-title mb-3"><i class="bi bi-tag"></i> Revenue by promo</h5>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                <tr>
                    <th>Promo</th>
                    <th class="text-end">Sales count</th>
                    <th class="text-end">Revenue</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($revenueByPromo as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['promo_name']); ?></td>
                        <td class="text-end"><?php echo (int)$r['sale_count']; ?></td>
                        <td class="text-end">₱<?php echo number_format((float)$r['revenue'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="bb-section-card card">
    <div class="card-body">
        <h5 class="bb-section-title mb-3"><i class="bi bi-list-check"></i> Sales details</h5>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>Barber</th>
                    <th>Service</th>
                    <th class="text-end">Price</th>
                    <th>Payment</th>
                    <th>Notes</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$salesRows): ?>
                    <tr>
                        <td colspan="6" class="p-0">
                            <div class="bb-empty"><i class="bi bi-inbox"></i><p>No sales in this range.</p></div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($salesRows as $s): ?>
                        <tr>
                            <td class="text-muted"><?php echo htmlspecialchars($s['sale_datetime']); ?></td>
                            <td><?php echo htmlspecialchars($s['barber_name']); ?></td>
                            <td><?php echo htmlspecialchars($s['service_name']); ?></td>
                            <td class="text-end">₱<?php echo number_format($s['price'], 2); ?></td>
                            <td><?php echo htmlspecialchars($s['payment_method'] ?? ''); ?></td>
                            <td class="text-muted"><?php echo htmlspecialchars($s['notes'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
    @media print {
        .bb-navbar, aside, .bb-footer, .d-print-none { display: none !important; }
        main { width: 100% !important; }
        .card { box-shadow: none !important; border: 1px solid #ddd !important; }
        .table { font-size: 12px; }
    }
</style>

<?php include 'partials/footer.php'; ?>

