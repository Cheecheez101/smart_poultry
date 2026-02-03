<?php
require_once '../../includes/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Require login
requireLogin();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        try {
            if ($action === 'add') {
                $stmt = $pdo->prepare("
                    INSERT INTO medications (flock_id, medication_name, medication_type, 
                    administration_date, next_due_date, dosage, administered_by, cost, notes, recorded_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['flock_id'],
                    $_POST['medication_name'],
                    $_POST['medication_type'],
                    $_POST['administration_date'],
                    !empty($_POST['next_due_date']) ? $_POST['next_due_date'] : null,
                    $_POST['dosage'],
                    $_POST['administered_by'],
                    !empty($_POST['cost']) ? $_POST['cost'] : null,
                    $_POST['notes'] ?? '',
                    $_SESSION['user_id']
                ]);
                
                logActivity('CREATE', 'medications', $pdo->lastInsertId(), 'Added medication record');
                $_SESSION['success_message'] = 'Medication record added successfully!';
                
            } elseif ($action === 'edit') {
                $stmt = $pdo->prepare("
                    UPDATE medications SET 
                    flock_id = ?, medication_name = ?, medication_type = ?, 
                    administration_date = ?, next_due_date = ?, dosage = ?, 
                    administered_by = ?, cost = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['flock_id'],
                    $_POST['medication_name'],
                    $_POST['medication_type'],
                    $_POST['administration_date'],
                    !empty($_POST['next_due_date']) ? $_POST['next_due_date'] : null,
                    $_POST['dosage'],
                    $_POST['administered_by'],
                    !empty($_POST['cost']) ? $_POST['cost'] : null,
                    $_POST['notes'] ?? '',
                    $_POST['medication_id']
                ]);
                
                logActivity('UPDATE', 'medications', $_POST['medication_id'], 'Updated medication record');
                $_SESSION['success_message'] = 'Medication record updated successfully!';
                
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM medications WHERE id = ?");
                $stmt->execute([$_POST['medication_id']]);
                
                logActivity('DELETE', 'medications', $_POST['medication_id'], 'Deleted medication record');
                $_SESSION['success_message'] = 'Medication record deleted successfully!';
            }
            
            header('Location: medication.php');
            exit;
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
        }
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$medication_type = $_GET['medication_type'] ?? '';
$flock_filter = $_GET['flock_id'] ?? '';
$upcoming_only = isset($_GET['upcoming_only']);

// Build query
$query = "
    SELECT m.*, f.batch_number, f.breed, u.full_name as recorded_by_name
    FROM medications m
    LEFT JOIN flocks f ON m.flock_id = f.id
    LEFT JOIN users u ON m.recorded_by = u.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $query .= " AND (m.medication_name LIKE ? OR m.administered_by LIKE ? OR f.batch_number LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if (!empty($medication_type)) {
    $query .= " AND m.medication_type = ?";
    $params[] = $medication_type;
}

if (!empty($flock_filter)) {
    $query .= " AND m.flock_id = ?";
    $params[] = $flock_filter;
}

if ($upcoming_only) {
    $query .= " AND m.next_due_date IS NOT NULL AND m.next_due_date >= CURDATE() AND m.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
}

$query .= " ORDER BY m.administration_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$medications = $stmt->fetchAll();

// Get statistics
$stats = [
    'total_records' => 0,
    'total_cost' => 0,
    'upcoming_due' => 0,
    'this_month' => 0
];

$stmt = $pdo->query("SELECT COUNT(*) as total, COALESCE(SUM(cost), 0) as total_cost FROM medications");
$result = $stmt->fetch();
$stats['total_records'] = $result['total'];
$stats['total_cost'] = $result['total_cost'];

$stmt = $pdo->query("
    SELECT COUNT(*) as count 
    FROM medications 
    WHERE next_due_date IS NOT NULL 
    AND next_due_date >= CURDATE() 
    AND next_due_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
");
$stats['upcoming_due'] = $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*) as count 
    FROM medications 
    WHERE MONTH(administration_date) = MONTH(CURDATE()) 
    AND YEAR(administration_date) = YEAR(CURDATE())
");
$stats['this_month'] = $stmt->fetchColumn();

// Get flocks for dropdowns
$flocks = $pdo->query("SELECT id, batch_number, breed FROM flocks ORDER BY batch_number")->fetchAll();

$pageTitle = 'Medication Management';
include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2><i class="bi bi-prescription2"></i> Medication & Vaccination Management</h2>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMedicationModal">
                <i class="bi bi-plus-circle"></i> Add Medication Record
            </button>
        </div>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['success_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['error_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Records</h5>
                    <h2><?= number_format($stats['total_records']) ?></h2>
                    <small>All medication entries</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Cost</h5>
                    <h2>KSh <?= number_format($stats['total_cost'], 2) ?></h2>
                    <small>Total medication expenses</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Upcoming Due</h5>
                    <h2><?= number_format($stats['upcoming_due']) ?></h2>
                    <small>Due in next 30 days</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">This Month</h5>
                    <h2><?= number_format($stats['this_month']) ?></h2>
                    <small>Records this month</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Medication, administrator..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select name="medication_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="vaccine" <?= $medication_type === 'vaccine' ? 'selected' : '' ?>>Vaccine</option>
                        <option value="antibiotic" <?= $medication_type === 'antibiotic' ? 'selected' : '' ?>>Antibiotic</option>
                        <option value="vitamin" <?= $medication_type === 'vitamin' ? 'selected' : '' ?>>Vitamin</option>
                        <option value="other" <?= $medication_type === 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Flock</label>
                    <select name="flock_id" class="form-select">
                        <option value="">All Flocks</option>
                        <?php foreach ($flocks as $flock): ?>
                            <option value="<?= $flock['id'] ?>" <?= $flock_filter == $flock['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($flock['batch_number']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="form-check">
                        <input type="checkbox" name="upcoming_only" class="form-check-input" id="upcomingOnly" <?= $upcoming_only ? 'checked' : '' ?>>
                        <label class="form-check-label" for="upcomingOnly">
                            Upcoming Due Only
                        </label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Filter
                        </button>
                        <a href="medication.php" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Medications Table -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Flock</th>
                            <th>Medication</th>
                            <th>Type</th>
                            <th>Dosage</th>
                            <th>Administered By</th>
                            <th>Cost</th>
                            <th>Next Due</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($medications)): ?>
                            <tr>
                                <td colspan="9" class="text-center">No medication records found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($medications as $med): ?>
                                <?php 
                                    $isDueSoon = false;
                                    if ($med['next_due_date']) {
                                        $daysUntilDue = (strtotime($med['next_due_date']) - time()) / (60 * 60 * 24);
                                        $isDueSoon = $daysUntilDue >= 0 && $daysUntilDue <= 30;
                                    }
                                ?>
                                <tr class="<?= $isDueSoon ? 'table-warning' : '' ?>">
                                    <td><?= date('M d, Y', strtotime($med['administration_date'])) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($med['batch_number']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($med['breed']) ?></small>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($med['medication_name']) ?></strong>
                                        <?php if ($med['notes']): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars(substr($med['notes'], 0, 50)) ?><?= strlen($med['notes']) > 50 ? '...' : '' ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $badgeClass = [
                                                'vaccine' => 'bg-primary',
                                                'antibiotic' => 'bg-danger',
                                                'vitamin' => 'bg-success',
                                                'other' => 'bg-secondary'
                                            ];
                                        ?>
                                        <span class="badge <?= $badgeClass[$med['medication_type']] ?? 'bg-secondary' ?>">
                                            <?= ucfirst($med['medication_type']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($med['dosage']) ?></td>
                                    <td><?= htmlspecialchars($med['administered_by']) ?></td>
                                    <td>
                                        <?= $med['cost'] ? 'KSh ' . number_format($med['cost'], 2) : '-' ?>
                                    </td>
                                    <td>
                                        <?php if ($med['next_due_date']): ?>
                                            <?= date('M d, Y', strtotime($med['next_due_date'])) ?>
                                            <?php if ($isDueSoon): ?>
                                                <br><small class="text-warning"><i class="bi bi-exclamation-triangle"></i> Due soon</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick='editMedication(<?= json_encode($med) ?>)'>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this record?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="medication_id" value="<?= $med['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="bi bi-trash"></i>
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

<!-- Add Medication Modal -->
<div class="modal fade" id="addMedicationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Medication Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Flock *</label>
                            <select name="flock_id" class="form-select" required>
                                <option value="">Select Flock</option>
                                <?php foreach ($flocks as $flock): ?>
                                    <option value="<?= $flock['id'] ?>">
                                        <?= htmlspecialchars($flock['batch_number']) ?> - <?= htmlspecialchars($flock['breed']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Medication Name *</label>
                            <input type="text" name="medication_name" class="form-control" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Type *</label>
                            <select name="medication_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="vaccine">Vaccine</option>
                                <option value="antibiotic">Antibiotic</option>
                                <option value="vitamin">Vitamin</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Administration Date *</label>
                            <input type="date" name="administration_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Dosage *</label>
                            <input type="text" name="dosage" class="form-control" placeholder="e.g., 1ml per bird" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Next Due Date</label>
                            <input type="date" name="next_due_date" class="form-control">
                            <small class="text-muted">Leave empty if not recurring</small>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Administered By *</label>
                            <input type="text" name="administered_by" class="form-control" placeholder="Name of person" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Cost (KSh)</label>
                            <input type="number" name="cost" class="form-control" step="0.01" min="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Medication Modal -->
<div class="modal fade" id="editMedicationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Medication Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="medication_id" id="edit_medication_id">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Flock *</label>
                            <select name="flock_id" id="edit_flock_id" class="form-select" required>
                                <option value="">Select Flock</option>
                                <?php foreach ($flocks as $flock): ?>
                                    <option value="<?= $flock['id'] ?>">
                                        <?= htmlspecialchars($flock['batch_number']) ?> - <?= htmlspecialchars($flock['breed']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Medication Name *</label>
                            <input type="text" name="medication_name" id="edit_medication_name" class="form-control" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Type *</label>
                            <select name="medication_type" id="edit_medication_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="vaccine">Vaccine</option>
                                <option value="antibiotic">Antibiotic</option>
                                <option value="vitamin">Vitamin</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Administration Date *</label>
                            <input type="date" name="administration_date" id="edit_administration_date" class="form-control" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Dosage *</label>
                            <input type="text" name="dosage" id="edit_dosage" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Next Due Date</label>
                            <input type="date" name="next_due_date" id="edit_next_due_date" class="form-control">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Administered By *</label>
                            <input type="text" name="administered_by" id="edit_administered_by" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Cost (KSh)</label>
                            <input type="number" name="cost" id="edit_cost" class="form-control" step="0.01" min="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editMedication(med) {
    document.getElementById('edit_medication_id').value = med.id;
    document.getElementById('edit_flock_id').value = med.flock_id;
    document.getElementById('edit_medication_name').value = med.medication_name;
    document.getElementById('edit_medication_type').value = med.medication_type;
    document.getElementById('edit_administration_date').value = med.administration_date;
    document.getElementById('edit_next_due_date').value = med.next_due_date || '';
    document.getElementById('edit_dosage').value = med.dosage;
    document.getElementById('edit_administered_by').value = med.administered_by;
    document.getElementById('edit_cost').value = med.cost || '';
    document.getElementById('edit_notes').value = med.notes || '';
    
    new bootstrap.Modal(document.getElementById('editMedicationModal')).show();
}
</script>

<?php include '../../includes/footer.php'; ?>
