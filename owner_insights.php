<?php
require 'connection.php';

// --- Insight preferences (same as dashboard) ---
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
    header('Location: owner_insights.php');
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

// Total set aside for the business (investments)
$totalInvestment = 0;
try {
    $stmt = $pdo->query('SELECT SUM(cost) AS total FROM investments');
    $totalInvestment = (float)($stmt->fetch()['total'] ?? 0);
} catch (Throwable $e) {
    $totalInvestment = 0;
}

// All-time profit = money the business has made (toward recovering investment)
$allProfit = 0;
try {
    $allSales = (float)($pdo->query('SELECT SUM(price) AS total FROM sales')->fetch()['total'] ?? 0);
    $allShare = (float)($pdo->query('
        SELECT SUM(s.price * (b.percentage_share / 100)) AS total
        FROM sales s JOIN barbers b ON s.barber_id = b.id
    ')->fetch()['total'] ?? 0);
    $allExpenses = (float)($pdo->query('SELECT SUM(amount) AS total FROM expenses')->fetch()['total'] ?? 0);
    $allProfit = $allSales - $allShare - $allExpenses;
} catch (Throwable $e) {
    $allProfit = 0;
}

// --- Insights: last 12 months (sales, share, expenses, profit) ---
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

$insightMonthCount = count($insightsMonths);
$avgMonthlyProfit = 0;
$suggestedMonthlyOwnerPay = 0;
$suggestedDailyOwnerPay = 0;
$suggestedMonthlyBusinessSavings = 0;  // amount to set aside for the business (rest of profit after owner %)
$suggestedDailyBusinessSavings = 0;
$paybackFormatted = null;
$goalRequiredProfit = null;
$goalRequiredSales = null;

if ($insightMonthCount > 0) {
    $avgMonthlyProfit = array_sum(array_column($insightsMonths, 'profit')) / $insightMonthCount;
    $avgMonthlySales = array_sum(array_column($insightsMonths, 'sales')) / $insightMonthCount;
    $suggestedMonthlyOwnerPay = max(0, $avgMonthlyProfit * ($ownerPayPercent / 100));
    $suggestedDailyOwnerPay = $suggestedMonthlyOwnerPay / 30;
    // Rest of profit = for the business
    $businessSharePercent = max(0, 100 - $ownerPayPercent);
    $suggestedMonthlyBusinessSavings = max(0, $avgMonthlyProfit * ($businessSharePercent / 100));
    $suggestedDailyBusinessSavings = $suggestedMonthlyBusinessSavings / 30;

    if ($totalInvestment > 0 && $avgMonthlyProfit > 0) {
        $totalMonths = $totalInvestment / $avgMonthlyProfit;
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
        if ($years > 0) $parts[] = $years . ' ' . ($years === 1 ? 'year' : 'years');
        if ($months > 0) $parts[] = $months . ' ' . ($months === 1 ? 'month' : 'months');
        if ($days > 0 || empty($parts)) $parts[] = $days . ' ' . ($days === 1 ? 'day' : 'days');
        $paybackFormatted = implode(', ', $parts);
    }

    if ($insightTargetYears >= 1 && $totalInvestment > 0) {
        $monthsToTarget = $insightTargetYears * 12;
        $goalRequiredProfit = $totalInvestment / $monthsToTarget;
        if ($avgMonthlyProfit > 0 && $avgMonthlySales > 0) {
            $profitMarginRatio = $avgMonthlyProfit / $avgMonthlySales;
            $goalRequiredSales = $profitMarginRatio > 0 ? $goalRequiredProfit / $profitMarginRatio : null;
        }
    }
}
?>
<?php include 'partials/header.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="bb-page-title">Owner pay &amp; insights</h1>
        <p class="bb-page-subtitle">What you've set aside for the business and your suggested payout (monthly and daily).</p>
    </div>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-house"></i> Dashboard</a>
</div>

<!-- What you've set aside for the business -->
<div class="bb-section-card card mb-4">
    <div class="card-body">
        <h5 class="bb-section-title mb-3"><i class="bi bi-piggy-bank"></i> Set aside for the business</h5>
        <p class="text-muted small mb-3">Total capital you've put into the business (from <a href="investments.php">Investments</a>).</p>
        <div class="p-4 rounded bb-insight-card bb-insight-card-neutral">
            <div class="small text-muted mb-1">Total investment</div>
            <div class="h3 mb-0">₱<?php echo number_format($totalInvestment, 2); ?></div>
            <a href="investments.php" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-plus-lg"></i> Add or view investments</a>
        </div>
    </div>
</div>

<!-- How much to save for the business (daily basis + amount saved so far) -->
<div class="bb-section-card card mb-4">
    <div class="card-body">
        <h5 class="bb-section-title mb-3"><i class="bi bi-bank"></i> Save for the business</h5>
        <p class="text-muted small mb-3">After barber share and your owner pay, this is how much to set aside for the business so you know how much is &quot;business money&quot; and how much you&apos;ve saved toward recovering your investment.</p>

        <!-- Amount saved so far (the number behind "progress to getting your money back") -->
        <div class="p-4 rounded bg-primary bg-opacity-10 border border-primary border-opacity-25 mb-3">
            <div class="small text-muted mb-1">Amount saved for the business so far</div>
            <div class="h4 mb-1 text-primary">₱<?php echo number_format($allProfit, 2); ?></div>
            <div class="small text-muted">Total net profit (sales − barber share − expenses). This is the money toward getting your investment back.</div>
            <?php if ($totalInvestment > 0): ?>
                <?php
                $roiProgress = $totalInvestment > 0 ? min(100, max(0, ($allProfit / $totalInvestment) * 100)) : 0;
                ?>
                <div class="mt-2">
                    <div class="d-flex justify-content-between small mb-1">
                        <span>Progress to getting your money back</span>
                        <span><?php echo number_format($roiProgress, 0); ?>%</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-primary" style="width: <?php echo (float)$roiProgress; ?>%"></div>
                    </div>
                    <div class="small text-muted mt-1">₱<?php echo number_format($allProfit, 2); ?> of ₱<?php echo number_format($totalInvestment, 2); ?> investment</div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($insightMonthCount > 0): ?>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="p-4 rounded bb-insight-card bb-insight-card-neutral">
                    <div class="small text-muted mb-1">Put this much aside each day</div>
                    <div class="h4 mb-0">₱<?php echo number_format($suggestedDailyBusinessSavings, 2); ?></div>
                    <div class="small text-muted">Daily amount to save for the business</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-4 rounded bb-insight-card bb-insight-card-neutral">
                    <div class="small text-muted mb-1">Put this much aside each month</div>
                    <div class="h4 mb-0">₱<?php echo number_format($suggestedMonthlyBusinessSavings, 2); ?></div>
                    <div class="small text-muted">Monthly amount for the business</div>
                </div>
            </div>
        </div>
        <div class="p-3 rounded bg-light bg-opacity-50 mt-3 small text-muted">
            <strong>Basis:</strong> From each day&apos;s profit (after barbers and expenses), <?php echo (int)$ownerPayPercent; ?>% is suggested for you (owner pay) and <?php echo (int)max(0, 100 - $ownerPayPercent); ?>% is for the business. Use the daily number as your guide for how much to keep as business money.
        </div>
        <?php else: ?>
            <p class="mb-0 text-muted">Record more <a href="add_sale.php">sales</a> and <a href="expenses.php">expenses</a> to see suggested daily/monthly amounts.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Your suggested payout: monthly + daily -->
<div class="bb-section-card card mb-4">
    <div class="card-body">
        <h5 class="bb-section-title mb-3"><i class="bi bi-wallet2"></i> Your suggested payout</h5>
        <p class="text-muted small mb-3">Based on the last <?php echo $insightMonthCount; ?> month(s) of profit. <?php echo (int)$ownerPayPercent; ?>% of average monthly net profit.</p>

        <?php if ($insightMonthCount === 0): ?>
            <p class="mb-0 text-muted">Record more <a href="add_sale.php">sales</a> and <a href="expenses.php">expenses</a> to see suggested pay here.</p>
        <?php else: ?>
            <div class="row g-3 mb-0">
                <div class="col-md-6">
                    <div class="p-4 rounded bg-success bg-opacity-10 border border-success border-opacity-25">
                        <div class="small text-muted mb-1">Suggested monthly owner pay</div>
                        <div class="h4 mb-0 text-success">₱<?php echo number_format($suggestedMonthlyOwnerPay, 2); ?></div>
                        <div class="small text-muted"><?php echo (int)$ownerPayPercent; ?>% of avg monthly profit</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="p-4 rounded bg-primary bg-opacity-10 border border-primary border-opacity-25">
                        <div class="small text-muted mb-1">Suggested daily owner pay</div>
                        <div class="h4 mb-0 text-primary">₱<?php echo number_format($suggestedDailyOwnerPay, 2); ?></div>
                        <div class="small text-muted">Monthly ÷ 30 days (for planning)</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recover investment -->
<div class="bb-section-card card mb-4">
    <div class="card-body">
        <h5 class="bb-section-title mb-3"><i class="bi bi-graph-up-arrow"></i> Recover your investment</h5>
        <?php if ($paybackFormatted !== null): ?>
            <div class="p-4 rounded bg-primary bg-opacity-10 border border-primary border-opacity-25 mb-3">
                <div class="small text-muted mb-1">Estimated time to recover investment</div>
                <div class="h4 mb-0 text-primary">~<?php echo htmlspecialchars($paybackFormatted); ?></div>
                <div class="small text-muted">At current average profit rate</div>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">Add <a href="investments.php">investments</a> and build profit history to see an estimate here.</p>
        <?php endif; ?>

        <?php if ($insightTargetYears >= 1 && $goalRequiredProfit !== null): ?>
            <div class="p-3 rounded bb-insight-card bb-insight-card-neutral">
                <div class="small text-muted mb-1">To recover in <strong><?php echo $insightTargetYears; ?> year<?php echo $insightTargetYears === 1 ? '' : 's'; ?></strong> you need:</div>
                <ul class="mb-0 small">
                    <li><strong>₱<?php echo number_format($goalRequiredProfit, 2); ?></strong> net profit per month</li>
                    <?php if ($goalRequiredSales !== null): ?>
                    <li>About <strong>₱<?php echo number_format($goalRequiredSales, 2); ?></strong> sales per month (at your current margin)</li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Customize (same as dashboard) -->
<?php if ($insightMonthCount > 0): ?>
<div class="bb-section-card card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h5 class="bb-section-title mb-0"><i class="bi bi-gear"></i> Customize</h5>
        </div>
        <p class="text-muted small mb-3">Change how owner pay and recovery goals are calculated. These settings also apply on the dashboard.</p>
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
                    <div class="form-text small">Set 0 to hide goal</div>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-sm btn-bb-primary"><i class="bi bi-check-lg"></i> Save</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Placeholder for future features -->
<div class="bb-section-card card mb-4 border-secondary border-opacity-25">
    <div class="card-body">
        <h5 class="bb-section-title mb-2"><i class="bi bi-plus-circle"></i> More coming</h5>
        <p class="text-muted small mb-0">This page can grow with features like: owner withdrawal history, weekly view, or savings goals.</p>
    </div>
</div>

<?php include 'partials/footer.php'; ?>
