<?php
require 'connection.php';

// Load active inventory items (for usage-per-service section)
$inventoryItems = [];
$usageCountByService = [];
try {
    $inventoryItems = $pdo->query('SELECT id, item_name, unit FROM inventory_items WHERE is_active = 1 ORDER BY item_name')->fetchAll();
    $usageCountByService = $pdo->query('SELECT service_id, COUNT(*) AS cnt FROM service_inventory_usage GROUP BY service_id')->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) {
    // service_inventory_usage table may not exist yet
    $usageCountByService = [];
}

// Handle create / update / deactivate (before any output so redirect works)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $name = trim($_POST['name'] ?? '');
    $price = (float)($_POST['default_price'] ?? 0);
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;

    if ($name !== '') {
        if ($action === 'update' && $id) {
            $stmt = $pdo->prepare('UPDATE services SET name = ?, default_price = ? WHERE id = ?');
            $stmt->execute([$name, $price, $id]);
            $serviceId = $id;
        } else {
            $stmt = $pdo->prepare('INSERT INTO services (name, default_price) VALUES (?, ?)');
            $stmt->execute([$name, $price]);
            $serviceId = (int)$pdo->lastInsertId();
        }

        // Replace "usage per service" mapping (only if table exists and we have a valid service id)
        if ($serviceId && !empty($inventoryItems)) {
            try {
                $pdo->prepare('DELETE FROM service_inventory_usage WHERE service_id = ?')->execute([$serviceId]);
                $usage = $_POST['usage'] ?? [];
                $insStmt = $pdo->prepare('INSERT INTO service_inventory_usage (service_id, inventory_item_id, quantity_per_service) VALUES (?, ?, ?)');
                foreach ($inventoryItems as $item) {
                    $itemId = (int)$item['id'];
                    $qty = isset($usage[$itemId]) ? (float)$usage[$itemId] : 0;
                    if ($qty > 0) {
                        $insStmt->execute([$serviceId, $itemId, $qty]);
                    }
                }
            } catch (Throwable $e) {
                // ignore if table missing
            }
        }
    }

    header('Location: services.php');
    exit;
}

if (isset($_GET['deactivate'])) {
    $id = (int)$_GET['deactivate'];
    $pdo->prepare('UPDATE services SET is_active = 0 WHERE id = ?')->execute([$id]);
    header('Location: services.php');
    exit;
}

$editService = null;
$usageByItem = []; // inventory_item_id => quantity_per_service (when editing)
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM services WHERE id = ?');
    $stmt->execute([$id]);
    $editService = $stmt->fetch();
    if ($editService) {
        try {
            $rows = $pdo->prepare('SELECT inventory_item_id, quantity_per_service FROM service_inventory_usage WHERE service_id = ?');
            $rows->execute([$editService['id']]);
            while ($row = $rows->fetch()) {
                $usageByItem[(int)$row['inventory_item_id']] = (float)$row['quantity_per_service'];
            }
        } catch (Throwable $e) {
            // table may not exist
        }
    }
}

$services = $pdo->query('SELECT * FROM services ORDER BY is_active DESC, name')->fetchAll();
?>
<?php include 'partials/header.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="bb-page-title">Services</h1>
        <p class="bb-page-subtitle">Add and manage services with default prices. Used when recording sales.</p>
    </div>
    <a href="services.php" class="btn btn-sm btn-bb-primary"><i class="bi bi-plus-lg"></i> Add service</a>
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="bb-section-card card">
            <div class="card-body">
                <h5 class="bb-section-title mb-3">
                    <i class="bi bi-scissors"></i>
                    <?php echo $editService ? 'Edit service' : 'Add service'; ?>
                </h5>
                <form method="post" class="vstack gap-3">
                    <input type="hidden" name="action" value="<?php echo $editService ? 'update' : 'create'; ?>">
                    <?php if ($editService): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($editService['id']); ?>">
                    <?php endif; ?>
                    <div>
                        <label class="form-label small">Service name</label>
                        <input
                            type="text"
                            name="name"
                            class="form-control form-control-sm"
                            required
                            value="<?php echo $editService ? htmlspecialchars($editService['name']) : ''; ?>"
                        >
                    </div>
                    <div>
                        <label class="form-label small">Default price</label>
                        <input
                            type="number"
                            name="default_price"
                            class="form-control form-control-sm"
                            step="0.01"
                            min="0"
                            required
                            value="<?php echo $editService ? htmlspecialchars($editService['default_price']) : '0'; ?>"
                        >
                    </div>
                    <?php if (!empty($inventoryItems)): ?>
                    <div>
                        <label class="form-label small"><i class="bi bi-box-seam text-muted me-1"></i> Inventory used per service</label>
                        <p class="form-text small mb-2">Quantity of each item consumed per service (e.g. 1 blade, 2g pomade). Leave 0 or empty if not used.</p>
                        <div class="vstack gap-2">
                            <?php foreach ($inventoryItems as $item): ?>
                                <?php $qty = $usageByItem[(int)$item['id']] ?? ''; ?>
                                <div class="d-flex align-items-center gap-2">
                                    <label class="small text-muted mb-0 flex-grow-1"><?php echo htmlspecialchars($item['item_name']); ?><?php if (!empty($item['unit'])): ?> <span class="text-muted">(<?php echo htmlspecialchars($item['unit']); ?>)</span><?php endif; ?></label>
                                    <input type="number" name="usage[<?php echo (int)$item['id']; ?>]" class="form-control form-control-sm" min="0" step="0.001" style="width: 6rem;" value="<?php echo $qty !== '' ? htmlspecialchars((string)$qty) : ''; ?>" placeholder="0">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-bb-primary">
                            <?php echo $editService ? 'Save changes' : 'Add service'; ?>
                        </button>
                        <?php if ($editService): ?>
                            <a href="services.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="bb-section-card card">
            <div class="card-body">
                <h5 class="bb-section-title mb-3"><i class="bi bi-list-ul"></i> Services list</h5>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th class="text-end">Default price</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($services as $service): ?>
                            <?php $usageCount = (int)($usageCountByService[$service['id']] ?? 0); ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($service['name']); ?>
                                    <?php if ($usageCount > 0): ?>
                                        <span class="badge bg-secondary-subtle text-secondary ms-1" title="Inventory items used per service">Uses <?php echo $usageCount; ?> item<?php echo $usageCount === 1 ? '' : 's'; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php echo number_format($service['default_price'], 2); ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($service['is_active']): ?>
                                        <span class="badge bb-badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge bb-badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="services.php?edit=<?php echo $service['id']; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> Edit</a>
                                    <?php if ($service['is_active']): ?>
                                        <a href="services.php?deactivate=<?php echo $service['id']; ?>" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Deactivate</a>
                                    <?php endif; ?>
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

<?php include 'partials/footer.php'; ?>

