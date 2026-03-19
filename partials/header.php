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

// Breadcrumbs from current script and GET (e.g. analytics.php?section=peak → Analytics > Peak & Daily Target)
function bb_breadcrumbs(): array {
    $script = basename($_SERVER['PHP_SELF'] ?? 'index.php');
    $section = $_GET['section'] ?? '';
    $labels = [
        'index.php' => 'Dashboard',
        'add_sale.php' => 'Add sale',
        'barbers.php' => 'Barbers',
        'services.php' => 'Services',
        'payment_methods.php' => 'Payment methods',
        'promos.php' => 'Promos',
        'cash_flow.php' => 'Cash flow',
        'expenses.php' => 'Expenses',
        'investments.php' => 'Investments',
        'reports.php' => 'Reports',
        'sales_intelligence.php' => 'Sales Intelligence',
        'owner_insights.php' => 'Owner pay & insights',
        'analytics.php' => 'Analytics',
        'inventory.php' => 'Inventory',
    ];
    $pageLabel = $labels[$script] ?? preg_replace('/\.php$/', '', $script);
    if ($script === 'analytics.php' && $section !== '') {
        $sectionLabels = ['activity' => 'Activity', 'peak' => 'Peak & Daily Target'];
        $sectionLabel = $sectionLabels[$section] ?? $section;
        return [
            ['label' => $pageLabel, 'url' => 'analytics.php'],
            ['label' => $sectionLabel, 'url' => null],
        ];
    }
    return [['label' => $pageLabel, 'url' => null]];
}
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
        <button class="btn btn-link btn-lg p-2 d-md-none d-print-none bb-nav-trigger" type="button" data-bs-toggle="offcanvas" data-bs-target="#bb-offcanvas-nav" aria-controls="bb-offcanvas-nav" aria-label="Open menu">
            <i class="bi bi-list"></i>
        </button>
        <div class="bb-logo-wrap">
            <img src="assets/img/logo.png" alt="Boy Barbershop logo" />
            <div class="bb-brand">
                BOY BARBERSHOP
                <span class="text-muted d-none d-sm-inline">Tamnag, Lutayan, Sultan Kudarat</span>
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

<!-- Mobile nav drawer -->
<div class="offcanvas offcanvas-start d-md-none d-print-none" tabindex="-1" id="bb-offcanvas-nav" aria-labelledby="bb-offcanvas-nav-label">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title text-muted text-uppercase fw-semibold small" id="bb-offcanvas-nav-label">Navigation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body pt-0">
        <?php $bb_nav_id_suffix = '-mob'; include __DIR__ . '/sidebar_nav.php'; ?>
    </div>
</div>

<div class="container-fluid bb-shell py-4">
    <div class="row g-3">
        <aside class="col-12 col-md-3 col-lg-2 d-none d-md-block bb-sidebar-aside">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="text-muted text-uppercase fw-semibold mb-3 small">Navigation</h6>
                    <?php include __DIR__ . '/sidebar_nav.php'; ?>
                </div>
            </div>
        </aside>

        <main class="col-12 col-md-9 col-lg-10 bb-main">
            <div class="bb-main-card card border-0 shadow-sm h-100">
                <div class="card-body">
                    <nav aria-label="breadcrumb" class="bb-breadcrumb mb-3">
                        <ol class="breadcrumb mb-0">
                            <?php
                            $crumbs = bb_breadcrumbs();
                            foreach ($crumbs as $i => $c) {
                                $last = $i === count($crumbs) - 1;
                                if ($last || $c['url'] === null) {
                                    echo '<li class="breadcrumb-item active" aria-current="page">' . htmlspecialchars($c['label']) . '</li>';
                                } else {
                                    echo '<li class="breadcrumb-item"><a href="' . htmlspecialchars($c['url']) . '">' . htmlspecialchars($c['label']) . '</a></li>';
                                }
                            }
                            ?>
                        </ol>
                    </nav>
