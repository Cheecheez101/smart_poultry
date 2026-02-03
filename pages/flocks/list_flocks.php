<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';

$page_title = 'Flock List';

// Get flocks from database
try {
    $stmt = $pdo->query("
        SELECT f.*, 
               COALESCE(SUM(p.eggs_collected), 0) as total_eggs,
               (f.initial_count - f.current_count) as total_deaths,
               DATEDIFF(CURDATE(), f.arrival_date) as age_days
        FROM flocks f
        LEFT JOIN egg_production p ON f.id = p.flock_id
        GROUP BY f.id
        ORDER BY f.created_at DESC
    ");
    $flocks = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error loading flocks: " . $e->getMessage();
    $flocks = [];
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Flock Management</h1>
        <a href="add_flock.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add New Flock
        </a>
    </div>

    <!-- Search and Filter -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="text" class="form-control" id="searchFlocks" placeholder="Search flocks...">
            </div>
        </div>
        <div class="col-md-3">
            <select class="form-select" id="filterStatus">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="sold">Sold</option>
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select" id="filterPurpose">
                <option value="">All Purpose</option>
                <option value="layers">Layers</option>
                <option value="broilers">Broilers</option>
                <option value="breeders">Breeders</option>
            </select>
        </div>
    </div>

    <!-- Flocks Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Flock Details</h6>
        </div>
        <div class="card-body">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php elseif (empty($flocks)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-dove fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No flocks found</h5>
                    <p class="text-muted">Start by adding your first flock</p>
                    <a href="add_flock.php" class="btn btn-primary">Add Flock</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="flocksTable">
                        <thead class="table-light">
                            <tr>
                                <th>Flock Name</th>
                                <th>Breed</th>
                                <th>Current Count</th>
                                <th>Age (Weeks)</th>
                                <th>Purpose</th>
                                <th>Total Eggs</th>
                                <th>Deaths</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($flocks as $flock): ?>
                            <tr data-searchable>
                                <td>
                                    <strong><?php echo htmlspecialchars($flock['batch_number']); ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        Added: <?php echo formatDate($flock['created_at']); ?>
                                    </small>
                                </td>
                                <td><?php echo htmlspecialchars($flock['breed']); ?></td>
                                <td>
                                    <?php echo formatNumber($flock['current_count']); ?>
                                    <small class="text-muted">
                                        / <?php echo formatNumber($flock['initial_count']); ?>
                                    </small>
                                </td>
                                <td><?php echo floor($flock['age_days'] / 7); ?> weeks</td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php echo ucfirst(str_replace('_', ' ', $flock['purpose'] ?? 'egg_production')); ?>
                                    </span>
                                </td>
                                <td><?php echo formatNumber($flock['total_eggs']); ?></td>
                                <td>
                                    <?php echo formatNumber($flock['total_deaths']); ?>
                                    <?php if ($flock['initial_count'] > 0): ?>
                                        <small class="text-muted">
                                            (<?php echo getMortalityRate($flock['total_deaths'], $flock['initial_count']); ?>%)
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="<?php echo getStatusBadge($flock['status'] ?? 'active'); ?>">
                                        <?php echo ucfirst($flock['status'] ?? 'active'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <a href="edit_flock.php?id=<?php echo $flock['id']; ?>" 
                                           class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="../production/record_production.php?flock_id=<?php echo $flock['id']; ?>" 
                                           class="btn btn-sm btn-outline-success" title="Record Production">
                                            <i class="fas fa-egg"></i>
                                        </a>
                                        <a href="../medication/log_treatment.php?flock_id=<?php echo $flock['id']; ?>" 
                                           class="btn btn-sm btn-outline-info" title="Log Treatment">
                                            <i class="fas fa-pills"></i>
                                        </a>
                                        <a href="delete_flock.php?id=<?php echo $flock['id']; ?>" 
                                           class="btn btn-sm btn-outline-danger" 
                                           title="Delete"
                                           data-confirm="Are you sure you want to delete this flock? This action cannot be undone.">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Summary Statistics -->
                <div class="row mt-4">
                    <div class="col-md-3">
                        <div class="card border-left-primary">
                            <div class="card-body">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                    Total Flocks
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo count($flocks); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-left-success">
                            <div class="card-body">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                    Active Flocks
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo count(array_filter($flocks, fn($f) => ($f['status'] ?? 'active') === 'active')); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-left-info">
                            <div class="card-body">
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                    Total Birds
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo formatNumber(array_sum(array_column($flocks, 'current_count'))); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-left-warning">
                            <div class="card-body">
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                    Total Eggs
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo formatNumber(array_sum(array_column($flocks, 'total_eggs'))); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Search functionality
    document.getElementById('searchFlocks').addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = document.querySelectorAll('#flocksTable tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchTerm) ? '' : 'none';
        });
    });

    // Filter by status
    document.getElementById('filterStatus').addEventListener('change', function() {
        const filterValue = this.value.toLowerCase();
        const rows = document.querySelectorAll('#flocksTable tbody tr');
        
        rows.forEach(row => {
            if (!filterValue) {
                row.style.display = '';
            } else {
                const statusCell = row.cells[7].textContent.toLowerCase();
                row.style.display = statusCell.includes(filterValue) ? '' : 'none';
            }
        });
    });

    // Filter by purpose
    document.getElementById('filterPurpose').addEventListener('change', function() {
        const filterValue = this.value.toLowerCase();
        const rows = document.querySelectorAll('#flocksTable tbody tr');
        
        rows.forEach(row => {
            if (!filterValue) {
                row.style.display = '';
            } else {
                const purposeCell = row.cells[4].textContent.toLowerCase();
                row.style.display = purposeCell.includes(filterValue) ? '' : 'none';
            }
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>