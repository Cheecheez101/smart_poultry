<?php
require_once '../../includes/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Require login
requireLogin();

// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$flock_filter = $_GET['flock_id'] ?? '';
$group_by = $_GET['group_by'] ?? 'daily'; // daily, weekly, monthly

// Get all flocks for dropdown
$flocks = $pdo->query("SELECT id, batch_number, breed FROM flocks ORDER BY batch_number")->fetchAll();

// Overall Production Statistics
$stats = [
    'total_eggs' => 0,
    'total_broken' => 0,
    'total_sold' => 0,
    'total_stored' => 0,
    'avg_daily' => 0,
    'success_rate' => 0,
    'days_recorded' => 0
];

$where_clause = "WHERE production_date BETWEEN ? AND ?";
$params = [$date_from, $date_to];

if (!empty($flock_filter)) {
    $where_clause .= " AND flock_id = ?";
    $params[] = $flock_filter;
}

$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT production_date) as days,
        COALESCE(SUM(eggs_collected), 0) as total_eggs,
        COALESCE(SUM(eggs_broken), 0) as broken,
        COALESCE(SUM(eggs_sold), 0) as sold,
        COALESCE(SUM(eggs_stored), 0) as stored,
        COALESCE(AVG(eggs_collected), 0) as avg_daily
    FROM egg_production
    $where_clause
");
$stmt->execute($params);
$result = $stmt->fetch();

$stats['total_eggs'] = $result['total_eggs'];
$stats['total_broken'] = $result['broken'];
$stats['total_sold'] = $result['sold'];
$stats['total_stored'] = $result['stored'];
$stats['avg_daily'] = $result['avg_daily'];
$stats['days_recorded'] = $result['days'];
$stats['success_rate'] = $stats['total_eggs'] > 0 
    ? (($stats['total_eggs'] - $stats['total_broken']) / $stats['total_eggs']) * 100 
    : 0;

// Production by Flock
$flock_query = "
    SELECT 
        f.id,
        f.batch_number,
        f.breed,
        f.current_count,
        COALESCE(SUM(ep.eggs_collected), 0) as total_eggs,
        COALESCE(SUM(ep.eggs_broken), 0) as broken,
        COALESCE(SUM(ep.eggs_sold), 0) as sold,
        COALESCE(AVG(ep.eggs_collected), 0) as avg_daily,
        COUNT(DISTINCT ep.production_date) as days_recorded,
        COALESCE(AVG(ep.average_weight), 0) as avg_weight
    FROM flocks f
    LEFT JOIN egg_production ep ON f.id = ep.flock_id 
        AND ep.production_date BETWEEN ? AND ?
    " . (!empty($flock_filter) ? "WHERE f.id = ?" : "") . "
    GROUP BY f.id, f.batch_number, f.breed, f.current_count
    ORDER BY total_eggs DESC
";

$flock_params = [$date_from, $date_to];
if (!empty($flock_filter)) {
    $flock_params[] = $flock_filter;
}

$stmt = $pdo->prepare($flock_query);
$stmt->execute($flock_params);
$flock_production = $stmt->fetchAll();

// Trend Data (Grouped by period)
$trend_data = [];
if ($group_by === 'daily') {
    $stmt = $pdo->prepare("
        SELECT 
            production_date as period,
            DATE_FORMAT(production_date, '%b %d') as label,
            COALESCE(SUM(eggs_collected), 0) as eggs,
            COALESCE(SUM(eggs_broken), 0) as broken,
            COALESCE(SUM(eggs_sold), 0) as sold
        FROM egg_production
        $where_clause
        GROUP BY production_date
        ORDER BY production_date ASC
    ");
} elseif ($group_by === 'weekly') {
    $stmt = $pdo->prepare("
        SELECT 
            WEEK(production_date, 1) as period,
            CONCAT('Week ', WEEK(production_date, 1)) as label,
            COALESCE(SUM(eggs_collected), 0) as eggs,
            COALESCE(SUM(eggs_broken), 0) as broken,
            COALESCE(SUM(eggs_sold), 0) as sold
        FROM egg_production
        $where_clause
        GROUP BY WEEK(production_date, 1)
        ORDER BY period ASC
    ");
} else { // monthly
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(production_date, '%Y-%m') as period,
            DATE_FORMAT(production_date, '%b %Y') as label,
            COALESCE(SUM(eggs_collected), 0) as eggs,
            COALESCE(SUM(eggs_broken), 0) as broken,
            COALESCE(SUM(eggs_sold), 0) as sold
        FROM egg_production
        $where_clause
        GROUP BY DATE_FORMAT(production_date, '%Y-%m')
        ORDER BY period ASC
    ");
}
$stmt->execute($params);
$trend_data = $stmt->fetchAll();

// Best and Worst Performing Days
$stmt = $pdo->prepare("
    SELECT 
        production_date,
        COALESCE(SUM(eggs_collected), 0) as total_eggs,
        COALESCE(SUM(eggs_broken), 0) as broken
    FROM egg_production
    $where_clause
    GROUP BY production_date
    ORDER BY COALESCE(SUM(eggs_collected), 0) DESC
    LIMIT 5
");
$stmt->execute($params);
$best_days = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT 
        production_date,
        COALESCE(SUM(eggs_collected), 0) as total_eggs,
        COALESCE(SUM(eggs_broken), 0) as broken
    FROM egg_production
    $where_clause
    GROUP BY production_date
    HAVING total_eggs > 0
    ORDER BY COALESCE(SUM(eggs_collected), 0) ASC
    LIMIT 5
");
$stmt->execute($params);
$worst_days = $stmt->fetchAll();

$pageTitle = 'Production Report';
include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2><i class="bi bi-egg-fill"></i> Production Report</h2>
        </div>
        <div class="col-auto">
            <button class="btn btn-success" onclick="window.print()">
                <i class="bi bi-printer"></i> Print Report
            </button>
            <button class="btn btn-primary" onclick="exportToCSV()">
                <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Flock</label>
                    <select name="flock_id" class="form-select">
                        <option value="">All Flocks</option>
                        <?php foreach ($flocks as $flock): ?>
                            <option value="<?= $flock['id'] ?>" <?= $flock_filter == $flock['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($flock['batch_number']) ?> - <?= htmlspecialchars($flock['breed']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Group By</label>
                    <select name="group_by" class="form-select">
                        <option value="daily" <?= $group_by === 'daily' ? 'selected' : '' ?>>Daily</option>
                        <option value="weekly" <?= $group_by === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                        <option value="monthly" <?= $group_by === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    </select>
                </div>
                <div class="col-md-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Generate Report
                    </button>
                    <a href="production_report.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Report Period -->
    <div class="alert alert-info mb-4">
        <i class="bi bi-calendar-range"></i> Report Period: <strong><?= date('M d, Y', strtotime($date_from)) ?></strong> to <strong><?= date('M d, Y', strtotime($date_to)) ?></strong>
        <?php if (!empty($flock_filter)): ?>
            <?php 
                $selected_flock = array_filter($flocks, fn($f) => $f['id'] == $flock_filter);
                $selected_flock = reset($selected_flock);
            ?>
            | Flock: <strong><?= htmlspecialchars($selected_flock['batch_number']) ?></strong>
        <?php endif; ?>
    </div>

    <!-- Summary Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h6 class="card-title">Total Eggs Collected</h6>
                    <h2><?= number_format($stats['total_eggs']) ?></h2>
                    <small>Over <?= $stats['days_recorded'] ?> days</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h6 class="card-title">Average Daily Production</h6>
                    <h2><?= number_format($stats['avg_daily'], 1) ?></h2>
                    <small>Eggs per day</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h6 class="card-title">Success Rate</h6>
                    <h2><?= number_format($stats['success_rate'], 1) ?>%</h2>
                    <small><?= number_format($stats['total_eggs'] - $stats['total_broken']) ?> good eggs</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h6 class="card-title">Broken Eggs</h6>
                    <h2><?= number_format($stats['total_broken']) ?></h2>
                    <small><?= number_format(($stats['total_eggs'] > 0 ? ($stats['total_broken'] / $stats['total_eggs']) * 100 : 0), 1) ?>% loss rate</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Distribution Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h6 class="text-muted">Eggs Sold</h6>
                    <h3 class="text-success"><?= number_format($stats['total_sold']) ?></h3>
                    <div class="progress" style="height: 25px;">
                        <?php $sold_pct = $stats['total_eggs'] > 0 ? ($stats['total_sold'] / $stats['total_eggs']) * 100 : 0; ?>
                        <div class="progress-bar bg-success" style="width: <?= $sold_pct ?>%">
                            <?= number_format($sold_pct, 1) ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h6 class="text-muted">Eggs Stored</h6>
                    <h3 class="text-info"><?= number_format($stats['total_stored']) ?></h3>
                    <div class="progress" style="height: 25px;">
                        <?php $stored_pct = $stats['total_eggs'] > 0 ? ($stats['total_stored'] / $stats['total_eggs']) * 100 : 0; ?>
                        <div class="progress-bar bg-info" style="width: <?= $stored_pct ?>%">
                            <?= number_format($stored_pct, 1) ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <h6 class="text-muted">Good Eggs</h6>
                    <h3 class="text-primary"><?= number_format($stats['total_eggs'] - $stats['total_broken']) ?></h3>
                    <div class="progress" style="height: 25px;">
                        <div class="progress-bar bg-primary" style="width: <?= $stats['success_rate'] ?>%">
                            <?= number_format($stats['success_rate'], 1) ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Production Trend Chart -->
    <?php if (!empty($trend_data)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-graph-up"></i> Production Trend (<?= ucfirst($group_by) ?>)</h5>
        </div>
        <div class="card-body">
            <canvas id="productionChart" height="80"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- Production by Flock -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Production by Flock</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="flockProductionTable">
                    <thead>
                        <tr>
                            <th>Batch Number</th>
                            <th>Breed</th>
                            <th>Current Birds</th>
                            <th>Total Eggs</th>
                            <th>Broken</th>
                            <th>Sold</th>
                            <th>Avg Daily</th>
                            <th>Avg Weight (g)</th>
                            <th>Days Recorded</th>
                            <th>Success Rate</th>
                            <th>Per Bird/Day</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($flock_production)): ?>
                            <tr><td colspan="11" class="text-center">No production data</td></tr>
                        <?php else: ?>
                            <?php foreach ($flock_production as $flock): ?>
                                <?php 
                                    $success_rate = $flock['total_eggs'] > 0 
                                        ? (($flock['total_eggs'] - $flock['broken']) / $flock['total_eggs']) * 100 
                                        : 0;
                                    $per_bird_day = ($flock['current_count'] > 0 && $flock['days_recorded'] > 0) 
                                        ? $flock['total_eggs'] / ($flock['current_count'] * $flock['days_recorded']) 
                                        : 0;
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($flock['batch_number']) ?></strong></td>
                                    <td><?= htmlspecialchars($flock['breed']) ?></td>
                                    <td><?= number_format($flock['current_count']) ?></td>
                                    <td><strong><?= number_format($flock['total_eggs']) ?></strong></td>
                                    <td><span class="text-danger"><?= number_format($flock['broken']) ?></span></td>
                                    <td><span class="text-success"><?= number_format($flock['sold']) ?></span></td>
                                    <td><?= number_format($flock['avg_daily'], 1) ?></td>
                                    <td><?= number_format($flock['avg_weight'], 1) ?></td>
                                    <td><?= $flock['days_recorded'] ?></td>
                                    <td>
                                        <span class="badge bg-<?= $success_rate >= 95 ? 'success' : ($success_rate >= 90 ? 'warning' : 'danger') ?>">
                                            <?= number_format($success_rate, 1) ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $per_bird_day >= 0.8 ? 'success' : ($per_bird_day >= 0.5 ? 'warning' : 'danger') ?>">
                                            <?= number_format($per_bird_day, 2) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Best and Worst Days -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-trophy"></i> Best Performing Days</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Eggs Collected</th>
                                <th>Broken</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($best_days)): ?>
                                <tr><td colspan="3" class="text-center">No data</td></tr>
                            <?php else: ?>
                                <?php foreach ($best_days as $day): ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime($day['production_date'])) ?></td>
                                        <td><strong><?= number_format($day['total_eggs']) ?></strong></td>
                                        <td><span class="text-danger"><?= number_format($day['broken']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-warning">
                <div class="card-header bg-warning text-white">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Lowest Performing Days</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Eggs Collected</th>
                                <th>Broken</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($worst_days)): ?>
                                <tr><td colspan="3" class="text-center">No data</td></tr>
                            <?php else: ?>
                                <?php foreach ($worst_days as $day): ?>
                                    <tr>
                                        <td><?= date('M d, Y', strtotime($day['production_date'])) ?></td>
                                        <td><strong><?= number_format($day['total_eggs']) ?></strong></td>
                                        <td><span class="text-danger"><?= number_format($day['broken']) ?></span></td>
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

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<script>
// Production Trend Chart
<?php if (!empty($trend_data)): ?>
const ctx = document.getElementById('productionChart');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($trend_data, 'label')) ?>,
        datasets: [
            {
                label: 'Eggs Collected',
                data: <?= json_encode(array_column($trend_data, 'eggs')) ?>,
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                tension: 0.1
            },
            {
                label: 'Eggs Broken',
                data: <?= json_encode(array_column($trend_data, 'broken')) ?>,
                borderColor: 'rgb(255, 99, 132)',
                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                tension: 0.1
            },
            {
                label: 'Eggs Sold',
                data: <?= json_encode(array_column($trend_data, 'sold')) ?>,
                borderColor: 'rgb(54, 162, 235)',
                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                tension: 0.1
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'top',
            },
            title: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
<?php endif; ?>

// Export to CSV
function exportToCSV() {
    const table = document.getElementById('flockProductionTable');
    let csv = [];
    
    // Headers
    const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent);
    csv.push(headers.join(','));
    
    // Rows
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cols = Array.from(row.querySelectorAll('td')).map(td => {
            return '"' + td.textContent.trim().replace(/"/g, '""') + '"';
        });
        if (cols.length > 0) {
            csv.push(cols.join(','));
        }
    });
    
    // Download
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'production_report_<?= date('Y-m-d') ?>.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}
</script>

<style>
@media print {
    .btn, nav, .sidebar, .no-print {
        display: none !important;
    }
    .card {
        border: 1px solid #ddd !important;
        page-break-inside: avoid;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>
