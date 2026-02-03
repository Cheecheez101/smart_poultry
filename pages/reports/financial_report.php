<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';

requireLogin();

$page_title = "Financial Report";
include '../../includes/header.php';

// Handle date filtering
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

// Build where clause for date filtering
$date_where = "WHERE date BETWEEN ? AND ?";
$params = [$start_date, $end_date];

// Financial Overview
$financial_overview = [
    'total_revenue' => 0,
    'total_expenses' => 0,
    'net_profit' => 0,
    'feed_costs' => 0,
    'medication_costs' => 0,
    'other_expenses' => 0
];

// Get total sales revenue
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0) as revenue
    FROM sales
    WHERE sale_date BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
$result = $stmt->fetch();
$financial_overview['total_revenue'] = $result['revenue'];

// Get feed costs (from feed_inventory purchases - assuming cost is tracked)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(quantity * unit_price), 0) as feed_costs
    FROM feed_inventory
    WHERE created_at BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
$result = $stmt->fetch();
$financial_overview['feed_costs'] = $result['feed_costs'];

// Get medication costs
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(cost), 0) as medication_costs
    FROM medications
    WHERE administration_date BETWEEN ? AND ?
");
$stmt->execute([$start_date, $end_date]);
$result = $stmt->fetch();
$financial_overview['medication_costs'] = $result['medication_costs'];

// Calculate total expenses
$financial_overview['total_expenses'] = $financial_overview['feed_costs'] + $financial_overview['medication_costs'] + $financial_overview['other_expenses'];

// Calculate net profit
$financial_overview['net_profit'] = $financial_overview['total_revenue'] - $financial_overview['total_expenses'];

// Monthly revenue trend
$stmt = $pdo->prepare("
    SELECT
        DATE_FORMAT(sale_date, '%Y-%m') as period,
        DATE_FORMAT(sale_date, '%b %Y') as label,
        COALESCE(SUM(total_amount), 0) as revenue
    FROM sales
    WHERE sale_date BETWEEN ? AND ?
    GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
    ORDER BY period ASC
");
$stmt->execute([$start_date, $end_date]);
$revenue_trend = $stmt->fetchAll();

// Monthly expenses trend
$stmt = $pdo->prepare("
    SELECT
        DATE_FORMAT(date, '%Y-%m') as period,
        DATE_FORMAT(date, '%b %Y') as label,
        COALESCE(SUM(amount), 0) as expenses
    FROM (
        SELECT administration_date as date, cost as amount FROM medications WHERE administration_date BETWEEN ? AND ?
        UNION ALL
        SELECT created_at as date, (quantity * unit_price) as amount FROM feed_inventory WHERE created_at BETWEEN ? AND ?
    ) as combined_expenses
    GROUP BY DATE_FORMAT(date, '%Y-%m')
    ORDER BY period ASC
");
$stmt->execute([$start_date, $end_date, $start_date, $end_date]);
$expenses_trend = $stmt->fetchAll();

// Revenue by product type
$stmt = $pdo->prepare("
    SELECT
        product_type,
        COALESCE(SUM(total_amount), 0) as revenue,
        COALESCE(SUM(quantity), 0) as quantity
    FROM sales
    WHERE sale_date BETWEEN ? AND ?
    GROUP BY product_type
    ORDER BY revenue DESC
");
$stmt->execute([$start_date, $end_date]);
$revenue_by_product = $stmt->fetchAll();

// Top customers by revenue
$stmt = $pdo->prepare("
    SELECT
        c.customer_name,
        COALESCE(SUM(s.total_amount), 0) as total_revenue,
        COUNT(s.id) as total_sales
    FROM customers c
    LEFT JOIN sales s ON c.id = s.customer_id AND s.sale_date BETWEEN ? AND ?
    GROUP BY c.id, c.customer_name
    HAVING total_revenue > 0
    ORDER BY total_revenue DESC
    LIMIT 10
");
$stmt->execute([$start_date, $end_date]);
$top_customers = $stmt->fetchAll();

// Expense breakdown
$expense_breakdown = [
    ['category' => 'Feed Costs', 'amount' => $financial_overview['feed_costs'], 'percentage' => 0],
    ['category' => 'Medication Costs', 'amount' => $financial_overview['medication_costs'], 'percentage' => 0],
    ['category' => 'Other Expenses', 'amount' => $financial_overview['other_expenses'], 'percentage' => 0]
];

$total_expenses = $financial_overview['total_expenses'];
if ($total_expenses > 0) {
    foreach ($expense_breakdown as &$expense) {
        $expense['percentage'] = round(($expense['amount'] / $total_expenses) * 100, 1);
    }
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="financial_report_' . $start_date . '_to_' . $end_date . '.csv"');

    $output = fopen('php://output', 'w');

    // Financial Overview
    fputcsv($output, ['Financial Overview']);
    fputcsv($output, ['Metric', 'Amount (KES)']);
    fputcsv($output, ['Total Revenue', formatCurrency($financial_overview['total_revenue'])]);
    fputcsv($output, ['Total Expenses', formatCurrency($financial_overview['total_expenses'])]);
    fputcsv($output, ['Net Profit', formatCurrency($financial_overview['net_profit'])]);
    fputcsv($output, []);

    // Revenue by Product
    fputcsv($output, ['Revenue by Product Type']);
    fputcsv($output, ['Product Type', 'Revenue (KES)', 'Quantity']);
    foreach ($revenue_by_product as $product) {
        fputcsv($output, [
            ucfirst($product['product_type']),
            formatCurrency($product['revenue']),
            $product['quantity']
        ]);
    }
    fputcsv($output, []);

    // Top Customers
    fputcsv($output, ['Top Customers']);
    fputcsv($output, ['Customer Name', 'Total Revenue (KES)', 'Number of Sales']);
    foreach ($top_customers as $customer) {
        fputcsv($output, [
            $customer['customer_name'],
            formatCurrency($customer['total_revenue']),
            $customer['total_sales']
        ]);
    }

    fclose($output);
    exit;
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-chart-line me-2"></i>Financial Report</h2>
                <div>
                    <a href="?export=csv&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="btn btn-success me-2">
                        <i class="fas fa-download me-1"></i>Export CSV
                    </a>
                    <a href="reports.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back to Reports
                    </a>
                </div>
            </div>

            <!-- Date Filter -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?= $start_date ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?= $end_date ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary d-block">
                                <i class="fas fa-filter me-1"></i>Apply Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Financial Overview Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">Total Revenue</h6>
                                    <h4 class="mb-0"><?= formatCurrency($financial_overview['total_revenue']) ?></h4>
                                </div>
                                <i class="fas fa-dollar-sign fa-2x opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-danger text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">Total Expenses</h6>
                                    <h4 class="mb-0"><?= formatCurrency($financial_overview['total_expenses']) ?></h4>
                                </div>
                                <i class="fas fa-credit-card fa-2x opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-<?= $financial_overview['net_profit'] >= 0 ? 'info' : 'warning' ?> text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">Net Profit</h6>
                                    <h4 class="mb-0"><?= formatCurrency($financial_overview['net_profit']) ?></h4>
                                </div>
                                <i class="fas fa-chart-line fa-2x opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-secondary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-title">Profit Margin</h6>
                                    <h4 class="mb-0">
                                        <?= $financial_overview['total_revenue'] > 0 ? round(($financial_overview['net_profit'] / $financial_overview['total_revenue']) * 100, 1) : 0 ?>%
                                    </h4>
                                </div>
                                <i class="fas fa-percentage fa-2x opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Revenue vs Expenses Trend</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="financialTrendChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Expense Breakdown</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="expenseBreakdownChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Revenue by Product Type -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Revenue by Product Type</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Product Type</th>
                                            <th>Revenue</th>
                                            <th>Quantity</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($revenue_by_product as $product): ?>
                                        <tr>
                                            <td><?= ucfirst($product['product_type']) ?></td>
                                            <td><?= formatCurrency($product['revenue']) ?></td>
                                            <td><?= $product['quantity'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Customers -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Top Customers</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Customer</th>
                                            <th>Total Revenue</th>
                                            <th>Sales Count</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($top_customers as $customer): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($customer['customer_name']) ?></td>
                                            <td><?= formatCurrency($customer['total_revenue']) ?></td>
                                            <td><?= $customer['total_sales'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Expense Breakdown -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Detailed Expense Breakdown</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Amount</th>
                                    <th>Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expense_breakdown as $expense): ?>
                                <tr>
                                    <td><?= $expense['category'] ?></td>
                                    <td><?= formatCurrency($expense['amount']) ?></td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar" role="progressbar" style="width: <?= $expense['percentage'] ?>%">
                                                <?= $expense['percentage'] ?>%
                                            </div>
                                        </div>
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
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Financial Trend Chart
const ctx1 = document.getElementById('financialTrendChart').getContext('2d');
const financialTrendChart = new Chart(ctx1, {
    type: 'line',
    data: {
        labels: [
            <?php
            $all_periods = array_unique(array_merge(
                array_column($revenue_trend, 'label'),
                array_column($expenses_trend, 'label')
            ));
            sort($all_periods);
            echo '"' . implode('","', $all_periods) . '"';
            ?>
        ],
        datasets: [{
            label: 'Revenue',
            data: [
                <?php
                $revenue_data = [];
                foreach ($all_periods as $period) {
                    $found = false;
                    foreach ($revenue_trend as $r) {
                        if ($r['label'] === $period) {
                            $revenue_data[] = $r['revenue'];
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) $revenue_data[] = 0;
                }
                echo implode(',', $revenue_data);
                ?>
            ],
            borderColor: 'rgb(40, 167, 69)',
            backgroundColor: 'rgba(40, 167, 69, 0.1)',
            tension: 0.1
        }, {
            label: 'Expenses',
            data: [
                <?php
                $expenses_data = [];
                foreach ($all_periods as $period) {
                    $found = false;
                    foreach ($expenses_trend as $e) {
                        if ($e['label'] === $period) {
                            $expenses_data[] = $e['expenses'];
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) $expenses_data[] = 0;
                }
                echo implode(',', $expenses_data);
                ?>
            ],
            borderColor: 'rgb(220, 53, 69)',
            backgroundColor: 'rgba(220, 53, 69, 0.1)',
            tension: 0.1
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'top',
            },
            title: {
                display: true,
                text: 'Monthly Revenue vs Expenses'
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return 'KES ' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Expense Breakdown Chart
const ctx2 = document.getElementById('expenseBreakdownChart').getContext('2d');
const expenseBreakdownChart = new Chart(ctx2, {
    type: 'doughnut',
    data: {
        labels: [
            <?php
            $labels = array_column($expense_breakdown, 'category');
            echo '"' . implode('","', $labels) . '"';
            ?>
        ],
        datasets: [{
            data: [
                <?php
                $amounts = array_column($expense_breakdown, 'amount');
                echo implode(',', $amounts);
                ?>
            ],
            backgroundColor: [
                'rgb(255, 193, 7)',
                'rgb(23, 162, 184)',
                'rgb(108, 117, 125)'
            ],
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'bottom',
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = context.parsed;
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                        return label + ': KES ' + value.toLocaleString() + ' (' + percentage + '%)';
                    }
                }
            }
        }
    }
});
</script>

<?php include '../../includes/footer.php'; ?>