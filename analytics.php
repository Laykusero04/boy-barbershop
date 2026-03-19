<?php
require 'connection.php';

$message = null;
$messageType = 'info';

// Helpers for settings
function getSetting(PDO $pdo, string $key, ?string $default = null): ?string
{
    try {
        $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function setSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('
        INSERT INTO settings (`key`, `value`)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ');
    $stmt->execute([$key, $value]);
}

// Save daily target (redirect to peak section after save)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_target') {
    $target = (float)($_POST['daily_target'] ?? 0);
    if ($target > 0) {
        try {
            setSetting($pdo, 'daily_target', (string)$target);
            $_SESSION['flash'] = ['type' => 'success', 'text' => 'Daily target saved.'];
            header('Location: analytics.php?section=peak');
            exit;
        } catch (Throwable $e) {
            $message = 'Unable to save target (settings table not set up yet).';
            $messageType = 'error';
        }
    } else {
        $message = 'Please enter a target greater than 0.';
        $messageType = 'info';
    }
}

$dailyTarget = (float)(getSetting($pdo, 'daily_target', '0') ?? '0');

// Today sales
$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 23:59:59');

$stmt = $pdo->prepare('SELECT SUM(price) AS total_sales, COUNT(*) AS total_customers, AVG(price) AS avg_sale_price FROM sales WHERE sale_datetime BETWEEN ? AND ?');
$stmt->execute([$todayStart, $todayEnd]);
$today = $stmt->fetch() ?: ['total_sales' => 0, 'total_customers' => 0, 'avg_sale_price' => 0];

$todaySales = (float)($today['total_sales'] ?? 0);

// Average service price (fallback to today's avg sale price)
$avgServicePrice = 0.0;
try {
    $avgServicePrice = (float)($pdo->query('SELECT AVG(default_price) AS avg_price FROM services WHERE is_active = 1')->fetch()['avg_price'] ?? 0);
} catch (Throwable $e) {
    $avgServicePrice = 0.0;
}
if ($avgServicePrice <= 0) {
    $avgServicePrice = (float)($today['avg_sale_price'] ?? 0);
}
if ($avgServicePrice <= 0) {
    $avgServicePrice = 100.0; // last-resort fallback for estimate
}

$remaining = max(0, $dailyTarget - $todaySales);
$estimatedHaircuts = $remaining > 0 ? (int)ceil($remaining / $avgServicePrice) : 0;
$targetProgress = $dailyTarget > 0 ? min(100, max(0, ($todaySales / $dailyTarget) * 100)) : 0;

// Peak hour analyzer (today)
$stmt = $pdo->prepare('
    SELECT HOUR(sale_datetime) AS hr, COUNT(*) AS customers, SUM(price) AS sales
    FROM sales
    WHERE sale_datetime BETWEEN ? AND ?
    GROUP BY HOUR(sale_datetime)
    ORDER BY hr ASC
');
$stmt->execute([$todayStart, $todayEnd]);
$hours = $stmt->fetchAll();

$hourMap = [];
for ($h = 0; $h < 24; $h++) {
    $hourMap[$h] = ['hr' => $h, 'customers' => 0, 'sales' => 0];
}
foreach ($hours as $row) {
    $h = (int)$row['hr'];
    $hourMap[$h] = [
        'hr' => $h,
        'customers' => (int)($row['customers'] ?? 0),
        'sales' => (float)($row['sales'] ?? 0),
    ];
}
$hourRows = array_values($hourMap);

$peakCustomers = 0;
$peakHour = null;
foreach ($hourRows as $r) {
    if ($r['customers'] > $peakCustomers) {
        $peakCustomers = $r['customers'];
        $peakHour = $r['hr'];
    }
}

// ----- Usual customer activity by hour (historical, for planning when to start/rest/close)
$lookbackDays = (int)($_GET['lookback_days'] ?? 30);
$lookbackDays = $lookbackDays <= 0 ? 30 : min(365, $lookbackDays);
$historyStart = date('Y-m-d 00:00:00', strtotime("-{$lookbackDays} days"));
$historyEnd = date('Y-m-d 23:59:59');

$stmt = $pdo->prepare('
    SELECT HOUR(sale_datetime) AS hr, COUNT(*) AS customers, SUM(price) AS sales
    FROM sales
    WHERE sale_datetime BETWEEN ? AND ?
    GROUP BY HOUR(sale_datetime)
    ORDER BY hr ASC
');
$stmt->execute([$historyStart, $historyEnd]);
$historyHours = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT COUNT(DISTINCT DATE(sale_datetime)) AS days FROM sales WHERE sale_datetime BETWEEN ? AND ?');
$stmt->execute([$historyStart, $historyEnd]);
$daysInRange = (int)($stmt->fetch()['days'] ?? 0);

$hourMapHistory = [];
for ($h = 0; $h < 24; $h++) {
    $hourMapHistory[$h] = ['hr' => $h, 'customers' => 0, 'sales' => 0.0, 'avg_per_day' => 0.0];
}
foreach ($historyHours as $row) {
    $h = (int)$row['hr'];
    $cust = (int)($row['customers'] ?? 0);
    $hourMapHistory[$h] = [
        'hr' => $h,
        'customers' => $cust,
        'sales' => (float)($row['sales'] ?? 0),
        'avg_per_day' => $daysInRange > 0 ? round($cust / $daysInRange, 1) : 0,
    ];
}
$historyRows = array_values($hourMapHistory);

$maxCust = 0;
foreach ($historyRows as $r) {
    if ($r['customers'] > $maxCust) $maxCust = $r['customers'];
}
$busyThreshold = $maxCust > 0 ? $maxCust * 0.5 : 0; // "usually busy" if >= 50% of peak

$section = isset($_GET['section']) && $_GET['section'] === 'activity' ? 'activity' : 'peak';
?>
<?php include 'partials/header.php'; ?>

<div class="mb-4">
    <h1 class="bb-page-title">Peak</h1>
    <p class="bb-page-subtitle">Usual customer activity by hour, or today’s peak and daily target.</p>
    <div class="d-flex flex-wrap gap-2 mt-2">
        <a href="analytics.php?section=activity<?php echo isset($_GET['lookback_days']) ? '&lookback_days=' . (int)$_GET['lookback_days'] : ''; ?>" class="btn btn-sm <?php echo $section === 'activity' ? 'btn-bb-primary' : 'btn-outline-secondary'; ?>">
            <i class="bi bi-clock-history"></i> Usual customer activity by hour
        </a>
        <a href="analytics.php?section=peak" class="btn btn-sm <?php echo $section === 'peak' ? 'btn-bb-primary' : 'btn-outline-secondary'; ?>">
            <i class="bi bi-graph-up"></i> Peak (today) &amp; Daily Target
        </a>
    </div>
</div>

<?php if ($message): ?>
    <?php $alertClass = $messageType === 'error' ? 'alert-danger' : ($messageType === 'success' ? 'alert-success' : 'alert-info'); $alertIcon = $messageType === 'error' ? 'bi-exclamation-triangle-fill' : ($messageType === 'success' ? 'bi-check-circle-fill' : 'bi-info-circle-fill'); ?>
    <div class="alert <?php echo $alertClass; ?> py-2 small d-flex align-items-center gap-2 mb-3" role="alert"><i class="bi <?php echo $alertIcon; ?>"></i><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($section === 'activity'): ?>
<!-- Section: Usual customer activity by hour (historical) -->
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h5 class="card-title mb-0"><i class="bi bi-clock-history"></i> Usual customer activity by hour</h5>
            <form method="get" class="d-flex align-items-center gap-2">
                <input type="hidden" name="section" value="activity">
                <label class="small text-muted mb-0">Based on</label>
                <select name="lookback_days" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                    <option value="7"   <?php echo $lookbackDays === 7   ? 'selected' : ''; ?>>Last 7 days</option>
                    <option value="30"  <?php echo $lookbackDays === 30  ? 'selected' : ''; ?>>Last 30 days</option>
                    <option value="60"  <?php echo $lookbackDays === 60  ? 'selected' : ''; ?>>Last 60 days</option>
                    <option value="90"  <?php echo $lookbackDays === 90  ? 'selected' : ''; ?>>Last 90 days</option>
                    <option value="365" <?php echo $lookbackDays === 365 ? 'selected' : ''; ?>>Last 12 months</option>
                </select>
            </form>
        </div>
        <p class="small text-muted mb-3">
            Use this to plan when barbers should start, when they can rest, and when you can close. Green = usually busy, gray = usually quiet.
        </p>
        <?php if ($daysInRange === 0): ?>
            <p class="text-muted mb-0">No sales in the selected period. Add sales to see usual activity by hour.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Hour</th>
                        <th class="text-end">Total customers</th>
                        <th class="text-end">Avg per day</th>
                        <th class="text-end">Sales</th>
                        <th style="width: 35%;">Usual activity</th>
                        <th class="text-muted small">Hint</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($historyRows as $r): ?>
                        <?php
                        $isUsuallyBusy = $maxCust > 0 && $r['customers'] >= $busyThreshold && $r['customers'] > 0;
                        $isQuiet = $r['customers'] == 0;
                        $pct = $maxCust > 0 ? ($r['customers'] / $maxCust) * 100 : 0;
                        $hint = '';
                        if ($isUsuallyBusy) $hint = 'Usually busy — good time to have barbers';
                        elseif ($isQuiet) $hint = 'Usually quiet — can rest or consider closing';
                        else $hint = 'Moderate';
                        ?>
                        <tr class="<?php echo $isUsuallyBusy ? 'table-success' : ($isQuiet ? 'table-light' : ''); ?>">
                            <td class="text-muted"><?php echo sprintf('%02d:00', $r['hr']); ?></td>
                            <td class="text-end"><?php echo (int)$r['customers']; ?></td>
                            <td class="text-end"><?php echo number_format((float)$r['avg_per_day'], 1); ?></td>
                            <td class="text-end">₱<?php echo number_format((float)$r['sales'], 2); ?></td>
                            <td>
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar <?php echo $isUsuallyBusy ? 'bg-success' : 'bg-secondary'; ?>" style="width: <?php echo (float)$pct; ?>%"></div>
                                </div>
                            </td>
                            <td class="small text-muted"><?php echo htmlspecialchars($hint); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mt-2 small text-muted">
                Based on <?php echo $daysInRange; ?> day(s) with sales in the last <?php echo $lookbackDays; ?> days.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- Section: Peak (today) & Daily Target Helper -->
<div class="row g-3">
    <div class="col-lg-5">
        <div class="bb-section-card card h-100">
            <div class="card-body">
                <h5 class="bb-section-title mb-3"><i class="bi bi-bullseye"></i> Daily Target Helper</h5>

                <form method="post" class="row g-2 align-items-end mb-3">
                    <input type="hidden" name="action" value="save_target">
                    <div class="col-7">
                        <label class="form-label small">Daily target (₱)</label>
                        <input
                            type="number"
                            name="daily_target"
                            class="form-control form-control-sm"
                            step="0.01"
                            min="0"
                            value="<?php echo htmlspecialchars((string)$dailyTarget); ?>"
                            required
                        >
                    </div>
                    <div class="col-5">
                        <button type="submit" class="btn btn-sm btn-bb-primary w-100"><i class="bi bi-check-lg"></i> Save</button>
                    </div>
                </form>

                <div class="row g-3 small">
                    <div class="col-6">
                        <div class="text-muted text-uppercase mb-1">Target</div>
                        <div class="h6 mb-0">₱<?php echo number_format($dailyTarget ?: 0, 2); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted text-uppercase mb-1">Actual (today)</div>
                        <div class="h6 mb-0">₱<?php echo number_format($todaySales, 2); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted text-uppercase mb-1">Remaining</div>
                        <div class="h6 mb-0">₱<?php echo number_format($remaining, 2); ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted text-uppercase mb-1">Est. haircuts needed</div>
                        <div class="h6 mb-0"><?php echo (int)$estimatedHaircuts; ?></div>
                        <div class="text-muted small">
                            Based on avg service price: ₱<?php echo number_format($avgServicePrice, 2); ?>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span>Target progress</span>
                        <span><?php echo number_format($targetProgress, 0); ?>%</span>
                    </div>
                    <div class="progress" role="progressbar" aria-valuenow="<?php echo (int)$targetProgress; ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" style="width: <?php echo (float)$targetProgress; ?>%"></div>
                    </div>
                    <?php if (($dailyTarget ?: 0) <= 0): ?>
                        <div class="form-text small">Set a target to enable progress calculation.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <h5 class="card-title mb-0">Peak Hour (today)</h5>
                    <div class="small text-muted">
                        Peak: <?php echo $peakHour === null ? '—' : sprintf('%02d:00', $peakHour); ?>
                        (<?php echo (int)$peakCustomers; ?> customer(s))
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Hour</th>
                            <th class="text-end">Customers</th>
                            <th class="text-end">Sales</th>
                            <th style="width: 40%;">Activity</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($hourRows as $r): ?>
                            <?php
                            $isPeak = ($peakHour !== null && $r['hr'] === $peakHour && $peakCustomers > 0);
                            $pct = $peakCustomers > 0 ? ($r['customers'] / $peakCustomers) * 100 : 0;
                            ?>
                            <tr class="<?php echo $isPeak ? 'table-primary' : ''; ?>">
                                <td class="text-muted"><?php echo sprintf('%02d:00', $r['hr']); ?></td>
                                <td class="text-end"><?php echo (int)$r['customers']; ?></td>
                                <td class="text-end">₱<?php echo number_format((float)$r['sales'], 2); ?></td>
                                <td>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar <?php echo $isPeak ? '' : 'bg-secondary'; ?>" style="width: <?php echo (float)$pct; ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'partials/footer.php'; ?>

