<?php
require 'connection.php';

// Defaults for filters
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);

    if ($category !== '' && $amount > 0) {
        $stmt = $pdo->prepare('
            INSERT INTO expenses (expense_date, category, description, amount)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$expenseDate, $category, $description !== '' ? $description : null, $amount]);
        $message = 'Expense added successfully.';
    } else {
        $message = 'Please fill all required fields.';
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
?>

<?php include 'partials/header.php'; ?>

<div class="mb-4">
    <h1 class="bb-page-title">Expenses</h1>
    <p class="bb-page-subtitle">Track rent, supplies, and other costs. Filter by date range to see totals.</p>
</div>

<?php if ($message): ?>
    <div class="alert alert-info py-2 small d-flex align-items-center gap-2 mb-3"><i class="bi bi-check-circle-fill"></i><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-md-4">
        <div class="bb-section-card card">
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
                    </div>
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
                            <th>Description</th>
                            <th class="text-end">Amount</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$expenses): ?>
                            <tr>
                                <td colspan="4" class="p-0">
                                    <div class="bb-empty"><i class="bi bi-receipt"></i><p>No expenses for this date range.</p></div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($expenses as $exp): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($exp['expense_date']); ?></td>
                                    <td><?php echo htmlspecialchars($exp['category']); ?></td>
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

