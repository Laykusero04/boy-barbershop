<?php
require 'connection.php';
?>
<?php include 'partials/header.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="bb-page-title">Services</h1>
        <p class="bb-page-subtitle">Add and manage services with default prices. Used when recording sales.</p>
    </div>
    <a href="services.php" class="btn btn-sm btn-bb-primary"><i class="bi bi-plus-lg"></i> Add service</a>
</div>

<?php
// Handle create / update / deactivate
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $name = trim($_POST['name'] ?? '');
    $price = (float)($_POST['default_price'] ?? 0);
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;

    if ($name !== '') {
        if ($action === 'update' && $id) {
            $stmt = $pdo->prepare('UPDATE services SET name = ?, default_price = ? WHERE id = ?');
            $stmt->execute([$name, $price, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO services (name, default_price) VALUES (?, ?)');
            $stmt->execute([$name, $price]);
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
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM services WHERE id = ?');
    $stmt->execute([$id]);
    $editService = $stmt->fetch();
}

$services = $pdo->query('SELECT * FROM services ORDER BY is_active DESC, name')->fetchAll();
?>

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
                            <tr>
                                <td><?php echo htmlspecialchars($service['name']); ?></td>
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

