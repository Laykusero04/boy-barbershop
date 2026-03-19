<?php
require 'connection.php';

// Period for "over time" trend (months)
$trendMonths = 12;
$periodStart = date('Y-m-d', strtotime("-{$trendMonths} months"));

// --- Total revenue (all-time and for trend period) ---
$totalRevenue = 0;
$totalRevenuePeriod = 0;
$totalCount = 0;
try {
    $row = $pdo->query('SELECT SUM(price) AS total, COUNT(*) AS cnt FROM sales')->fetch();
    $totalRevenue = (float)($row['total'] ?? 0);
    $totalCount = (int)($row['cnt'] ?? 0);

    $stmt = $pdo->prepare('SELECT SUM(price) AS total FROM sales WHERE sale_datetime >= ?');
    $stmt->execute([$periodStart . ' 00:00:00']);
    $totalRevenuePeriod = (float)($stmt->fetch()['total'] ?? 0);
} catch (Throwable $e) {}

// --- Revenue per service (all-time): name, count, revenue, % ---
$serviceStats = [];
try {
    $stmt = $pdo->query("
        SELECT sv.id, sv.name,
               COUNT(s.id) AS sale_count,
               COALESCE(SUM(s.price), 0) AS revenue
        FROM services sv
        LEFT JOIN sales s ON s.service_id = sv.id
        GROUP BY sv.id, sv.name
        ORDER BY revenue DESC, sale_count DESC
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $revenue = (float)($row['revenue'] ?? 0);
        $count = (int)($row['sale_count'] ?? 0);
        $pct = $totalRevenue > 0 ? ($revenue / $totalRevenue) * 100 : 0;
        $serviceStats[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'sale_count' => $count,
            'revenue' => $revenue,
            'pct' => $pct,
        ];
    }
} catch (Throwable $e) {}

// Best = top by revenue; worst = bottom (or zero revenue)
$bestServices = array_slice($serviceStats, 0, 5);
$worstServices = array_filter($serviceStats, function ($s) { return $s['revenue'] >= 0; });
$worstServices = array_slice(array_reverse($worstServices), 0, 5);

// --- Barber performance: total + average sale (high-value indicator) ---
$barberStats = [];
try {
    $stmt = $pdo->query("
        SELECT b.id, b.name,
               COUNT(s.id) AS sale_count,
               COALESCE(SUM(s.price), 0) AS total_sales,
               COALESCE(AVG(s.price), 0) AS avg_sale
        FROM barbers b
        LEFT JOIN sales s ON s.barber_id = b.id
        GROUP BY b.id, b.name
        ORDER BY total_sales DESC
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $barberStats[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'sale_count' => (int)$row['sale_count'],
            'total_sales' => (float)$row['total_sales'],
            'avg_sale' => (float)$row['avg_sale'],
        ];
    }
} catch (Throwable $e) {}

// Barber with highest avg sale = "generates more high-value services"
$topBarberByAvg = null;
if (!empty($barberStats)) {
    $withSales = array_filter($barberStats, function ($b) { return $b['sale_count'] > 0; });
    if (!empty($withSales)) {
        usort($withSales, function ($a, $b) { return $b['avg_sale'] <=> $a['avg_sale']; });
        $topBarberByAvg = $withSales[0];
    }
}

// --- Barber performance over time (last N months trend) ---
$barberTrend = []; // [ barber_name => [ '2025-01' => 1234, ... ], ... ]
try {
    $stmt = $pdo->prepare("
        SELECT b.id, b.name,
               DATE_FORMAT(s.sale_datetime, '%Y-%m') AS ym,
               SUM(s.price) AS monthly_sales
        FROM barbers b
        LEFT JOIN sales s ON s.barber_id = b.id AND s.sale_datetime >= ?
        WHERE b.id IS NOT NULL
        GROUP BY b.id, b.name, ym
        HAVING ym IS NOT NULL AND ym >= ?
        ORDER BY b.name, ym
    ");
    $stmt->execute([$periodStart . ' 00:00:00', date('Y-m', strtotime($periodStart))]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $name = (string)$row['name'];
        $ym = (string)$row['ym'];
        $sales = (float)($row['monthly_sales'] ?? 0);
        if (!isset($barberTrend[$name])) {
            $barberTrend[$name] = [];
        }
        $barberTrend[$name][$ym] = $sales;
    }
} catch (Throwable $e) {}

// Build list of months for trend table
$monthsInPeriod = [];
for ($i = $trendMonths - 1; $i >= 0; $i--) {
    $monthsInPeriod[] = date('Y-m', strtotime("-$i months"));
}

// --- One-line insights (for display) ---
$insightTopService = null;
if (!empty($serviceStats) && $totalRevenue > 0) {
    $top = $serviceStats[0];
    if ($top['revenue'] > 0) {
        $insightTopService = [
            'name' => $top['name'],
            'pct' => round($top['pct'], 0),
        ];
    }
}
?>
<?php include 'partials/header.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="bb-page-title">Sales Intelligence</h1>
        <p class="bb-page-subtitle">Best and worst services, revenue per service, and barber performance over time to optimize pricing and promos.</p>
    </div>
    <a href="add_sale.php" class="btn btn-sm btn-bb-primary"><i class="bi bi-plus-lg"></i> Add sale</a>
</div>

<?php if ($totalCount === 0): ?>
<div class="bb-section-card card mb-4">
    <div class="card-body text-center py-5">
        <i class="bi bi-graph-up text-muted" style="font-size: 3rem;"></i>
        <p class="text-muted mb-0 mt-2">No sales yet. Add sales to see best-selling services, revenue per service, and barber trends.</p>
        <a href="add_sale.php" class="btn btn-bb-primary mt-3"><i class="bi bi-plus-circle"></i> Add sale</a>
    </div>
</div>
<?php else: ?>

<!-- Insight bullets -->
<div class="bb-section-card card mb-4 border-primary border-opacity-25">
    <div class="card-body">
        <h5 class="bb-section-title mb-3"><i class="bi bi-lightbulb"></i> Quick insights</h5>
        <ul class="mb-0 list-unstyled">
            <?php if ($insightTopService): ?>
            <li class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-check2-circle text-success"></i>
                <span><strong><?php echo htmlspecialchars($insightTopService['name']); ?></strong> = <?php echo (int)$insightTopService['pct']; ?>% of revenue</span>
            </li>
            <?php endif; ?>
            <?php if ($topBarberByAvg && $topBarberByAvg['sale_count'] >= 3): ?>
            <li class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-check2-circle text-success"></i>
                <span><strong><?php echo htmlspecialchars($topBarberByAvg['name']); ?></strong> generates more high-value services (avg sale ₱<?php echo number_format($topBarberByAvg['avg_sale'], 0); ?>)</span>
            </li>
            <?php endif; ?>
            <?php if (count($barberStats) > 1 && !empty($barberStats)): ?>
            <?php
            $topByRevenue = $barberStats[0];
            if ($topByRevenue['total_sales'] > 0):
            ?>
            <li class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-person-badge text-primary"></i>
                <span>Top earner by revenue: <strong><?php echo htmlspecialchars($topByRevenue['name']); ?></strong> (₱<?php echo number_format($topByRevenue['total_sales'], 0); ?> total)</span>
            </li>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (empty($insightTopService) && !$topBarberByAvg): ?>
            <li class="text-muted">Add more sales to see insights like “Haircut = 70% of revenue” and barber high-value comparison.</li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<!-- Best-selling & worst-performing services -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="bb-section-card card h-100">
            <div class="card-body">
                <h5 class="bb-section-title mb-3"><i class="bi bi-trophy"></i> Best-selling services</h5>
                <p class="text-muted small mb-3">Top 5 by revenue.</p>
                <?php if (empty($bestServices)): ?>
                <p class="text-muted small mb-0">No sales by service yet.</p>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($bestServices as $i => $s): ?>
                    <?php if ($s['revenue'] <= 0) continue; ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span><?php echo htmlspecialchars($s['name']); ?></span>
                        <span class="text-success fw-semibold">₱<?php echo number_format($s['revenue'], 0); ?> <small class="text-muted">(<?php echo (int)round($s['pct']); ?>%)</small></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="bb-section-card card h-100">
            <div class="card-body">
                <h5 class="bb-section-title mb-3"><i class="bi bi-graph-down"></i> Worst-performing services</h5>
                <p class="text-muted small mb-3">Bottom 5 by revenue (or zero).</p>
                <?php if (empty($worstServices)): ?>
                <p class="text-muted small mb-0">No services with low/zero sales.</p>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($worstServices as $s): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span><?php echo htmlspecialchars($s['name']); ?></span>
                        <span class="text-muted">₱<?php echo number_format($s['revenue'], 0); ?> <small>(<?php echo $s['sale_count']; ?> sales)</small></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Revenue per service (full table) -->
<div class="bb-section-card card mb-4">
    <div class="card-body">
        <h5 class="bb-section-title mb-3"><i class="bi bi-currency-exchange"></i> Revenue per service</h5>
        <p class="text-muted small mb-3">All services with total revenue and share of total sales. Use this to optimize pricing and promos.</p>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Service</th>
                        <th class="text-end">Sales (count)</th>
                        <th class="text-end">Revenue</th>
                        <th class="text-end">% of total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($serviceStats as $s): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($s['name']); ?></td>
                        <td class="text-end"><?php echo (int)$s['sale_count']; ?></td>
                        <td class="text-end">₱<?php echo number_format($s['revenue'], 2); ?></td>
                        <td class="text-end"><?php echo number_format($s['pct'], 1); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalRevenue > 0): ?>
        <div class="mt-2 small text-muted">Total revenue: ₱<?php echo number_format($totalRevenue, 2); ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- Barber performance over time (trend) -->
<div class="bb-section-card card mb-4">
    <div class="card-body">
        <h5 class="bb-section-title mb-3"><i class="bi bi-person-badge"></i> Barber performance over time</h5>
        <p class="text-muted small mb-3">Monthly sales per barber for the last <?php echo $trendMonths; ?> months (trend, not just totals).</p>
        <?php if (empty($barberTrend)): ?>
        <p class="text-muted small mb-0">No sales in the last <?php echo $trendMonths; ?> months to show trend.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Barber</th>
                        <?php foreach ($monthsInPeriod as $ym): ?>
                        <th class="text-end"><?php echo htmlspecialchars($ym); ?></th>
                        <?php endforeach; ?>
                        <th class="text-end">Total (period)</th>
                        <th class="text-end">Avg sale</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($barberTrend as $barberName => $monthly): ?>
                    <?php
                    $periodTotal = array_sum($monthly);
                    $barberInfo = null;
                    foreach ($barberStats as $b) {
                        if ($b['name'] === $barberName) { $barberInfo = $b; break; }
                    }
                    $avgSale = $barberInfo ? $barberInfo['avg_sale'] : 0;
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($barberName); ?></strong></td>
                        <?php foreach ($monthsInPeriod as $ym): ?>
                        <td class="text-end"><?php echo isset($monthly[$ym]) && $monthly[$ym] > 0 ? '₱' . number_format($monthly[$ym], 0) : '—'; ?></td>
                        <?php endforeach; ?>
                        <td class="text-end fw-semibold">₱<?php echo number_format($periodTotal, 0); ?></td>
                        <td class="text-end text-muted">₱<?php echo number_format($avgSale, 0); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-2 small text-muted">Use this to see who is growing or slowing over time and to compare who drives higher average ticket (avg sale).</div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php include 'partials/footer.php'; ?>
