<?php
require_once '../../includes/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Require login
requireLogin();

// Get date range from filters
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today
$report_type = $_GET['report_type'] ?? 'overview';

// Overview Statistics
$overview_stats = [
    'total_flocks' => 0,
    'total_birds' => 0,
    'total_eggs_collected' => 0,
    'total_revenue' => 0,
    'total_expenses' => 0,
    'net_profit' => 0,
    'feed_consumption' => 0,
    'medication_cost' => 0
];

// Get flock statistics
$stmt = $pdo->query("SELECT COUNT(*) as count, COALESCE(SUM(current_count), 0) as total_birds FROM flocks");
$result = $stmt->fetch();
$overview_stats['total_flocks'] = $result['count'];
$overview_stats['total_birds'] = $result['total_birds'];

// Get egg production for date range
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(eggs_collected), 0) as total_eggs
    FROM egg_production
    WHERE production_date BETWEEN ? AND ?
");
$stmt->execute([$date_from, $date_to]);
$overview_stats['total_eggs_collected'] = $stmt->fetchColumn();

// Get sales revenue for date range
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0) as revenue
    FROM sales
    WHERE sale_date BETWEEN ? AND ?
");
$stmt->execute([$date_from, $date_to]);
$overview_stats['total_revenue'] = $stmt->fetchColumn();

// Get medication expenses for date range
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(cost), 0) as med_cost
    FROM medications
    WHERE administration_date BETWEEN ? AND ?
");
$stmt->execute([$date_from, $date_to]);
$overview_stats['medication_cost'] = $stmt->fetchColumn();

// Get feed consumption value (assuming we have pricing data)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(fc.quantity_kg * fi.unit_price), 0) as feed_cost
    FROM feed_consumption fc
    LEFT JOIN feed_inventory fi ON fc.feed_id = fi.id
    WHERE fc.consumption_date BETWEEN ? AND ?
");
$stmt->execute([$date_from, $date_to]);
$overview_stats['feed_consumption'] = $stmt->fetchColumn();

$overview_stats['total_expenses'] = $overview_stats['medication_cost'] + $overview_stats['feed_consumption'];
$overview_stats['net_profit'] = $overview_stats['total_revenue'] - $overview_stats['total_expenses'];

// Production Report Data
$production_report = [];
if ($report_type === 'production' || $report_type === 'overview') {
    $stmt = $pdo->prepare("
        SELECT 
            f.batch_number,
            f.breed,
            COALESCE(SUM(ep.eggs_collected), 0) as total_eggs,
            COALESCE(SUM(ep.eggs_broken), 0) as broken_eggs,
            COALESCE(SUM(ep.eggs_sold), 0) as sold_eggs,
            COALESCE(AVG(ep.eggs_collected), 0) as avg_daily,
            COUNT(DISTINCT ep.production_date) as days_recorded
        FROM flocks f
        LEFT JOIN egg_production ep ON f.id = ep.flock_id 
            AND ep.production_date BETWEEN ? AND ?
        GROUP BY f.id, f.batch_number, f.breed
        ORDER BY total_eggs DESC
    ");
    $stmt->execute([$date_from, $date_to]);
    $production_report = $stmt->fetchAll();
}

// Sales Report Data
$sales_report = [];
if ($report_type === 'sales' || $report_type === 'overview') {
    $stmt = $pdo->prepare("
        SELECT 
            product_type,
            COUNT(*) as transaction_count,
            COALESCE(SUM(quantity), 0) as total_quantity,
            COALESCE(SUM(total_amount), 0) as total_revenue,
            COALESCE(AVG(unit_price), 0) as avg_price
        FROM sales
        WHERE sale_date BETWEEN ? AND ?
        GROUP BY product_type
        ORDER BY total_revenue DESC
    ");
    $stmt->execute([$date_from, $date_to]);
    $sales_report = $stmt->fetchAll();
}

// Expense Report Data
$expense_report = [];
if ($report_type === 'expenses' || $report_type === 'overview') {
    // Medication expenses
    $stmt = $pdo->prepare("
        SELECT 
            'Medication' as category,
            medication_type as subcategory,
            COUNT(*) as transaction_count,
            COALESCE(SUM(cost), 0) as total_cost
        FROM medications
        WHERE administration_date BETWEEN ? AND ?
        GROUP BY medication_type
    ");
    $stmt->execute([$date_from, $date_to]);
    $med_expenses = $stmt->fetchAll();
    
    // Feed expenses (from consumption)
    $stmt = $pdo->prepare("
        SELECT 
            'Feed' as category,
            fi.feed_type as subcategory,
            COUNT(*) as transaction_count,
            COALESCE(SUM(fc.quantity_kg * fi.unit_price), 0) as total_cost
        FROM feed_consumption fc
        LEFT JOIN feed_inventory fi ON fc.feed_id = fi.id
        WHERE fc.consumption_date BETWEEN ? AND ?
        GROUP BY fi.feed_type
    ");
    $stmt->execute([$date_from, $date_to]);
    $feed_expenses = $stmt->fetchAll();
    
    $expense_report = array_merge($med_expenses, $feed_expenses);
}

// Daily Performance Trend
$daily_trend = [];
if ($report_type === 'overview') {
    $stmt = $pdo->prepare("
        SELECT 
            DATE(production_date) as date,
            COALESCE(SUM(eggs_collected), 0) as eggs,
            COALESCE(SUM(eggs_broken), 0) as broken
        FROM egg_production
        WHERE production_date BETWEEN ? AND ?
        GROUP BY DATE(production_date)
        ORDER BY date DESC
        LIMIT 30
    ");
    $stmt->execute([$date_from, $date_to]);
    $daily_trend = $stmt->fetchAll();
}

// Payment Status Report
$payment_report = [];
if ($report_type === 'sales' || $report_type === 'overview') {
    $stmt = $pdo->prepare("
        SELECT 
            payment_status,
            COUNT(*) as count,
            COALESCE(SUM(total_amount), 0) as amount
        FROM sales
        WHERE sale_date BETWEEN ? AND ?
        GROUP BY payment_status
    ");
    $stmt->execute([$date_from, $date_to]);
    $payment_report = $stmt->fetchAll();
}

$pageTitle = 'Reports & Analytics';
include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2><i class="bi bi-graph-up"></i> Reports & Analytics</h2>
        </div>
        <div class="col-auto">
            <button class="btn btn-success" onclick="window.print()">
                <i class="bi bi-printer"></i> Print Report
            </button>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <span class="text-muted me-2">Detailed Reports:</span>
            <a class="btn btn-outline-primary" href="production_report.php"><i class="fas fa-egg me-1"></i>Production Analytics</a>
            <a class="btn btn-outline-primary" href="financial_report.php"><i class="fas fa-chart-line me-1"></i>Financial Report</a>
            <a class="btn btn-outline-primary" href="inventory_report.php"><i class="fas fa-warehouse me-1"></i>Inventory Report</a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Report Type</label>
                    <select name="report_type" class="form-select">
                        <option value="overview" <?= $report_type === 'overview' ? 'selected' : '' ?>>Overview</option>
                        <option value="production" <?= $report_type === 'production' ? 'selected' : '' ?>>Production</option>
                        <option value="sales" <?= $report_type === 'sales' ? 'selected' : '' ?>>Sales</option>
                        <option value="expenses" <?= $report_type === 'expenses' ? 'selected' : '' ?>>Expenses</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i> Generate Report
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Date Range Display -->
    <div class="alert alert-info">
        <i class="bi bi-calendar-range"></i> Report Period: <strong><?= date('M d, Y', strtotime($date_from)) ?></strong> to <strong><?= date('M d, Y', strtotime($date_to)) ?></strong>
    </div>

    <!-- Overview Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h6 class="card-title">Total Revenue</h6>
                    <h3>KSh <?= number_format($overview_stats['total_revenue'], 2) ?></h3>
                    <small>From sales</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h6 class="card-title">Total Expenses</h6>
                    <h3>KSh <?= number_format($overview_stats['total_expenses'], 2) ?></h3>
                    <small>Feed + Medication</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-<?= $overview_stats['net_profit'] >= 0 ? 'success' : 'warning' ?> text-white">
                <div class="card-body">
                    <h6 class="card-title">Net Profit/Loss</h6>
                    <h3>KSh <?= number_format($overview_stats['net_profit'], 2) ?></h3>
                    <small>Revenue - Expenses</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h6 class="card-title">Eggs Collected</h6>
                    <h3><?= number_format($overview_stats['total_eggs_collected']) ?></h3>
                    <small>Total eggs</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Production Report -->
    <?php if ($report_type === 'production' || $report_type === 'overview'): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-egg"></i> Production Report by Flock</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Batch Number</th>
                            <th>Breed</th>
                            <th>Total Eggs</th>
                            <th>Broken</th>
                            <th>Sold</th>
                            <th>Avg Daily</th>
                            <th>Days Recorded</th>
                            <th>Success Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($production_report)): ?>
                            <tr><td colspan="8" class="text-center">No production data for this period</td></tr>
                        <?php else: ?>
                            <?php foreach ($production_report as $prod): ?>
                                <?php 
                                    $success_rate = $prod['total_eggs'] > 0 
                                        ? (($prod['total_eggs'] - $prod['broken_eggs']) / $prod['total_eggs']) * 100 
                                        : 0;
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($prod['batch_number']) ?></strong></td>
                                    <td><?= htmlspecialchars($prod['breed']) ?></td>
                                    <td><?= number_format($prod['total_eggs']) ?></td>
                                    <td><span class="text-danger"><?= number_format($prod['broken_eggs']) ?></span></td>
                                    <td><span class="text-success"><?= number_format($prod['sold_eggs']) ?></span></td>
                                    <td><?= number_format($prod['avg_daily'], 1) ?></td>
                                    <td><?= $prod['days_recorded'] ?></td>
                                    <td>
                                        <span class="badge bg-<?= $success_rate >= 95 ? 'success' : ($success_rate >= 90 ? 'warning' : 'danger') ?>">
                                            <?= number_format($success_rate, 1) ?>%
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
    <?php endif; ?>

    <!-- Sales Report -->
    <?php if ($report_type === 'sales' || $report_type === 'overview'): ?>
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-cart-check"></i> Sales Report by Product Type</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Product Type</th>
                                    <th>Transactions</th>
                                    <th>Quantity</th>
                                    <th>Revenue</th>
                                    <th>Avg Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sales_report)): ?>
                                    <tr><td colspan="5" class="text-center">No sales data for this period</td></tr>
                                <?php else: ?>
                                    <?php foreach ($sales_report as $sale): ?>
                                        <tr>
                                            <td><strong><?= ucfirst($sale['product_type']) ?></strong></td>
                                            <td><?= number_format($sale['transaction_count']) ?></td>
                                            <td><?= number_format($sale['total_quantity'], 2) ?></td>
                                            <td>KSh <?= number_format($sale['total_revenue'], 2) ?></td>
                                            <td>KSh <?= number_format($sale['avg_price'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-credit-card"></i> Payment Status</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($payment_report)): ?>
                        <p class="text-center text-muted">No payment data</p>
                    <?php else: ?>
                        <?php foreach ($payment_report as $payment): ?>
                            <?php
                                $statusColors = [
                                    'paid' => 'success',
                                    'pending' => 'danger',
                                    'partial' => 'warning'
                                ];
                                $color = $statusColors[$payment['payment_status']] ?? 'secondary';
                            ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-capitalize"><?= $payment['payment_status'] ?></span>
                                    <strong>KSh <?= number_format($payment['amount'], 2) ?></strong>
                                </div>
                                <div class="progress" style="height: 25px;">
                                    <?php 
                                        $percentage = $overview_stats['total_revenue'] > 0 
                                            ? ($payment['amount'] / $overview_stats['total_revenue']) * 100 
                                            : 0;
                                    ?>
                                    <div class="progress-bar bg-<?= $color ?>" style="width: <?= $percentage ?>%">
                                        <?= $payment['count'] ?> transactions
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Expense Report -->
    <?php if ($report_type === 'expenses' || $report_type === 'overview'): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-cash-stack"></i> Expense Report</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Subcategory</th>
                            <th>Transactions</th>
                            <th>Total Cost</th>
                            <th>% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($expense_report)): ?>
                            <tr><td colspan="5" class="text-center">No expense data for this period</td></tr>
                        <?php else: ?>
                            <?php foreach ($expense_report as $expense): ?>
                                <?php 
                                    $percentage = $overview_stats['total_expenses'] > 0 
                                        ? ($expense['total_cost'] / $overview_stats['total_expenses']) * 100 
                                        : 0;
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($expense['category']) ?></strong></td>
                                    <td><?= htmlspecialchars($expense['subcategory'] ?? 'N/A') ?></td>
                                    <td><?= number_format($expense['transaction_count']) ?></td>
                                    <td>KSh <?= number_format($expense['total_cost'], 2) ?></td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar" style="width: <?= $percentage ?>%">
                                                <?= number_format($percentage, 1) ?>%
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Daily Performance Trend -->
    <?php if ($report_type === 'overview' && !empty($daily_trend)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-graph-up-arrow"></i> Daily Production Trend (Last 30 Days)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Eggs Collected</th>
                            <th>Broken</th>
                            <th>Quality Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($daily_trend as $day): ?>
                            <?php 
                                $quality_rate = $day['eggs'] > 0 
                                    ? (($day['eggs'] - $day['broken']) / $day['eggs']) * 100 
                                    : 0;
                            ?>
                            <tr>
                                <td><?= date('M d, Y', strtotime($day['date'])) ?></td>
                                <td><?= number_format($day['eggs']) ?></td>
                                <td><span class="text-danger"><?= number_format($day['broken']) ?></span></td>
                                <td>
                                    <span class="badge bg-<?= $quality_rate >= 95 ? 'success' : ($quality_rate >= 90 ? 'warning' : 'danger') ?>">
                                        <?= number_format($quality_rate, 1) ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Summary Box -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-clipboard-data"></i> Report Summary</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Farm Overview</h6>
                    <ul class="list-unstyled">
                        <li><i class="bi bi-check-circle text-success"></i> Total Flocks: <strong><?= $overview_stats['total_flocks'] ?></strong></li>
                        <li><i class="bi bi-check-circle text-success"></i> Total Birds: <strong><?= number_format($overview_stats['total_birds']) ?></strong></li>
                        <li><i class="bi bi-check-circle text-success"></i> Eggs Collected: <strong><?= number_format($overview_stats['total_eggs_collected']) ?></strong></li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6>Financial Summary</h6>
                    <ul class="list-unstyled">
                        <li><i class="bi bi-arrow-up-circle text-success"></i> Total Revenue: <strong>KSh <?= number_format($overview_stats['total_revenue'], 2) ?></strong></li>
                        <li><i class="bi bi-arrow-down-circle text-danger"></i> Total Expenses: <strong>KSh <?= number_format($overview_stats['total_expenses'], 2) ?></strong></li>
                        <li><i class="bi bi-<?= $overview_stats['net_profit'] >= 0 ? 'graph-up' : 'graph-down' ?> text-<?= $overview_stats['net_profit'] >= 0 ? 'success' : 'danger' ?>"></i> Net Profit/Loss: <strong>KSh <?= number_format($overview_stats['net_profit'], 2) ?></strong></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .btn, .card-header button, nav, .sidebar, .no-print {
        display: none !important;
    }
    .card {
        border: 1px solid #ddd !important;
        page-break-inside: avoid;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>
