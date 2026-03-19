<?php
require 'connection.php';

// Handle create / update / deactivate - redirect before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'deactivate' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        try {
            $pdo->prepare('UPDATE payment_methods SET is_active = 0 WHERE id = ?')->execute([$id]);
        } catch (Throwable $e) {
            // table may not exist yet
        }
        header('Location: payment_methods.php');
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;

    if ($name !== '') {
        if ($action === 'update' && $id) {
            $stmt = $pdo->prepare('UPDATE payment_methods SET name = ? WHERE id = ?');
            $stmt->execute([$name, $id]);
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO payment_methods (name) VALUES (?)');
                $stmt->execute([$name]);
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    // duplicate name
                }
            }
        }
    }

    header('Location: payment_methods.php');
    exit;
}

$editMethod = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    try {
        $stmt = $pdo->prepare('SELECT * FROM payment_methods WHERE id = ?');
        $stmt->execute([$id]);
        $editMethod = $stmt->fetch();
    } catch (Throwable $e) {
        $editMethod = null;
    }
}

$paymentMethods = [];
try {
    $paymentMethods = $pdo->query('SELECT * FROM payment_methods ORDER BY is_active DESC, id')->fetchAll();
} catch (Throwable $e) {
    $paymentMethods = [];
}
?>
<?php include 'partials/header.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="bb-page-title">Payment methods</h1>
        <p class="bb-page-subtitle">Manage payment options shown in Add Sale. Active methods appear in the dropdown.</p>
    </div>
    <a href="#bbPaymentMethodForm" class="btn btn-sm btn-bb-primary"><i class="bi bi-plus-lg"></i> Add payment method</a>
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="bb-section-card card" id="bbPaymentMethodForm">
            <div class="card-body">
                <h5 class="bb-section-title mb-3">
                    <i class="bi bi-wallet2"></i>
                    <?php echo $editMethod ? 'Edit payment method' : 'Add payment method'; ?>
                </h5>
                <form method="post" class="vstack gap-3">
                    <input type="hidden" name="action" value="<?php echo $editMethod ? 'update' : 'create'; ?>">
                    <?php if ($editMethod): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($editMethod['id']); ?>">
                    <?php endif; ?>
                    <div>
                        <label class="form-label small">Name</label>
                        <input
                            type="text"
                            name="name"
                            class="form-control form-control-sm"
                            required
                            placeholder="Cash, GCash, Maya..."
                            value="<?php echo $editMethod ? htmlspecialchars($editMethod['name']) : ''; ?>"
                        >
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-bb-primary">
                            <?php echo $editMethod ? 'Save changes' : 'Add payment method'; ?>
                        </button>
                        <?php if ($editMethod): ?>
                            <a href="payment_methods.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="bb-section-card card">
            <div class="card-body">
                <h5 class="bb-section-title mb-3"><i class="bi bi-list-ul"></i> Payment methods list</h5>
                <?php if (empty($paymentMethods)): ?>
                    <div class="bb-empty">
                        <i class="bi bi-wallet2"></i>
                        <p>No payment methods yet. Run <code>files/schema_phase7.sql</code> then add methods here.</p>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($paymentMethods as $pm): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pm['name']); ?></td>
                                <td class="text-center">
                                    <?php if ($pm['is_active']): ?>
                                        <span class="badge bb-badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge bb-badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="payment_methods.php?edit=<?php echo $pm['id']; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> Edit</a>
                                    <?php if ($pm['is_active']): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#bbDeactivatePaymentMethodModal" data-id="<?php echo (int)$pm['id']; ?>" data-name="<?php echo htmlspecialchars($pm['name']); ?>"><i class="bi bi-x-circle"></i> Deactivate</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Deactivate payment method confirmation -->
<div class="modal fade" id="bbDeactivatePaymentMethodModal" tabindex="-1" aria-labelledby="bbDeactivatePaymentMethodModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="bbDeactivatePaymentMethodModalLabel"><i class="bi bi-x-circle text-danger me-1"></i> Deactivate payment method?</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-2 small">
                <p class="mb-0" id="bbDeactivatePaymentMethodDesc">It won't appear at Add sale.</p>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="deactivate">
                    <input type="hidden" name="id" id="bbDeactivatePaymentMethodId">
                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-x-circle"></i> Deactivate</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('bbDeactivatePaymentMethodModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget;
        if (!btn) return;
        var id = btn.getAttribute('data-id');
        var name = btn.getAttribute('data-name');
        document.getElementById('bbDeactivatePaymentMethodId').value = id || '';
        var desc = document.getElementById('bbDeactivatePaymentMethodDesc');
        if (desc) desc.textContent = name ? ('Deactivate "' + name + '"? It won\'t appear at Add sale.') : 'It won\'t appear at Add sale.';
    });
})();
</script>

<?php include 'partials/footer.php'; ?>
