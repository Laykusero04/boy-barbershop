<?php
require_once __DIR__ . '/../connection.php';

function bb_get_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    try {
        $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function bb_set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('
        INSERT INTO settings (`key`, `value`)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ');
    $stmt->execute([$key, $value]);
}

// Dark mode toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_dark_mode') {
    $next = ($_POST['dark_mode'] ?? '0') === '1' ? '1' : '0';
    try {
        bb_set_setting($pdo, 'dark_mode', $next);
    } catch (Throwable $e) {
        // ignore if settings table not installed yet
    }
    $back = $_SERVER['HTTP_REFERER'] ?? 'index.php';
    header('Location: ' . $back);
    exit;
}

$darkMode = (bb_get_setting($pdo, 'dark_mode', '0') ?? '0') === '1';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?php echo $darkMode ? 'dark' : 'light'; ?>">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Boy Barbershop</title>
    <link rel="icon" href="assets/img/logo.png" type="image/png" />

    <!-- Bootstrap CSS -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    />

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/style.css" />
</head>
<body class="<?php echo $darkMode ? 'bb-dark' : ''; ?>">
<nav class="navbar bb-navbar border-bottom bg-body-tertiary">
    <div class="container-fluid bb-navbar-inner py-2">
        <div class="bb-logo-wrap">
            <img src="assets/img/logo.png" alt="Boy Barbershop logo" />
            <div class="bb-brand">
                BOY BARBERSHOP
                <span class="text-muted">Tamnag, Lutayan, Sultan Kudarat</span>
            </div>
        </div>

        <form method="post" class="bb-theme-toggle ms-auto">
            <input type="hidden" name="action" value="toggle_dark_mode">
            <input type="hidden" name="dark_mode" value="<?php echo $darkMode ? '0' : '1'; ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary" aria-label="<?php echo $darkMode ? 'Switch to light mode' : 'Switch to dark mode'; ?>">
                <i class="bi <?php echo $darkMode ? 'bi-sun-fill' : 'bi-moon-fill'; ?>"></i>
                <span class="d-none d-sm-inline ms-1"><?php echo $darkMode ? 'Light' : 'Dark'; ?></span>
            </button>
        </form>
    </div>
</nav>

<div class="container-fluid bb-shell py-4">
    <div class="row g-3">
        <aside class="col-12 col-md-3 col-lg-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="text-muted text-uppercase fw-semibold mb-3 small">
                        Navigation
                    </h6>
                    <nav class="nav flex-column bb-sidebar">
                        <span class="text-muted text-uppercase fw-semibold small">Menu</span>
                        <a href="index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : ''; ?>">
                            <i class="bi bi-house-door"></i><span>Dashboard</span>
                        </a>
                        <a href="add_sale.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'add_sale.php' ? 'active' : ''; ?>">
                            <i class="bi bi-plus-circle"></i><span>Add sale</span>
                        </a>
                        <a href="barbers.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'barbers.php' ? 'active' : ''; ?>">
                            <i class="bi bi-person-badge"></i><span>Barbers</span>
                        </a>
                        <a href="services.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'services.php' ? 'active' : ''; ?>">
                            <i class="bi bi-scissors"></i><span>Services</span>
                        </a>
                        <a href="payment_methods.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'payment_methods.php' ? 'active' : ''; ?>">
                            <i class="bi bi-wallet2"></i><span>Payment methods</span>
                        </a>
                        <a href="promos.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'promos.php' ? 'active' : ''; ?>">
                            <i class="bi bi-tag"></i><span>Promos</span>
                        </a>
                        <a href="cash_flow.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'cash_flow.php' ? 'active' : ''; ?>">
                            <i class="bi bi-cash-stack"></i><span>Cash flow</span>
                        </a>
                        <a href="expenses.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'expenses.php' ? 'active' : ''; ?>">
                            <i class="bi bi-receipt"></i><span>Expenses</span>
                        </a>
                        <a href="investments.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'investments.php' ? 'active' : ''; ?>">
                            <i class="bi bi-piggy-bank"></i><span>Investments</span>
                        </a>
                        <a href="reports.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'reports.php' ? 'active' : ''; ?>">
                            <i class="bi bi-file-earmark-text"></i><span>Reports</span>
                        </a>
                        <span class="text-muted text-uppercase fw-semibold small mt-2">Insights</span>
                        <a href="sales_intelligence.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'sales_intelligence.php' ? 'active' : ''; ?>">
                            <i class="bi bi-graph-up-arrow"></i><span>Sales Intelligence</span>
                        </a>
                        <span class="text-muted text-uppercase fw-semibold small mt-2">Owner</span>
                        <a href="owner_insights.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'owner_insights.php' ? 'active' : ''; ?>">
                            <i class="bi bi-wallet2"></i><span>Owner pay &amp; insights</span>
                        </a>
                        <span class="text-muted text-uppercase fw-semibold small mt-2">Peak</span>
                        <a href="analytics.php?section=activity" class="nav-link bb-nav-sub <?php echo (basename($_SERVER['PHP_SELF']) === 'analytics.php' && ($_GET['section'] ?? '') === 'activity') ? 'active' : ''; ?>">
                            <i class="bi bi-clock-history"></i><span>Usual customer activity by hour</span>
                        </a>
                        <a href="analytics.php?section=peak" class="nav-link bb-nav-sub <?php echo (basename($_SERVER['PHP_SELF']) === 'analytics.php' && ($_GET['section'] ?? 'peak') !== 'activity') ? 'active' : ''; ?>">
                            <i class="bi bi-graph-up"></i><span>Peak (today) &amp; Daily Target</span>
                        </a>
                        <a href="inventory.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'inventory.php' ? 'active' : ''; ?>">
                            <i class="bi bi-box-seam"></i><span>Inventory</span>
                        </a>
                    </nav>
                </div>
            </div>
        </aside>

        <main class="col-12 col-md-9 col-lg-10 bb-main">
            <div class="bb-main-card card border-0 shadow-sm h-100">
                <div class="card-body">
