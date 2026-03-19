<?php
require 'connection.php';

$promosTableExists = false;
try {
    $pdo->query('SELECT 1 FROM promos LIMIT 1');
    $promosTableExists = true;
} catch (Throwable $e) {
}

if (!$promosTableExists) {
    include 'partials/header.php';
    echo '<div class="mb-4"><h1 class="bb-page-title">Promos</h1><p class="bb-page-subtitle">Run the SQL migration first to enable promos.</p></div>';
    echo '<div class="alert alert-warning"><strong>Setup required.</strong> Run <code>files/sql/promos.sql</code> in phpMyAdmin or: <code>mysql -u root boy_barbershop &lt; files/sql/promos.sql</code></div>';
    include 'partials/footer.php';
    exit;
}

// Handle create / update / deactivate
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'deactivate' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $pdo->prepare('UPDATE promos SET is_active = 0 WHERE id = ?')->execute([$id]);
        header('Location: promos.php');
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $promoType = $_POST['promo_type'] ?? '';
    if (!in_array($promoType, ['percent_off', 'amount_off', 'free'], true)) {
        $promoType = 'percent_off';
    }
    $value = (float)($_POST['value'] ?? 0);
    if ($promoType === 'free') {
        $value = 100; // store 100% off
    }
    $validFrom = trim($_POST['valid_from'] ?? '');
    $validTo = trim($_POST['valid_to'] ?? '');
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;

    if ($name !== '' && $validFrom !== '' && $validTo !== '') {
        if ($action === 'update' && $id) {
            $stmt = $pdo->prepare('UPDATE promos SET name = ?, promo_type = ?, value = ?, valid_from = ?, valid_to = ? WHERE id = ?');
            $stmt->execute([$name, $promoType, $value, $validFrom, $validTo, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO promos (name, promo_type, value, valid_from, valid_to) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $promoType, $value, $validFrom, $validTo]);
        }
        header('Location: promos.php');
        exit;
    }
}

if (isset($_GET['activate'])) {
    $id = (int)$_GET['activate'];
    $pdo->prepare('UPDATE promos SET is_active = 1 WHERE id = ?')->execute([$id]);
    header('Location: promos.php');
    exit;
}

$editPromo = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM promos WHERE id = ?');
    $stmt->execute([$id]);
    $editPromo = $stmt->fetch();
}

$promos = $pdo->query('SELECT * FROM promos ORDER BY is_active DESC, valid_to DESC, name')->fetchAll();
?>
<?php include 'partials/header.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="bb-page-title">Promos</h1>
        <p class="bb-page-subtitle">Create date-bound discounts (percent off, amount off, or free). Select at Add sale to apply.</p>
    </div>
    <a href="#bbPromoForm" class="btn btn-sm btn-bb-primary"><i class="bi bi-plus-lg"></i> Add promo</a>
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="bb-section-card card" id="bbPromoForm">
            <div class="card-body">
                <h5 class="bb-section-title mb-3">
                    <i class="bi bi-tag"></i>
                    <?php echo $editPromo ? 'Edit promo' : 'Add promo'; ?>
                </h5>
                <form method="post" class="vstack gap-3">
                    <input type="hidden" name="action" value="<?php echo $editPromo ? 'update' : 'create'; ?>">
                    <?php if ($editPromo): ?>
                        <input type="hidden" name="id" value="<?php echo (int)$editPromo['id']; ?>">
                    <?php endif; ?>
                    <div>
                        <label class="form-label small">Promo name</label>
                        <input type="text" name="name" class="form-control form-control-sm" required placeholder="e.g. March 30% off"
                            value="<?php echo $editPromo ? htmlspecialchars($editPromo['name']) : ''; ?>">
                    </div>
                    <div>
                        <label class="form-label small">Type</label>
                        <select name="promo_type" id="bbPromoType" class="form-select form-select-sm" required>
                            <option value="percent_off" <?php echo ($editPromo && $editPromo['promo_type'] === 'percent_off') ? 'selected' : ''; ?>>Percent off</option>
                            <option value="amount_off" <?php echo ($editPromo && $editPromo['promo_type'] === 'amount_off') ? 'selected' : ''; ?>>Amount off (₱)</option>
                            <option value="free" <?php echo ($editPromo && $editPromo['promo_type'] === 'free') ? 'selected' : ''; ?>>Free</option>
                        </select>
                    </div>
                    <div id="bbPromoValueWrap">
                        <label class="form-label small">Value</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bb-promo-value-prefix">%</span>
                            <input type="number" name="value" id="bbPromoValue" class="form-control" step="0.01" min="0" max="100"
                                value="<?php echo $editPromo ? htmlspecialchars($editPromo['promo_type'] === 'free' ? '100' : $editPromo['value']) : ''; ?>"
                                placeholder="<?php echo ($editPromo && $editPromo['promo_type'] === 'amount_off') ? '50' : '30'; ?>">
                        </div>
                        <div class="form-text small">Percent off (0–100) or fixed amount in ₱.</div>
                    </div>
                    <div>
                        <label class="form-label small">Valid from</label>
                        <input type="date" name="valid_from" class="form-control form-control-sm" required
                            value="<?php echo $editPromo ? htmlspecialchars($editPromo['valid_from']) : date('Y-m-d'); ?>">
                    </div>
                    <div>
                        <label class="form-label small">Valid to</label>
                        <input type="date" name="valid_to" class="form-control form-control-sm" required
                            value="<?php echo $editPromo ? htmlspecialchars($editPromo['valid_to']) : date('Y-m-t'); ?>">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-bb-primary">
                            <?php echo $editPromo ? 'Save changes' : 'Add promo'; ?>
                        </button>
                        <?php if ($editPromo): ?>
                            <a href="promos.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="bb-section-card card">
            <div class="card-body">
                <h5 class="bb-section-title mb-3"><i class="bi bi-list-ul"></i> Promos list</h5>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th class="text-end">Value</th>
                            <th>Valid period</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($promos)): ?>
                            <tr>
                                <td colspan="6" class="p-0">
                                    <div class="bb-empty"><i class="bi bi-tag"></i><p>No promos yet. Add one to use at Add sale.</p></div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($promos as $p): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($p['name']); ?></td>
                                    <td>
                                        <?php
                                        $typeLabel = $p['promo_type'] === 'percent_off' ? 'Percent off' : ($p['promo_type'] === 'amount_off' ? 'Amount off' : 'Free');
                                        echo htmlspecialchars($typeLabel);
                                        ?>
                                    </td>
                                    <td class="text-end">
                                        <?php
                                        if ($p['promo_type'] === 'free') {
                                            echo '—';
                                        } elseif ($p['promo_type'] === 'amount_off') {
                                            echo '₱' . number_format((float)$p['value'], 2);
                                        } else {
                                            echo number_format((float)$p['value'], 0) . '%';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($p['valid_from'] . ' → ' . $p['valid_to']); ?></td>
                                    <td class="text-center">
                                        <?php if ($p['is_active']): ?>
                                            <span class="badge bb-badge-active">Active</span>
                                        <?php else: ?>
                                            <span class="badge bb-badge-inactive">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="promos.php?edit=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> Edit</a>
                                        <?php if ($p['is_active']): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#bbDeactivatePromoModal" data-id="<?php echo (int)$p['id']; ?>" data-name="<?php echo htmlspecialchars($p['name']); ?>">Deactivate</button>
                                        <?php else: ?>
                                            <a href="promos.php?activate=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-outline-success">Activate</a>
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

<script>
(function () {
    var typeSelect = document.getElementById('bbPromoType');
    var valueWrap = document.getElementById('bbPromoValueWrap');
    var valueInput = document.getElementById('bbPromoValue');
    var prefix = valueWrap && valueWrap.querySelector('.bb-promo-value-prefix');
    if (!typeSelect || !valueWrap) return;

    function updateValueLabel() {
        var t = typeSelect.value;
        if (t === 'free') {
            valueWrap.style.display = 'none';
            if (valueInput) valueInput.value = '100';
        } else {
            valueWrap.style.display = 'block';
            if (prefix) prefix.textContent = t === 'amount_off' ? '₱' : '%';
            if (valueInput) {
                valueInput.max = t === 'percent_off' ? '100' : '99999';
                valueInput.required = true;
            }
        }
    }
    typeSelect.addEventListener('change', updateValueLabel);
    updateValueLabel();
})();
</script>

<!-- Deactivate promo confirmation -->
<div class="modal fade" id="bbDeactivatePromoModal" tabindex="-1" aria-labelledby="bbDeactivatePromoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="bbDeactivatePromoModalLabel"><i class="bi bi-tag text-danger me-1"></i> Deactivate promo?</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-2 small">
                <p class="mb-0" id="bbDeactivatePromoDesc">It won't appear at Add sale.</p>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="deactivate">
                    <input type="hidden" name="id" id="bbDeactivatePromoId">
                    <button type="submit" class="btn btn-sm btn-danger">Deactivate</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('bbDeactivatePromoModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget;
        if (!btn) return;
        var id = btn.getAttribute('data-id');
        var name = btn.getAttribute('data-name');
        document.getElementById('bbDeactivatePromoId').value = id || '';
        var desc = document.getElementById('bbDeactivatePromoDesc');
        if (desc) desc.textContent = name ? ('Deactivate "' + name + '"? It won\'t appear at Add sale.') : 'It won\'t appear at Add sale.';
    });
})();
</script>

<?php include 'partials/footer.php'; ?>
