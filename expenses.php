<?php
require 'connection.php';

// Defaults for filters
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

$message = null;
$messageType = 'info';

// Check if expense_type column exists (for fixed/variable)
$hasExpenseType = false;
try {
    $pdo->query("SELECT expense_type FROM expenses LIMIT 1");
    $hasExpenseType = true;
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $expenseType = trim($_POST['expense_type'] ?? '');
    if ($expenseType !== 'fixed' && $expenseType !== 'variable') {
        $expenseType = null;
    }

    if ($category !== '' && $amount > 0) {
        if ($hasExpenseType) {
            $stmt = $pdo->prepare('
                INSERT INTO expenses (expense_date, category, description, amount, expense_type)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$expenseDate, $category, $description !== '' ? $description : null, $amount, $expenseType]);
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO expenses (expense_date, category, description, amount)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([$expenseDate, $category, $description !== '' ? $description : null, $amount]);
        }
        $message = 'Expense added successfully.';
        $messageType = 'success';
    } else {
        $message = 'Please fill all required fields.';
        $messageType = 'info';
    }
}

// List expenses by date range
$stmt = $pdo->prepare('
    SELECT *
    FROM expenses
    WHERE expense_date BETWEEN ? AND ?
    ORDER BY expense_date DESC, id DESC
');
$stmt->execute([$from, $to]);
$expenses = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT SUM(amount) AS total FROM expenses WHERE expense_date BETWEEN ? AND ?');
$stmt->execute([$from, $to]);
$totals = $stmt->fetch() ?: ['total' => 0];

// --- Expense Intelligence (last 12 months) ---
$periodStart = date('Y-m-d', strtotime('-12 months'));
$categorySummary = [];
$categoryTotalAll = 0;
$stmt = $pdo->prepare("
    SELECT category, SUM(amount) AS total
    FROM expenses
    WHERE expense_date >= ?
    GROUP BY category
    ORDER BY total DESC
");
$stmt->execute([$periodStart]);
while ($row = $stmt->fetch()) {
    $t = (float)$row['total'];
    $categorySummary[] = ['category' => $row['category'], 'total' => $t];
    $categoryTotalAll += $t;
}

// Monthly comparison: this month vs last month (by category)
$thisMonthStart = date('Y-m-01');
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd = date('Y-m-t', strtotime('-1 month'));
$thisMonthByCat = [];
$lastMonthByCat = [];
$stmt = $pdo->prepare("
    SELECT category, SUM(amount) AS total
    FROM expenses
    WHERE expense_date >= ?
    GROUP BY category
");
$stmt->execute([$thisMonthStart]);
while ($row = $stmt->fetch()) {
    $thisMonthByCat[$row['category']] = (float)$row['total'];
}
$stmt = $pdo->prepare("
    SELECT category, SUM(amount) AS total
    FROM expenses
    WHERE expense_date BETWEEN ? AND ?
    GROUP BY category
");
$stmt->execute([$lastMonthStart, $lastMonthEnd]);
while ($row = $stmt->fetch()) {
    $lastMonthByCat[$row['category']] = (float)$row['total'];
}
$monthlyComparison = [];
foreach (array_keys($thisMonthByCat + $lastMonthByCat) as $cat) {
    $thisVal = $thisMonthByCat[$cat] ?? 0;
    $lastVal = $lastMonthByCat[$cat] ?? 0;
    $pctChange = null;
    if ($lastVal > 0) {
        $pctChange = (($thisVal - $lastVal) / $lastVal) * 100;
    } elseif ($thisVal > 0) {
        $pctChange = 100;
    }
    $monthlyComparison[] = [
        'category' => $cat,
        'this_month' => $thisVal,
        'last_month' => $lastVal,
        'pct_change' => $pctChange,
    ];
}
usort($monthlyComparison, function ($a, $b) { return $b['this_month'] <=> $a['this_month']; });

// Fixed vs variable (last 12 months)
$fixedVariable = ['fixed' => 0, 'variable' => 0, 'uncategorized' => 0];
if ($hasExpenseType) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(expense_type, 'uncategorized') AS typ, SUM(amount) AS total
        FROM expenses
        WHERE expense_date >= ?
        GROUP BY typ
    ");
    $stmt->execute([$periodStart]);
    while ($row = $stmt->fetch()) {
        $k = $row['typ'] === 'fixed' ? 'fixed' : ($row['typ'] === 'variable' ? 'variable' : 'uncategorized');
        $fixedVariable[$k] = (float)$row['total'];
    }
}
$fixedVarTotal = $fixedVariable['fixed'] + $fixedVariable['variable'] + $fixedVariable['uncategorized'];

// Average monthly expenses (expected spending) from last 12 months
$avgMonthlyExpenses = 0;
$expenseMonthCount = 0;
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym, SUM(amount) AS total
    FROM expenses
    WHERE expense_date >= ?
    GROUP BY ym
    ORDER BY ym DESC
    LIMIT 12
");
$stmt->execute([$periodStart]);
$monthlyTotals = [];
while ($row = $stmt->fetch()) {
    $monthlyTotals[] = (float)$row['total'];
}
if (!empty($monthlyTotals)) {
    $expenseMonthCount = count($monthlyTotals);
    $avgMonthlyExpenses = array_sum($monthlyTotals) / $expenseMonthCount;
}
?>

<?php include 'partials/header.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="bb-page-title">Expenses</h1>
        <p class="bb-page-subtitle">Track rent, supplies, and other costs. Filter by date range to see totals.</p>
    </div>
    <a href="#bbExpenseForm" class="btn btn-sm btn-bb-primary"><i class="bi bi-plus-lg"></i> Add expense</a>
</div>

<?php if ($message): ?>
    <?php $alertClass = $messageType === 'success' ? 'alert-success' : 'alert-info'; $alertIcon = $messageType === 'success' ? 'bi-check-circle-fill' : 'bi-info-circle-fill'; ?>
    <div class="alert <?php echo $alertClass; ?> py-2 small d-flex align-items-center gap-2 mb-3" role="alert"><i class="bi <?php echo $alertIcon; ?>"></i><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<!-- Expense Intelligence -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h5 class="bb-section-title mb-3"><i class="bi bi-graph-up"></i> Expense intelligence</h5>
        <p class="text-muted small mb-3">Category breakdown, monthly comparison, and fixed vs variable to help control costs. Based on last 12 months unless noted.</p>

        <?php if ($expenseMonthCount > 0): ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-3 rounded bg-warning bg-opacity-10 border border-warning border-opacity-25">
            <span class="small text-muted">Expected monthly expenses (avg):</span>
            <strong class="h5 mb-0">₱<?php echo number_format($avgMonthlyExpenses, 2); ?></strong>
            <span class="small text-muted">from <?php echo $expenseMonthCount; ?> month(s) of data</span>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Categories summary: % of total -->
            <div class="col-md-4">
                <h6 class="text-muted small text-uppercase mb-2">Categories summary</h6>
                <?php if (!$categorySummary): ?>
                    <p class="text-muted small mb-0">No expenses in the last 12 months.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0 small">
                        <?php foreach ($categorySummary as $row): ?>
                            <?php
                            $pct = $categoryTotalAll > 0 ? ($row['total'] / $categoryTotalAll) * 100 : 0;
                            ?>
                            <li class="d-flex justify-content-between align-items-center py-1 border-bottom border-secondary border-opacity-25">
                                <span><?php echo htmlspecialchars($row['category']); ?></span>
                                <span>₱<?php echo number_format($row['total'], 0); ?> <span class="text-muted">(<?php echo number_format($pct, 1); ?>%)</span></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="mt-2 pt-2 border-top small fw-semibold">Total: ₱<?php echo number_format($categoryTotalAll, 2); ?></div>
                <?php endif; ?>
            </div>

            <!-- Monthly comparison: "Utilities increased by 20%" -->
            <div class="col-md-4">
                <h6 class="text-muted small text-uppercase mb-2">This month vs last month</h6>
                <?php if (!$monthlyComparison): ?>
                    <p class="text-muted small mb-0">No expenses this month or last month.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0 small">
                        <?php foreach ($monthlyComparison as $row): ?>
                            <?php
                            if ($row['this_month'] == 0 && $row['last_month'] == 0) continue;
                            $pct = $row['pct_change'];
                            $label = '';
                            if ($pct !== null) {
                                $label = $pct >= 0 ? ('+' . number_format($pct, 0) . '%') : (number_format($pct, 0) . '%');
                                $label = ' <span class="text-muted">(' . $label . ' vs last month)</span>';
                            }
                            ?>
                            <li class="d-flex justify-content-between align-items-center py-1 border-bottom border-secondary border-opacity-25">
                                <span><?php echo htmlspecialchars($row['category']); ?></span>
                                <span>₱<?php echo number_format($row['this_month'], 0); ?><?php echo $label; ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Fixed vs variable -->
            <div class="col-md-4">
                <h6 class="text-muted small text-uppercase mb-2">Fixed vs variable</h6>
                <?php if (!$hasExpenseType): ?>
                    <p class="text-muted small mb-0">Run <code>files/sql/expense_intelligence.sql</code> to track fixed vs variable expenses.</p>
                <?php elseif ($fixedVarTotal <= 0): ?>
                    <p class="text-muted small mb-0">Tag expenses as Fixed or Variable when adding to see the breakdown.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0 small">
                        <?php
                        $pctFixed = $fixedVarTotal > 0 ? ($fixedVariable['fixed'] / $fixedVarTotal) * 100 : 0;
                        $pctVar = $fixedVarTotal > 0 ? ($fixedVariable['variable'] / $fixedVarTotal) * 100 : 0;
                        $pctUncat = $fixedVarTotal > 0 ? ($fixedVariable['uncategorized'] / $fixedVarTotal) * 100 : 0;
                        ?>
                        <li class="d-flex justify-content-between align-items-center py-1 border-bottom border-secondary border-opacity-25">
                            <span>Fixed</span>
                            <span>₱<?php echo number_format($fixedVariable['fixed'], 0); ?> <span class="text-muted">(<?php echo number_format($pctFixed, 1); ?>%)</span></span>
                        </li>
                        <li class="d-flex justify-content-between align-items-center py-1 border-bottom border-secondary border-opacity-25">
                            <span>Variable</span>
                            <span>₱<?php echo number_format($fixedVariable['variable'], 0); ?> <span class="text-muted">(<?php echo number_format($pctVar, 1); ?>%)</span></span>
                        </li>
                        <?php if ($fixedVariable['uncategorized'] > 0): ?>
                        <li class="d-flex justify-content-between align-items-center py-1 border-bottom border-secondary border-opacity-25">
                            <span>Uncategorized</span>
                            <span>₱<?php echo number_format($fixedVariable['uncategorized'], 0); ?> <span class="text-muted">(<?php echo number_format($pctUncat, 1); ?>%)</span></span>
                        </li>
                        <?php endif; ?>
                    </ul>
                    <div class="mt-2 pt-2 border-top small fw-semibold">Total: ₱<?php echo number_format($fixedVarTotal, 2); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="bb-section-card card" id="bbExpenseForm">
            <div class="card-body">
                <h5 class="bb-section-title mb-3"><i class="bi bi-receipt"></i> Add expense</h5>
                <form method="post" class="vstack gap-3">
                    <div>
                        <label class="form-label small">Date</label>
                        <input type="date" name="expense_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
                    </div>
                    <div>
                        <label class="form-label small">Category</label>
                        <input type="text" name="category" class="form-control form-control-sm" placeholder="Supplies, Rent, etc." required>
                    </div>
                    <div>
                        <label class="form-label small">Description</label>
                        <input type="text" name="description" class="form-control form-control-sm" placeholder="Optional">
                    </div>
                    <div>
                        <label class="form-label small">Amount</label>
                        <input type="number" name="amount" class="form-control form-control-sm" step="0.01" min="0" required>
                        <div class="form-text small">Enter 0 or more. Negative values are invalid.</div>
                    </div>
                    <?php if ($hasExpenseType): ?>
                    <div>
                        <label class="form-label small">Type</label>
                        <select name="expense_type" class="form-select form-select-sm">
                            <option value="">— Optional —</option>
                            <option value="fixed">Fixed</option>
                            <option value="variable">Variable</option>
                        </select>
                        <div class="form-text small">Fixed = rent, salaries; Variable = supplies, utilities.</div>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-sm btn-bb-primary"><i class="bi bi-check-lg"></i> Save expense</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="bb-section-card card">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h5 class="bb-section-title mb-0"><i class="bi bi-list-ul"></i> Expenses list</h5>
                    <span class="fw-semibold">Total: ₱<?php echo number_format($totals['total'] ?: 0, 2); ?></span>
                </div>

                <form method="get" class="row g-2 align-items-end mb-3">
                    <div class="col-sm-4">
                        <label class="form-label small">From</label>
                        <input type="date" name="from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($from); ?>">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">To</label>
                        <input type="date" name="to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($to); ?>">
                    </div>
                    <div class="col-sm-4 d-flex gap-2">
                        <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-funnel"></i> Filter</button>
                        <a class="btn btn-sm btn-outline-secondary" href="expenses.php">Reset</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Category</th>
                            <?php if ($hasExpenseType): ?><th class="text-center">Type</th><?php endif; ?>
                            <th>Description</th>
                            <th class="text-end">Amount</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$expenses): ?>
                            <tr>
                                <td colspan="<?php echo $hasExpenseType ? 5 : 4; ?>" class="p-0">
                                    <div class="bb-empty"><i class="bi bi-receipt"></i><p>No expenses for this date range.</p></div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($expenses as $exp): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($exp['expense_date']); ?></td>
                                    <td><?php echo htmlspecialchars($exp['category']); ?></td>
                                    <?php if ($hasExpenseType): ?>
                                    <td class="text-center">
                                        <?php
                                        $et = $exp['expense_type'] ?? '';
                                        if ($et === 'fixed') echo '<span class="badge bg-primary-subtle text-primary">Fixed</span>';
                                        elseif ($et === 'variable') echo '<span class="badge bg-info-subtle text-info">Variable</span>';
                                        else echo '<span class="text-muted">—</span>';
                                        ?>
                                    </td>
                                    <?php endif; ?>
                                    <td class="text-muted"><?php echo htmlspecialchars($exp['description'] ?? ''); ?></td>
                                    <td class="text-end">₱<?php echo number_format($exp['amount'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>

