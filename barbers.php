<?php
require 'connection.php';

// Handle create / update / deactivate (before any output so redirect works)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'deactivate' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $pdo->prepare('UPDATE barbers SET is_active = 0 WHERE id = ?')->execute([$id]);
        header('Location: barbers.php');
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $percentage = (float)($_POST['percentage_share'] ?? 0);
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;

    if ($name !== '') {
        if ($action === 'update' && $id) {
            $stmt = $pdo->prepare('UPDATE barbers SET name = ?, percentage_share = ? WHERE id = ?');
            $stmt->execute([$name, $percentage, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO barbers (name, percentage_share) VALUES (?, ?)');
            $stmt->execute([$name, $percentage]);
        }
    }

    header('Location: barbers.php');
    exit;
}

$editBarber = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM barbers WHERE id = ?');
    $stmt->execute([$id]);
    $editBarber = $stmt->fetch();
}

$barbers = $pdo->query('SELECT * FROM barbers ORDER BY is_active DESC, name')->fetchAll();
?>
<?php include 'partials/header.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="bb-page-title">Barbers</h1>
        <p class="bb-page-subtitle">Manage barbers and their percentage share.</p>
    </div>
    <a href="#bbBarberForm" class="btn btn-sm btn-bb-primary"><i class="bi bi-person-plus"></i> Add barber</a>
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="bb-section-card card" id="bbBarberForm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h5 class="bb-section-title mb-1">
                            <i class="bi bi-person-badge"></i>
                            <?php echo $editBarber ? 'Edit barber' : 'Add barber'; ?>
                        </h5>
                        <p class="bb-section-subtitle mb-0">
                            <?php echo $editBarber ? 'Update the barber details.' : 'Create a new barber profile.'; ?>
                        </p>
                    </div>
                    <?php if ($editBarber): ?>
                        <span class="badge bg-warning text-dark">Editing</span>
                    <?php endif; ?>
                </div>
                <form method="post" class="vstack gap-3">
                    <input type="hidden" name="action" value="<?php echo $editBarber ? 'update' : 'create'; ?>">
                    <?php if ($editBarber): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($editBarber['id']); ?>">
                    <?php endif; ?>
                    <div>
                        <label class="form-label small">Name</label>
                        <input
                            type="text"
                            name="name"
                            class="form-control form-control-sm"
                            required
                            value="<?php echo $editBarber ? htmlspecialchars($editBarber['name']) : ''; ?>"
                        >
                    </div>
                    <div>
                        <label class="form-label small">Percentage share (%)</label>
                        <input
                            type="number"
                            name="percentage_share"
                            class="form-control form-control-sm"
                            step="0.01"
                            min="0"
                            max="100"
                            required
                            value="<?php echo $editBarber ? htmlspecialchars($editBarber['percentage_share']) : '0'; ?>"
                        >
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-bb-primary">
                            <?php echo $editBarber ? 'Save changes' : 'Add barber'; ?>
                        </button>
                        <?php if ($editBarber): ?>
                            <a href="barbers.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
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
                    <div>
                        <h5 class="bb-section-title mb-0"><i class="bi bi-list-ul"></i> Barbers list</h5>
                        <p class="bb-section-subtitle mb-0">Active barbers appear first.</p>
                    </div>
                    <span class="text-muted small">Total: <span class="fw-semibold"><?php echo count($barbers); ?></span></span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th class="text-end">Share %</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($barbers as $barber): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($barber['name']); ?></div>
                                    <div class="text-muted small">ID: <?php echo (int)$barber['id']; ?></div>
                                </td>
                                <td class="text-end"><?php echo htmlspecialchars($barber['percentage_share']); ?></td>
                                <td class="text-center">
                                    <?php if ($barber['is_active']): ?>
                                        <span class="badge bb-badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge bb-badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="barbers.php?edit=<?php echo $barber['id']; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> Edit</a>
                                    <?php if ($barber['is_active']): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#bbDeactivateBarberModal" data-id="<?php echo (int)$barber['id']; ?>" data-name="<?php echo htmlspecialchars($barber['name']); ?>"><i class="bi bi-person-x"></i> Deactivate</button>
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

<!-- Deactivate barber confirmation -->
<div class="modal fade" id="bbDeactivateBarberModal" tabindex="-1" aria-labelledby="bbDeactivateBarberModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="bbDeactivateBarberModalLabel"><i class="bi bi-person-x text-danger me-1"></i> Deactivate barber?</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-2 small">
                <p class="mb-0" id="bbDeactivateBarberDesc">They won't appear in new sales.</p>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="post" id="bbDeactivateBarberForm" class="d-inline">
                    <input type="hidden" name="action" value="deactivate">
                    <input type="hidden" name="id" id="bbDeactivateBarberId">
                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-person-x"></i> Deactivate</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('bbDeactivateBarberModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget;
        if (!btn) return;
        var id = btn.getAttribute('data-id');
        var name = btn.getAttribute('data-name');
        document.getElementById('bbDeactivateBarberId').value = id || '';
        var desc = document.getElementById('bbDeactivateBarberDesc');
        if (desc) desc.textContent = name ? ('Deactivate ' + name + '? They won\'t appear in new sales.') : 'They won\'t appear in new sales.';
    });
})();
</script>

<?php include 'partials/footer.php'; ?>

