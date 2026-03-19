<?php
// Used by header: expects $pdo, $darkMode. Uses PHP_SELF and GET for active state.
// Optional: set $bb_nav_id_suffix (e.g. '-mob') before include to avoid duplicate IDs when nav is rendered twice.
$bb_nav_id_suffix = isset($bb_nav_id_suffix) ? $bb_nav_id_suffix : '';
$bb_script = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$bb_section = $_GET['section'] ?? '';

$isActive = function ($script, $section = null) use ($bb_script, $bb_section) {
    if ($script !== $bb_script) return false;
    if ($section === null) return true;
    return $section === $bb_section;
};

$navLink = function ($href, $icon, $label, $sub = false) use ($isActive) {
    $script = basename(parse_url($href, PHP_URL_PATH) ?: '');
    $section = null;
    if (strpos($href, 'section=') !== false) {
        parse_str(parse_url($href, PHP_URL_QUERY) ?: '', $q);
        $section = $q['section'] ?? null;
    }
    $active = $script === basename($_SERVER['PHP_SELF'] ?? '') && ($section === null || $section === ($_GET['section'] ?? ''));
    $cls = 'nav-link' . ($sub ? ' bb-nav-sub' : '') . ($active ? ' active' : '');
    echo '<a href="' . htmlspecialchars($href) . '" class="' . $cls . '"><i class="bi ' . $icon . '"></i><span>' . htmlspecialchars($label) . '</span></a>';
};

// Which group contains the current page (to open by default)
$inOperations = in_array($bb_script, ['index.php', 'add_sale.php', 'barbers.php', 'services.php', 'payment_methods.php', 'promos.php'], true);
$inMoney = in_array($bb_script, ['cash_flow.php', 'expenses.php', 'investments.php', 'reports.php'], true);
$inInsights = in_array($bb_script, ['sales_intelligence.php', 'owner_insights.php', 'analytics.php'], true);
$inStock = $bb_script === 'inventory.php';

$showOps = $inOperations ? 'show' : '';
$showMoney = $inMoney ? 'show' : '';
$showInsights = $inInsights ? 'show' : '';
$showStock = $inStock ? 'show' : '';
?>
<nav class="nav flex-column bb-sidebar bb-sidebar-collapse">
    <!-- Operations -->
    <div class="bb-sidebar-group">
        <button class="bb-sidebar-toggler <?php echo $inOperations ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#bb-nav-operations<?php echo $bb_nav_id_suffix; ?>" aria-expanded="<?php echo $inOperations ? 'true' : 'false'; ?>" aria-controls="bb-nav-operations<?php echo $bb_nav_id_suffix; ?>">
            <span class="text-muted text-uppercase fw-semibold small">Operations</span>
            <i class="bi bi-chevron-down bb-sidebar-chevron"></i>
        </button>
        <div class="collapse <?php echo $showOps; ?>" id="bb-nav-operations<?php echo $bb_nav_id_suffix; ?>">
            <?php
            $navLink('index.php', 'bi-house-door', 'Dashboard');
            $navLink('add_sale.php', 'bi-plus-circle', 'Add sale');
            $navLink('barbers.php', 'bi-person-badge', 'Barbers');
            $navLink('services.php', 'bi-scissors', 'Services');
            $navLink('payment_methods.php', 'bi-wallet2', 'Payment methods');
            $navLink('promos.php', 'bi-tag', 'Promos');
            ?>
        </div>
    </div>
    <!-- Money -->
    <div class="bb-sidebar-group">
        <button class="bb-sidebar-toggler <?php echo $inMoney ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#bb-nav-money<?php echo $bb_nav_id_suffix; ?>" aria-expanded="<?php echo $inMoney ? 'true' : 'false'; ?>" aria-controls="bb-nav-money<?php echo $bb_nav_id_suffix; ?>">
            <span class="text-muted text-uppercase fw-semibold small">Money</span>
            <i class="bi bi-chevron-down bb-sidebar-chevron"></i>
        </button>
        <div class="collapse <?php echo $showMoney; ?>" id="bb-nav-money<?php echo $bb_nav_id_suffix; ?>">
            <?php
            $navLink('cash_flow.php', 'bi-cash-stack', 'Cash flow');
            $navLink('expenses.php', 'bi-receipt', 'Expenses');
            $navLink('investments.php', 'bi-piggy-bank', 'Investments');
            $navLink('reports.php', 'bi-file-earmark-text', 'Reports');
            ?>
        </div>
    </div>
    <!-- Insights -->
    <div class="bb-sidebar-group">
        <button class="bb-sidebar-toggler <?php echo $inInsights ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#bb-nav-insights<?php echo $bb_nav_id_suffix; ?>" aria-expanded="<?php echo $inInsights ? 'true' : 'false'; ?>" aria-controls="bb-nav-insights<?php echo $bb_nav_id_suffix; ?>">
            <span class="text-muted text-uppercase fw-semibold small">Insights</span>
            <i class="bi bi-chevron-down bb-sidebar-chevron"></i>
        </button>
        <div class="collapse <?php echo $showInsights; ?>" id="bb-nav-insights<?php echo $bb_nav_id_suffix; ?>">
            <?php
            $navLink('sales_intelligence.php', 'bi-graph-up-arrow', 'Sales Intelligence');
            $navLink('owner_insights.php', 'bi-wallet2', 'Owner pay & insights');
            $navLink('analytics.php?section=activity', 'bi-clock-history', 'Activity (by hour)', true);
            $navLink('analytics.php?section=peak', 'bi-graph-up', 'Peak & Daily Target', true);
            ?>
        </div>
    </div>
    <!-- Stock -->
    <div class="bb-sidebar-group">
        <button class="bb-sidebar-toggler <?php echo $inStock ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#bb-nav-stock<?php echo $bb_nav_id_suffix; ?>" aria-expanded="<?php echo $inStock ? 'true' : 'false'; ?>" aria-controls="bb-nav-stock<?php echo $bb_nav_id_suffix; ?>">
            <span class="text-muted text-uppercase fw-semibold small">Stock</span>
            <i class="bi bi-chevron-down bb-sidebar-chevron"></i>
        </button>
        <div class="collapse <?php echo $showStock; ?>" id="bb-nav-stock<?php echo $bb_nav_id_suffix; ?>">
            <?php $navLink('inventory.php', 'bi-box-seam', 'Inventory'); ?>
        </div>
    </div>
</nav>
