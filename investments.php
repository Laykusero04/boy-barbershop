<?php
require 'connection.php';

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemName = trim($_POST['item_name'] ?? '');
    $cost = (float) str_replace(',', '', $_POST['cost'] ?? '');
    $investmentDate = trim($_POST['investment_date'] ?? '');

    if ($itemName !== '' && $cost > 0) {
        $stmt = $pdo->prepare('
            INSERT INTO investments (item_name, cost, investment_date)
            VALUES (?, ?, ?)
        ');
        $stmt->execute([
            $itemName,
            $cost,
            $investmentDate !== '' ? $investmentDate : null,
        ]);
        $message = 'Investment item added successfully.';
    } else {
        $message = 'Please fill all required fields.';
    }
}

$items = [];
$totalInvestment = 0;
try {
    $items = $pdo->query('
        SELECT id, item_name, cost, investment_date, created_at
        FROM investments
        ORDER BY COALESCE(investment_date, DATE(created_at)) DESC, id DESC
    ')->fetchAll();

    $totalInvestment = (float)($pdo->query('SELECT SUM(cost) AS total FROM investments')->fetch()['total'] ?? 0);
} catch (Throwable $e) {
    $items = [];
    $totalInvestment = 0;
}
?>

<?php include 'partials/header.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="bb-page-title">Investments</h1>
        <p class="bb-page-subtitle">Record equipment and capital. Used for ROI on the dashboard.</p>
    </div>
    <div class="fw-semibold">Total: ₱<?php echo number_format($totalInvestment, 2, '.', ','); ?></div>
</div>

<?php if ($message): ?>
    <div class="alert alert-info py-2 small d-flex align-items-center gap-2 mb-3"><i class="bi bi-check-circle-fill"></i><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-md-4">
        <div class="bb-section-card card">
            <div class="card-body">
                <h5 class="bb-section-title mb-3"><i class="bi bi-piggy-bank"></i> Add investment item</h5>
                <form method="post" class="vstack gap-3">
                    <div>
                        <label class="form-label small">Item name</label>
                        <input type="text" name="item_name" class="form-control form-control-sm" required placeholder="Chair, Clippers, Mirror...">
                    </div>
                    <div>
                        <label class="form-label small">Cost</label>
                        <input type="text" name="cost" id="bbInvestmentCost" class="form-control form-control-sm" inputmode="decimal" placeholder="Cost" required autocomplete="off">
                    </div>
                    <div>
                        <label class="form-label small">Date (optional)</label>
                        <input type="date" name="investment_date" class="form-control form-control-sm">
                    </div>
                    <button type="submit" class="btn btn-sm btn-bb-primary"><i class="bi bi-check-lg"></i> Save investment</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="bb-section-card card">
            <div class="card-body">
                <h5 class="bb-section-title mb-3"><i class="bi bi-list-ul"></i> All investment items</h5>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Item</th>
                            <th class="text-end">Cost</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$items): ?>
                            <tr>
                                <td colspan="3" class="p-0">
                                    <div class="bb-empty"><i class="bi bi-piggy-bank"></i><p>No investments added yet.</p></div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $it): ?>
                                <tr>
                                    <td class="text-muted">
                                        <?php
                                        $d = $it['investment_date'] ?: date('Y-m-d', strtotime($it['created_at']));
                                        echo htmlspecialchars($d);
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($it['item_name']); ?></td>
                                    <td class="text-end">₱<?php echo number_format((float)$it['cost'], 2, '.', ','); ?></td>
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

<script>
(function () {
    var costInput = document.getElementById('bbInvestmentCost');
    if (!costInput) return;

    function formatWithCommas(val) {
        var s = String(val).replace(/,/g, '');
        var parts = s.split('.');
        parts[0] = parts[0].replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return parts.length > 1 ? parts[0] + '.' + parts[1].replace(/\D/g, '').slice(0, 2) : parts[0];
    }

    costInput.addEventListener('input', function () {
        this.value = formatWithCommas(this.value);
    });

    costInput.addEventListener('blur', function () {
        if (this.value) this.value = formatWithCommas(this.value);
    });

    costInput.form.addEventListener('submit', function () {
        costInput.value = costInput.value.replace(/,/g, '');
    });
})();
</script>

<?php include 'partials/footer.php'; ?>

