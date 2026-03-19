<?php
require 'connection.php';

$message = null;
$messageType = 'info';

// Handle create/update/deactivate
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'deactivate' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $pdo->prepare('UPDATE inventory_items SET is_active = 0 WHERE id = ?')->execute([$id]);
        header('Location: inventory.php');
        exit;
    }

    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $itemName = trim($_POST['item_name'] ?? '');
    $stockQty = (int)($_POST['stock_qty'] ?? 0);
    $threshold = (int)($_POST['low_stock_threshold'] ?? 5);
    $unit = trim($_POST['unit'] ?? '');

    if ($itemName === '') {
        $message = 'Item name is required.';
        $messageType = 'error';
    } else {
        if ($threshold < 0) $threshold = 0;
        if ($stockQty < 0) $stockQty = 0;

        if ($action === 'update' && $id) {
            $stmt = $pdo->prepare('
                UPDATE inventory_items
                SET item_name = ?, stock_qty = ?, low_stock_threshold = ?, unit = ?
                WHERE id = ?
            ');
            $stmt->execute([$itemName, $stockQty, $threshold, ($unit !== '' ? $unit : null), $id]);
            $message = 'Item updated.';
            $messageType = 'success';
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO inventory_items (item_name, stock_qty, low_stock_threshold, unit)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([$itemName, $stockQty, $threshold, ($unit !== '' ? $unit : null)]);
            $message = 'Item added.';
            $messageType = 'success';
        }
    }
}

// Edit
$editItem = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM inventory_items WHERE id = ?');
    $stmt->execute([$id]);
    $editItem = $stmt->fetch();
}

$items = $pdo->query('SELECT * FROM inventory_items ORDER BY is_active DESC, item_name')->fetchAll();
?>

<?php include 'partials/header.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="bb-page-title">Inventory</h1>
        <p class="bb-page-subtitle">Track stock and see low-stock alerts. Set a threshold per item.</p>
    </div>
    <a href="#bbInventoryForm" class="btn btn-sm btn-bb-primary"><i class="bi bi-plus-lg"></i> Add item</a>
</div>

<?php if ($message): ?>
    <?php $alertClass = $messageType === 'error' ? 'alert-danger' : ($messageType === 'success' ? 'alert-success' : 'alert-info'); $alertIcon = $messageType === 'error' ? 'bi-exclamation-triangle-fill' : ($messageType === 'success' ? 'bi-check-circle-fill' : 'bi-info-circle-fill'); ?>
    <div class="alert <?php echo $alertClass; ?> py-2 small d-flex align-items-center gap-2 mb-3" role="alert"><i class="bi <?php echo $alertIcon; ?>"></i><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-md-4">
        <div class="bb-section-card card" id="bbInventoryForm">
            <div class="card-body">
                <h5 class="bb-section-title mb-3"><i class="bi bi-box-seam"></i> <?php echo $editItem ? 'Edit item' : 'Add item'; ?></h5>
                <form method="post" class="vstack gap-3">
                    <input type="hidden" name="action" value="<?php echo $editItem ? 'update' : 'create'; ?>">
                    <?php if ($editItem): ?>
                        <input type="hidden" name="id" value="<?php echo (int)$editItem['id']; ?>">
                    <?php endif; ?>

                    <div>
                        <label class="form-label small">Item name</label>
                        <input type="text" name="item_name" class="form-control form-control-sm" required
                               value="<?php echo htmlspecialchars($editItem['item_name'] ?? ''); ?>"
                               placeholder="Shampoo, Gel, Blade...">
                    </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small">Stock</label>
                            <input type="number" name="stock_qty" class="form-control form-control-sm" min="0" required
                                   value="<?php echo htmlspecialchars((string)($editItem['stock_qty'] ?? 0)); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Unit (optional)</label>
                            <input type="text" name="unit" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars((string)($editItem['unit'] ?? '')); ?>"
                                   placeholder="pcs, bottles...">
                        </div>
                    </div>

                    <div>
                        <label class="form-label small">Low-stock threshold</label>
                        <input type="number" name="low_stock_threshold" class="form-control form-control-sm" min="0" required
                               value="<?php echo htmlspecialchars((string)($editItem['low_stock_threshold'] ?? 5)); ?>">
                        <div class="form-text small">Item is marked low when stock is ≤ threshold.</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-bb-primary" type="submit">
                            <?php echo $editItem ? 'Save changes' : 'Add item'; ?>
                        </button>
                        <?php if ($editItem): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="inventory.php">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="card-title mb-3">Items</h5>
                <div class="table-responsive small">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-end">Stock</th>
                            <th class="text-end">Low threshold</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$items): ?>
                            <tr>
                                <td colspan="5" class="p-0">
                                    <div class="bb-empty"><i class="bi bi-box-seam"></i><p>No inventory items yet.</p></div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $it): ?>
                                <?php
                                $stock = (int)$it['stock_qty'];
                                $thr = (int)$it['low_stock_threshold'];
                                $isLow = $it['is_active'] && ($stock <= $thr);
                                ?>
                                <tr class="<?php echo $isLow ? 'table-warning' : ''; ?>">
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($it['item_name']); ?></div>
                                        <?php if (!empty($it['unit'])): ?>
                                            <div class="text-muted small"><?php echo htmlspecialchars($it['unit']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php echo $stock; ?>
                                    </td>
                                    <td class="text-end"><?php echo $thr; ?></td>
                                    <td class="text-center">
                                        <?php if (!$it['is_active']): ?>
                                            <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                                        <?php elseif ($isLow): ?>
                                            <span class="badge bg-warning text-dark">Low</span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success">OK</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-secondary" href="inventory.php?edit=<?php echo (int)$it['id']; ?>"><i class="bi bi-pencil"></i> Edit</a>
                                        <?php if ($it['is_active']): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#bbDeactivateInventoryModal" data-id="<?php echo (int)$it['id']; ?>" data-name="<?php echo htmlspecialchars($it['item_name']); ?>"><i class="bi bi-x-circle"></i> Deactivate</button>
                                        <?php endif; ?>
                                    </td>
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

<!-- Deactivate inventory item confirmation -->
<div class="modal fade" id="bbDeactivateInventoryModal" tabindex="-1" aria-labelledby="bbDeactivateInventoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="bbDeactivateInventoryModalLabel"><i class="bi bi-x-circle text-danger me-1"></i> Deactivate item?</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-2 small">
                <p class="mb-0" id="bbDeactivateInventoryDesc">It won't appear in inventory lists.</p>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="deactivate">
                    <input type="hidden" name="id" id="bbDeactivateInventoryId">
                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-x-circle"></i> Deactivate</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('bbDeactivateInventoryModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget;
        if (!btn) return;
        var id = btn.getAttribute('data-id');
        var name = btn.getAttribute('data-name');
        document.getElementById('bbDeactivateInventoryId').value = id || '';
        var desc = document.getElementById('bbDeactivateInventoryDesc');
        if (desc) desc.textContent = name ? ('Deactivate "' + name + '"? It won\'t appear in inventory lists.') : 'It won\'t appear in inventory lists.';
    });
})();
</script>

<?php include 'partials/footer.php'; ?>

