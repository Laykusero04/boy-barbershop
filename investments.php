<?php
require 'connection.php';

$message = null;
$messageType = 'info';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPageOptions = [20, 50];
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 20;
}
$sort = (string)($_GET['sort'] ?? 'date_desc');
$sortMap = [
    'date_desc' => 'COALESCE(investment_date, DATE(created_at)) DESC, id DESC',
    'date_asc' => 'COALESCE(investment_date, DATE(created_at)) ASC, id ASC',
    'cost_desc' => 'cost DESC, id DESC',
    'cost_asc' => 'cost ASC, id ASC',
    'item_asc' => 'item_name ASC, id DESC',
];
if (!isset($sortMap[$sort])) {
    $sort = 'date_desc';
}
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editItem = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'create');
    $itemName = trim($_POST['item_name'] ?? '');
    $cost = (float) str_replace(',', '', $_POST['cost'] ?? '');
    $investmentDate = trim($_POST['investment_date'] ?? '');
    $targetId = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $targetId > 0) {
        $stmt = $pdo->prepare('DELETE FROM investments WHERE id = ?');
        $stmt->execute([$targetId]);
        $message = 'Investment item deleted.';
        $messageType = 'success';
    } elseif (($action === 'update' || $action === 'create') && $itemName !== '' && $cost > 0) {
        if ($action === 'update' && $targetId > 0) {
            $stmt = $pdo->prepare('
                UPDATE investments
                SET item_name = ?, cost = ?, investment_date = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $itemName,
                $cost,
                $investmentDate !== '' ? $investmentDate : null,
                $targetId,
            ]);
            $message = 'Investment item updated.';
        } else {
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
        }
        $messageType = 'success';
    } elseif ($action !== 'delete') {
        $message = 'Please fill all required fields.';
        $messageType = 'info';
    }
}

$items = [];
$totalInvestment = 0;
try {
    $countStmt = $pdo->query('SELECT COUNT(*) AS total_rows FROM investments');
    $totalRows = (int)($countStmt->fetch()['total_rows'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $orderSql = $sortMap[$sort];
    $stmt = $pdo->prepare('
        SELECT id, item_name, cost, investment_date, created_at
        FROM investments
        ORDER BY ' . $orderSql . '
        LIMIT ? OFFSET ?
    ');
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();

    $totalInvestment = (float)($pdo->query('SELECT SUM(cost) AS total FROM investments')->fetch()['total'] ?? 0);

    if ($editId > 0) {
        $editStmt = $pdo->prepare('SELECT id, item_name, cost, investment_date FROM investments WHERE id = ?');
        $editStmt->execute([$editId]);
        $editItem = $editStmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    $items = [];
    $totalInvestment = 0;
    $totalRows = 0;
    $totalPages = 1;
    $offset = 0;
}
?>

<?php include 'partials/header.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="bb-page-title">Investments</h1>
        <p class="bb-page-subtitle">Record equipment and capital. Used for ROI on the dashboard.</p>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="text-muted small">Total: ₱<?php echo number_format($totalInvestment, 2, '.', ','); ?></span>
        <a href="#bbInvestmentForm" class="btn btn-sm btn-bb-primary"><i class="bi bi-plus-lg"></i> Add investment</a>
    </div>
</div>

<?php if ($message): ?>
    <?php $alertClass = $messageType === 'success' ? 'alert-success' : 'alert-info'; $alertIcon = $messageType === 'success' ? 'bi-check-circle-fill' : 'bi-info-circle-fill'; ?>
    <div class="alert <?php echo $alertClass; ?> py-2 small d-flex align-items-center gap-2 mb-3" role="alert"><i class="bi <?php echo $alertIcon; ?>"></i><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-md-4">
        <div class="bb-section-card card" id="bbInvestmentForm">
            <div class="card-body">
                <h5 class="bb-section-title mb-3"><i class="bi bi-piggy-bank"></i> <?php echo $editItem ? 'Edit investment item' : 'Add investment item'; ?></h5>
                <form method="post" class="vstack gap-3">
                    <input type="hidden" name="action" value="<?php echo $editItem ? 'update' : 'create'; ?>">
                    <?php if ($editItem): ?>
                        <input type="hidden" name="id" value="<?php echo (int)$editItem['id']; ?>">
                    <?php endif; ?>
                    <div>
                        <label class="form-label small">Item name</label>
                        <input type="text" name="item_name" class="form-control form-control-sm" required placeholder="Chair, Clippers, Mirror..." value="<?php echo htmlspecialchars((string)($editItem['item_name'] ?? '')); ?>">
                    </div>
                    <div>
                        <label class="form-label small">Cost</label>
                        <input type="number" name="cost" id="bbInvestmentCost" class="form-control form-control-sm" step="0.01" min="0" placeholder="Cost" required autocomplete="off" value="<?php echo $editItem ? htmlspecialchars((string)$editItem['cost']) : ''; ?>">
                        <div class="form-text small">Enter amount; commas are added automatically. Negative values are invalid.</div>
                    </div>
                    <div>
                        <label class="form-label small">Date (optional)</label>
                        <input type="date" name="investment_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars((string)($editItem['investment_date'] ?? '')); ?>">
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-sm btn-bb-primary"><i class="bi bi-check-lg"></i> <?php echo $editItem ? 'Save changes' : 'Save investment'; ?></button>
                        <?php if ($editItem): ?>
                            <a href="investments.php?<?php echo htmlspecialchars(http_build_query(['sort' => $sort, 'per_page' => $perPage, 'page' => $page])); ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i> Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="bb-section-card card">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h5 class="bb-section-title mb-0"><i class="bi bi-list-ul"></i> All investment items</h5>
                    <form method="get" class="d-flex flex-wrap align-items-center gap-2">
                        <input type="hidden" name="page" value="1">
                        <label class="small text-muted">Sort</label>
                        <select name="sort" class="form-select form-select-sm">
                            <option value="date_desc"<?php echo $sort === 'date_desc' ? ' selected' : ''; ?>>Date (newest)</option>
                            <option value="date_asc"<?php echo $sort === 'date_asc' ? ' selected' : ''; ?>>Date (oldest)</option>
                            <option value="cost_desc"<?php echo $sort === 'cost_desc' ? ' selected' : ''; ?>>Cost (high to low)</option>
                            <option value="cost_asc"<?php echo $sort === 'cost_asc' ? ' selected' : ''; ?>>Cost (low to high)</option>
                            <option value="item_asc"<?php echo $sort === 'item_asc' ? ' selected' : ''; ?>>Item (A-Z)</option>
                        </select>
                        <label class="small text-muted">Rows</label>
                        <select name="per_page" class="form-select form-select-sm">
                            <option value="20"<?php echo $perPage === 20 ? ' selected' : ''; ?>>20</option>
                            <option value="50"<?php echo $perPage === 50 ? ' selected' : ''; ?>>50</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Item</th>
                            <th class="text-end">Cost</th>
                            <th class="text-end" style="width:1%;">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$items): ?>
                            <tr>
                                <td colspan="4" class="p-0">
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
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-secondary" href="investments.php?<?php echo htmlspecialchars(http_build_query(['edit' => (int)$it['id'], 'sort' => $sort, 'per_page' => $perPage, 'page' => $page])); ?>" title="Edit" aria-label="Edit investment"><i class="bi bi-pencil"></i></a>
                                        <form method="post" class="d-inline ms-1" onsubmit="return confirm('Delete this investment item? This cannot be undone.');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int)$it['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete" aria-label="Delete investment"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalRows > $perPage): ?>
                    <?php
                    $prevParams = ['sort' => $sort, 'per_page' => $perPage, 'page' => max(1, $page - 1)];
                    $nextParams = ['sort' => $sort, 'per_page' => $perPage, 'page' => min($totalPages, $page + 1)];
                    ?>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                        <span class="text-muted small">
                            Showing <?php echo $totalRows > 0 ? ($offset + 1) : 0; ?>-<?php echo min($offset + count($items), $totalRows); ?> of <?php echo $totalRows; ?>
                        </span>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Investments pagination">
                            <a class="btn btn-outline-secondary<?php echo $page <= 1 ? ' disabled' : ''; ?>" href="investments.php?<?php echo htmlspecialchars(http_build_query($prevParams)); ?>">Prev</a>
                            <span class="btn btn-outline-secondary disabled">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span>
                            <a class="btn btn-outline-secondary<?php echo $page >= $totalPages ? ' disabled' : ''; ?>" href="investments.php?<?php echo htmlspecialchars(http_build_query($nextParams)); ?>">Next</a>
                        </div>
                    </div>
                <?php endif; ?>
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

