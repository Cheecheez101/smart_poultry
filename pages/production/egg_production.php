<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';

requireLogin();

$page_title = 'Egg Production Records';
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO egg_production 
                (flock_id, production_date, eggs_collected, eggs_broken, eggs_sold, eggs_stored, average_weight, notes, recorded_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $eggs_stored = $_POST['eggs_collected'] - $_POST['eggs_broken'] - $_POST['eggs_sold'];
            
            $stmt->execute([
                $_POST['flock_id'],
                $_POST['production_date'],
                $_POST['eggs_collected'],
                $_POST['eggs_broken'] ?? 0,
                $_POST['eggs_sold'] ?? 0,
                $eggs_stored,
                $_POST['average_weight'] ?? null,
                $_POST['notes'] ?? '',
                $_SESSION['user_id']
            ]);
            
            $success_message = 'Egg production record added successfully!';
            logActivity($_SESSION['user_id'], 'Added egg production record', 'egg_production', $pdo->lastInsertId());
        } catch (PDOException $e) {
            $error_message = 'Error adding record: ' . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'delete' && isset($_POST['id'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM egg_production WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $success_message = 'Record deleted successfully!';
            logActivity($_SESSION['user_id'], 'Deleted egg production record', 'egg_production', $_POST['id']);
        } catch (PDOException $e) {
            $error_message = 'Error deleting record: ' . $e->getMessage();
        }
    }
}

// Get production records with flock info
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$flock_filter = $_GET['flock_id'] ?? '';

$query = "
    SELECT ep.*, f.batch_number, f.breed, u.full_name as recorded_by_name
    FROM egg_production ep
    LEFT JOIN flocks f ON ep.flock_id = f.id
    LEFT JOIN users u ON ep.recorded_by = u.id
    WHERE 1=1
";

$params = [];

if ($search) {
    $query .= " AND (f.batch_number LIKE ? OR f.breed LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($date_from) {
    $query .= " AND ep.production_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND ep.production_date <= ?";
    $params[] = $date_to;
}

if ($flock_filter) {
    $query .= " AND ep.flock_id = ?";
    $params[] = $flock_filter;
}

$query .= " ORDER BY ep.production_date DESC, ep.recorded_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$production_records = $stmt->fetchAll();

// Get active flocks for dropdown
$stmt = $pdo->query("SELECT id, batch_number, breed, current_count FROM flocks ORDER BY batch_number");
$active_flocks = $stmt->fetchAll();

// Calculate summary statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_records,
        SUM(eggs_collected) as total_collected,
        SUM(eggs_broken) as total_broken,
        SUM(eggs_sold) as total_sold,
        SUM(eggs_stored) as total_stored,
        AVG(eggs_collected) as avg_daily_collection
    FROM egg_production
    WHERE production_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$stmt->execute();
$stats = $stmt->fetch();

include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="fas fa-egg text-warning"></i> Egg Production Records
        </h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductionModal">
            <i class="fas fa-plus"></i> Record Production
        </button>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Total Collected (30 Days)
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_collected'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-egg fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Avg Daily Collection
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['avg_daily_collection'] ?? 0, 1); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-chart-line fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Broken Eggs
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_broken'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-exclamation-triangle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Eggs Sold
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_sold'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-shopping-cart fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Filter Records</h6>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Batch number or breed...">
                </div>
                <div class="col-md-2">
                    <label for="date_from" class="form-label">From Date</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" 
                           value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">To Date</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" 
                           value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-md-3">
                    <label for="flock_id" class="form-label">Flock</label>
                    <select class="form-select" id="flock_id" name="flock_id">
                        <option value="">All Flocks</option>
                        <?php foreach ($active_flocks as $flock): ?>
                            <option value="<?php echo $flock['id']; ?>" 
                                    <?php echo $flock_filter == $flock['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($flock['batch_number'] . ' - ' . $flock['breed']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="egg_production.php" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Production Records Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Production Records</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="productionTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Flock</th>
                            <th>Breed</th>
                            <th>Collected</th>
                            <th>Broken</th>
                            <th>Sold</th>
                            <th>Stored</th>
                            <th>Avg Weight (g)</th>
                            <th>Recorded By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($production_records)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted">No production records found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($production_records as $record): ?>
                                <tr>
                                    <td><?php echo formatDate($record['production_date'], 'M j, Y'); ?></td>
                                    <td><?php echo htmlspecialchars($record['batch_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($record['breed'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format($record['eggs_collected']); ?></td>
                                    <td>
                                        <span class="badge bg-danger">
                                            <?php echo number_format($record['eggs_broken']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-success">
                                            <?php echo number_format($record['eggs_sold']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($record['eggs_stored']); ?></td>
                                    <td><?php echo $record['average_weight'] ? number_format($record['average_weight'], 1) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars($record['recorded_by_name'] ?? 'Unknown'); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info" 
                                                onclick="viewDetails(<?php echo htmlspecialchars(json_encode($record)); ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" 
                                              onsubmit="return confirm('Are you sure you want to delete this record?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $record['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
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

<!-- Add Production Modal -->
<div class="modal fade" id="addProductionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Record Egg Production</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="mb-3">
                        <label for="flock_id" class="form-label">Flock *</label>
                        <select class="form-select" id="flock_id" name="flock_id" required>
                            <option value="">Select Flock</option>
                            <?php foreach ($active_flocks as $flock): ?>
                                <option value="<?php echo $flock['id']; ?>">
                                    <?php echo htmlspecialchars($flock['batch_number'] . ' - ' . $flock['breed'] . ' (' . $flock['current_count'] . ' birds)'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="production_date" class="form-label">Production Date *</label>
                        <input type="date" class="form-control" id="production_date" name="production_date" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="eggs_collected" class="form-label">Eggs Collected *</label>
                        <input type="number" class="form-control" id="eggs_collected" name="eggs_collected" 
                               min="0" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="eggs_broken" class="form-label">Eggs Broken</label>
                            <input type="number" class="form-control" id="eggs_broken" name="eggs_broken" 
                                   min="0" value="0">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="eggs_sold" class="form-label">Eggs Sold</label>
                            <input type="number" class="form-control" id="eggs_sold" name="eggs_sold" 
                                   min="0" value="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="average_weight" class="form-label">Average Weight (grams)</label>
                        <input type="number" class="form-control" id="average_weight" name="average_weight" 
                               step="0.1" min="0">
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Details Modal -->
<div class="modal fade" id="viewDetailsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Production Record Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsContent">
                <!-- Details will be populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function viewDetails(record) {
    const content = `
        <table class="table table-sm">
            <tr>
                <th>Date:</th>
                <td>${record.production_date}</td>
            </tr>
            <tr>
                <th>Flock:</th>
                <td>${record.batch_number} - ${record.breed}</td>
            </tr>
            <tr>
                <th>Eggs Collected:</th>
                <td>${record.eggs_collected}</td>
            </tr>
            <tr>
                <th>Eggs Broken:</th>
                <td>${record.eggs_broken}</td>
            </tr>
            <tr>
                <th>Eggs Sold:</th>
                <td>${record.eggs_sold}</td>
            </tr>
            <tr>
                <th>Eggs Stored:</th>
                <td>${record.eggs_stored}</td>
            </tr>
            <tr>
                <th>Average Weight:</th>
                <td>${record.average_weight ? record.average_weight + ' g' : 'N/A'}</td>
            </tr>
            <tr>
                <th>Recorded By:</th>
                <td>${record.recorded_by_name}</td>
            </tr>
            <tr>
                <th>Recorded At:</th>
                <td>${record.recorded_at}</td>
            </tr>
            ${record.notes ? `<tr><th>Notes:</th><td>${record.notes}</td></tr>` : ''}
        </table>
    `;
    
    document.getElementById('detailsContent').innerHTML = content;
    new bootstrap.Modal(document.getElementById('viewDetailsModal')).show();
}
</script>

<?php include '../../includes/footer.php'; ?>
