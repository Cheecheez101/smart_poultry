<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';

requireLogin();

$page_title = 'Inventory Report';

// Filters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$feed_type_filter = trim($_GET['feed_type'] ?? '');
$expiring_days = (int)($_GET['expiring_days'] ?? 30);
if ($expiring_days < 1) {
    $expiring_days = 30;
}

// Feed types for dropdown
$feed_types = $pdo->query("SELECT DISTINCT feed_type FROM feed_inventory ORDER BY feed_type ASC")->fetchAll();

// Current inventory snapshot (optionally filter by feed_type)
$where = 'WHERE 1=1';
$params = [];
if ($feed_type_filter !== '') {
    $where .= ' AND fi.feed_type = ?';
    $params[] = $feed_type_filter;
}

$stmt = $pdo->prepare("
    SELECT fi.*, s.supplier_name
    FROM feed_inventory fi
    LEFT JOIN suppliers s ON fi.supplier_id = s.id
    $where
    ORDER BY fi.feed_type ASC, fi.id DESC
");
$stmt->execute($params);
$inventory_items = $stmt->fetchAll();

// Inventory stats
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_items,
        COALESCE(SUM(fi.quantity), 0) as total_quantity,
        COALESCE(SUM(fi.quantity * COALESCE(fi.unit_price, 0)), 0) as total_value,
        COALESCE(SUM(CASE WHEN fi.quantity <= fi.reorder_level THEN 1 ELSE 0 END), 0) as low_stock_items,
        COALESCE(SUM(CASE WHEN fi.expiry_date IS NOT NULL AND fi.expiry_date < CURDATE() AND fi.quantity > 0 THEN 1 ELSE 0 END), 0) as expired_items,
        COALESCE(SUM(CASE WHEN fi.expiry_date IS NOT NULL AND fi.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) AND fi.quantity > 0 THEN 1 ELSE 0 END), 0) as expiring_soon_items
    FROM feed_inventory fi
    $where
");
$stmt->execute(array_merge([$expiring_days], $params));
$stats = $stmt->fetch();

// Stock by feed type (for chart)
$stmt = $pdo->prepare("
    SELECT
        fi.feed_type,
        COALESCE(SUM(fi.quantity), 0) as qty,
        COALESCE(SUM(fi.quantity * COALESCE(fi.unit_price, 0)), 0) as value
    FROM feed_inventory fi
    $where
    GROUP BY fi.feed_type
    ORDER BY qty DESC
");
$stmt->execute($params);
$stock_by_type = $stmt->fetchAll();

// Consumption summary for date range (optionally filter by feed_type)
$cons_where = "WHERE fc.consumption_date BETWEEN ? AND ?";
$cons_params = [$date_from, $date_to];
if ($feed_type_filter !== '') {
    $cons_where .= " AND fi.feed_type = ?";
    $cons_params[] = $feed_type_filter;
}

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(fc.quantity_kg), 0) as total_consumed_kg,
        COALESCE(SUM(fc.quantity_kg * COALESCE(fi.unit_price, 0)), 0) as total_consumed_value
    FROM feed_consumption fc
    LEFT JOIN feed_inventory fi ON fc.feed_id = fi.id
    $cons_where
");
$stmt->execute($cons_params);
$cons_summary = $stmt->fetch();

// Daily consumption trend (for chart)
$stmt = $pdo->prepare("
    SELECT
        fc.consumption_date as day,
        COALESCE(SUM(fc.quantity_kg), 0) as qty_kg
    FROM feed_consumption fc
    LEFT JOIN feed_inventory fi ON fc.feed_id = fi.id
    $cons_where
    GROUP BY fc.consumption_date
    ORDER BY fc.consumption_date ASC
");
$stmt->execute($cons_params);
$cons_trend = $stmt->fetchAll();

// Top consuming flocks (for table)
$stmt = $pdo->prepare("
    SELECT
        f.batch_number,
        f.breed,
        COALESCE(SUM(fc.quantity_kg), 0) as qty_kg
    FROM feed_consumption fc
    JOIN flocks f ON fc.flock_id = f.id
    LEFT JOIN feed_inventory fi ON fc.feed_id = fi.id
    $cons_where
    GROUP BY f.id, f.batch_number, f.breed
    ORDER BY qty_kg DESC
    LIMIT 10
");
$stmt->execute($cons_params);
$top_flocks = $stmt->fetchAll();

// Reorder list
$stmt = $pdo->prepare("
    SELECT fi.*, s.supplier_name
    FROM feed_inventory fi
    LEFT JOIN suppliers s ON fi.supplier_id = s.id
    $where
    AND fi.quantity <= fi.reorder_level
    ORDER BY (fi.reorder_level - fi.quantity) DESC, fi.feed_type ASC
");
$stmt->execute($params);
$reorder_items = $stmt->fetchAll();

// Expiring soon list
$stmt = $pdo->prepare("
    SELECT fi.*, s.supplier_name
    FROM feed_inventory fi
    LEFT JOIN suppliers s ON fi.supplier_id = s.id
    $where
    AND fi.expiry_date IS NOT NULL
    AND fi.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
    AND fi.quantity > 0
    ORDER BY fi.expiry_date ASC
");
$stmt->execute(array_merge($params, [$expiring_days]));
$expiring_items = $stmt->fetchAll();

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory_report_' . $date_from . '_to_' . $date_to . '.csv"');

    $out = fopen('php://output', 'w');

    fputcsv($out, ['Inventory Report']);
    fputcsv($out, ['Date Range', $date_from . ' to ' . $date_to]);
    fputcsv($out, ['Feed Type Filter', $feed_type_filter !== '' ? $feed_type_filter : 'All']);
    fputcsv($out, []);

    fputcsv($out, ['Summary']);
    fputcsv($out, ['Metric', 'Value']);
    fputcsv($out, ['Total Items', $stats['total_items']]);
    fputcsv($out, ['Total Quantity (kg)', $stats['total_quantity']]);
    fputcsv($out, ['Total Stock Value', formatCurrency($stats['total_value'])]);
    fputcsv($out, ['Low Stock Items', $stats['low_stock_items']]);
    fputcsv($out, ['Expired Items', $stats['expired_items']]);
    fputcsv($out, ['Expiring Soon Items', $stats['expiring_soon_items']]);
    fputcsv($out, ['Consumed (kg)', $cons_summary['total_consumed_kg']]);
    fputcsv($out, ['Consumed Value', formatCurrency($cons_summary['total_consumed_value'])]);
    fputcsv($out, []);

    fputcsv($out, ['Current Stock']);
    fputcsv($out, ['Feed Type', 'Supplier', 'Quantity (kg)', 'Unit Price', 'Value', 'Reorder Level', 'Expiry Date', 'Storage Location']);
    foreach ($inventory_items as $item) {
        $unit = $item['unit_price'] ?? 0;
        $value = ((float)$item['quantity']) * ((float)$unit);
        fputcsv($out, [
            $item['feed_type'],
            $item['supplier_name'] ?? '',
            $item['quantity'],
            $unit !== null ? $unit : '',
            $value,
            $item['reorder_level'],
            $item['expiry_date'] ?? '',
            $item['storage_location'] ?? ''
        ]);
    }

    fclose($out);
    exit;
}

include '../../includes/header.php';

$chart_stock_labels = array_map(fn($r) => $r['feed_type'], $stock_by_type);
$chart_stock_qty = array_map(fn($r) => (float)$r['qty'], $stock_by_type);

$chart_cons_labels = array_map(fn($r) => $r['day'], $cons_trend);
$chart_cons_qty = array_map(fn($r) => (float)$r['qty_kg'], $cons_trend);
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0"><i class="fas fa-warehouse me-2"></i>Inventory Report</h2>
        <div class="d-flex gap-2">
            <a class="btn btn-success" href="?export=csv&date_from=<?= htmlspecialchars($date_from) ?>&date_to=<?= htmlspecialchars($date_to) ?>&feed_type=<?= urlencode($feed_type_filter) ?>&expiring_days=<?= (int)$expiring_days ?>">
                <i class="fas fa-download me-1"></i>Export CSV
            </a>
            <a class="btn btn-secondary" href="reports.php"><i class="fas fa-arrow-left me-1"></i>Back to Reports</a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Feed Type</label>
                    <select name="feed_type" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($feed_types as $ft): ?>
                            <option value="<?= htmlspecialchars($ft['feed_type']) ?>" <?= $feed_type_filter === $ft['feed_type'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ft['feed_type']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Expiring Within (days)</label>
                    <input type="number" min="1" name="expiring_days" class="form-control" value="<?= (int)$expiring_days ?>">
                </div>
                <div class="col-md-1 d-grid">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-filter me-1"></i>Go</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Total Items</div>
                    <div class="h4 mb-0"><?= (int)$stats['total_items'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Total Quantity</div>
                    <div class="h4 mb-0"><?= number_format((float)$stats['total_quantity'], 2) ?> kg</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Stock Value</div>
                    <div class="h4 mb-0"><?= formatCurrency((float)$stats['total_value']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Low Stock</div>
                    <div class="h4 mb-0 text-warning"><?= (int)$stats['low_stock_items'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Expired</div>
                    <div class="h4 mb-0 text-danger"><?= (int)$stats['expired_items'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Consumed (Range)</div>
                    <div class="h4 mb-0"><?= number_format((float)$cons_summary['total_consumed_kg'], 2) ?> kg</div>
                    <div class="small text-muted"><?= formatCurrency((float)$cons_summary['total_consumed_value']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong>Stock by Feed Type</strong></div>
                <div class="card-body"><canvas id="stockByTypeChart" height="140"></canvas></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong>Daily Feed Consumption</strong></div>
                <div class="card-body"><canvas id="consumptionTrendChart" height="140"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong>Reorder List</strong></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Feed</th>
                                    <th>Qty (kg)</th>
                                    <th>Reorder Level</th>
                                    <th>Supplier</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($reorder_items)): ?>
                                    <tr><td colspan="4" class="text-muted">No low stock items.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($reorder_items as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($item['feed_type']) ?></td>
                                            <td class="text-warning fw-semibold"><?= number_format((float)$item['quantity'], 2) ?></td>
                                            <td><?= number_format((float)$item['reorder_level'], 2) ?></td>
                                            <td><?= htmlspecialchars($item['supplier_name'] ?? '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong>Expiring Soon (<?= (int)$expiring_days ?> days)</strong></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Feed</th>
                                    <th>Qty (kg)</th>
                                    <th>Expiry</th>
                                    <th>Supplier</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($expiring_items)): ?>
                                    <tr><td colspan="4" class="text-muted">No expiring items in this window.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($expiring_items as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($item['feed_type']) ?></td>
                                            <td><?= number_format((float)$item['quantity'], 2) ?></td>
                                            <td class="text-danger fw-semibold"><?= htmlspecialchars($item['expiry_date']) ?></td>
                                            <td><?= htmlspecialchars($item['supplier_name'] ?? '-') ?></td>
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

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Top Consuming Flocks (<?= htmlspecialchars($date_from) ?> to <?= htmlspecialchars($date_to) ?>)</strong></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Batch</th>
                            <th>Breed</th>
                            <th>Consumed (kg)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($top_flocks)): ?>
                            <tr><td colspan="3" class="text-muted">No consumption records in this date range.</td></tr>
                        <?php else: ?>
                            <?php foreach ($top_flocks as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['batch_number']) ?></td>
                                    <td><?= htmlspecialchars($row['breed']) ?></td>
                                    <td><?= number_format((float)$row['qty_kg'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white"><strong>Current Stock Details</strong></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Feed Type</th>
                            <th>Supplier</th>
                            <th>Quantity (kg)</th>
                            <th>Unit Price</th>
                            <th>Value</th>
                            <th>Reorder Level</th>
                            <th>Expiry</th>
                            <th>Storage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inventory_items)): ?>
                            <tr><td colspan="8" class="text-muted">No inventory items found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($inventory_items as $item): ?>
                                <?php
                                    $unit_price = $item['unit_price'] ?? 0;
                                    $value = ((float)$item['quantity']) * ((float)$unit_price);
                                    $is_low = ((float)$item['quantity']) <= ((float)$item['reorder_level']);
                                    $is_expired = !empty($item['expiry_date']) && $item['expiry_date'] < date('Y-m-d') && ((float)$item['quantity'] > 0);
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['feed_type']) ?></td>
                                    <td><?= htmlspecialchars($item['supplier_name'] ?? '-') ?></td>
                                    <td class="<?= $is_low ? 'text-warning fw-semibold' : '' ?>"><?= number_format((float)$item['quantity'], 2) ?></td>
                                    <td><?= $item['unit_price'] !== null ? formatCurrency((float)$item['unit_price']) : '-' ?></td>
                                    <td><?= formatCurrency((float)$value) ?></td>
                                    <td><?= number_format((float)$item['reorder_level'], 2) ?></td>
                                    <td class="<?= $is_expired ? 'text-danger fw-semibold' : '' ?>"><?= htmlspecialchars($item['expiry_date'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($item['storage_location'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const stockLabels = <?= json_encode($chart_stock_labels) ?>;
const stockQty = <?= json_encode($chart_stock_qty) ?>;

const consLabels = <?= json_encode($chart_cons_labels) ?>;
const consQty = <?= json_encode($chart_cons_qty) ?>;

new Chart(document.getElementById('stockByTypeChart'), {
    type: 'bar',
    data: {
        labels: stockLabels,
        datasets: [{
            label: 'Quantity (kg)',
            data: stockQty,
            backgroundColor: 'rgba(52, 152, 219, 0.35)',
            borderColor: 'rgba(52, 152, 219, 1)',
            borderWidth: 1,
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true }
        }
    }
});

new Chart(document.getElementById('consumptionTrendChart'), {
    type: 'line',
    data: {
        labels: consLabels,
        datasets: [{
            label: 'Consumed (kg)',
            data: consQty,
            borderColor: 'rgba(39, 174, 96, 1)',
            backgroundColor: 'rgba(39, 174, 96, 0.12)',
            fill: true,
            tension: 0.25
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true }
        }
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
