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
                    INSERT INTO suppliers (supplier_name, contact_person, phone, email, 
                    address, supply_type, payment_terms, account_balance, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['supplier_name'],
                    $_POST['contact_person'] ?? '',
                    $_POST['phone'] ?? '',
                    $_POST['email'] ?? '',
                    $_POST['address'] ?? '',
                    $_POST['supply_type'],
                    $_POST['payment_terms'] ?? '',
                    $_POST['account_balance'] ?? 0,
                    $_POST['status']
                ]);
                
                logActivity('CREATE', 'suppliers', $pdo->lastInsertId(), 'Added supplier');
                $_SESSION['success_message'] = 'Supplier added successfully!';
                
            } elseif ($action === 'edit') {
                $stmt = $pdo->prepare("
                    UPDATE suppliers SET 
                    supplier_name = ?, contact_person = ?, phone = ?, email = ?, 
                    address = ?, supply_type = ?, payment_terms = ?, account_balance = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['supplier_name'],
                    $_POST['contact_person'] ?? '',
                    $_POST['phone'] ?? '',
                    $_POST['email'] ?? '',
                    $_POST['address'] ?? '',
                    $_POST['supply_type'],
                    $_POST['payment_terms'] ?? '',
                    $_POST['account_balance'] ?? 0,
                    $_POST['status'],
                    $_POST['supplier_id']
                ]);
                
                logActivity('UPDATE', 'suppliers', $_POST['supplier_id'], 'Updated supplier');
                $_SESSION['success_message'] = 'Supplier updated successfully!';
                
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
                $stmt->execute([$_POST['supplier_id']]);
                
                logActivity('DELETE', 'suppliers', $_POST['supplier_id'], 'Deleted supplier');
                $_SESSION['success_message'] = 'Supplier deleted successfully!';
            }
            
            header('Location: suppliers.php');
            exit;
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
        }
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$supply_type = $_GET['supply_type'] ?? '';
$status = $_GET['status'] ?? '';

// Build query
$query = "SELECT * FROM suppliers WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (supplier_name LIKE ? OR contact_person LIKE ? OR phone LIKE ? OR email LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if (!empty($supply_type)) {
    $query .= " AND supply_type = ?";
    $params[] = $supply_type;
}

if (!empty($status)) {
    $query .= " AND status = ?";
    $params[] = $status;
}

$query .= " ORDER BY supplier_name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

// Get statistics
$stats = [
    'total_suppliers' => 0,
    'active_suppliers' => 0,
    'total_balance' => 0,
    'feed_suppliers' => 0
];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM suppliers");
$stats['total_suppliers'] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) as active FROM suppliers WHERE status = 'active'");
$stats['active_suppliers'] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COALESCE(SUM(account_balance), 0) as balance FROM suppliers");
$stats['total_balance'] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) as feed FROM suppliers WHERE supply_type = 'feed' AND status = 'active'");
$stats['feed_suppliers'] = $stmt->fetchColumn();

$pageTitle = 'Supplier Management';
include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2><i class="bi bi-truck"></i> Supplier Management</h2>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                <i class="bi bi-plus-circle"></i> Add Supplier
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
                    <h5 class="card-title">Total Suppliers</h5>
                    <h2><?= number_format($stats['total_suppliers']) ?></h2>
                    <small>All registered suppliers</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Active Suppliers</h5>
                    <h2><?= number_format($stats['active_suppliers']) ?></h2>
                    <small>Currently active</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Balance</h5>
                    <h2>KSh <?= number_format($stats['total_balance'], 2) ?></h2>
                    <small>Outstanding payables</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Feed Suppliers</h5>
                    <h2><?= number_format($stats['feed_suppliers']) ?></h2>
                    <small>Active feed suppliers</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Name, contact, phone, email..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Supply Type</label>
                    <select name="supply_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="feed" <?= $supply_type === 'feed' ? 'selected' : '' ?>>Feed</option>
                        <option value="medication" <?= $supply_type === 'medication' ? 'selected' : '' ?>>Medication</option>
                        <option value="equipment" <?= $supply_type === 'equipment' ? 'selected' : '' ?>>Equipment</option>
                        <option value="other" <?= $supply_type === 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Filter
                        </button>
                        <a href="suppliers.php" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Suppliers Table -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Supplier Name</th>
                            <th>Contact Person</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Supply Type</th>
                            <th>Payment Terms</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($suppliers)): ?>
                            <tr>
                                <td colspan="9" class="text-center">No suppliers found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($suppliers as $supplier): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($supplier['supplier_name']) ?></strong>
                                        <?php if ($supplier['address']): ?>
                                            <br><small class="text-muted"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars(substr($supplier['address'], 0, 30)) ?><?= strlen($supplier['address']) > 30 ? '...' : '' ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($supplier['contact_person']) ?: '-' ?></td>
                                    <td>
                                        <?php if ($supplier['phone']): ?>
                                            <i class="bi bi-telephone"></i> <?= htmlspecialchars($supplier['phone']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($supplier['email']): ?>
                                            <i class="bi bi-envelope"></i> <?= htmlspecialchars($supplier['email']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $typeBadges = [
                                                'feed' => 'bg-success',
                                                'medication' => 'bg-danger',
                                                'equipment' => 'bg-info',
                                                'other' => 'bg-secondary'
                                            ];
                                        ?>
                                        <span class="badge <?= $typeBadges[$supplier['supply_type']] ?? 'bg-secondary' ?>">
                                            <?= ucfirst($supplier['supply_type']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($supplier['payment_terms']) ?: '-' ?></td>
                                    <td>
                                        <?php if ($supplier['account_balance'] > 0): ?>
                                            <span class="text-danger">KSh <?= number_format($supplier['account_balance'], 2) ?></span>
                                        <?php elseif ($supplier['account_balance'] < 0): ?>
                                            <span class="text-success">KSh <?= number_format(abs($supplier['account_balance']), 2) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $supplier['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                            <?= ucfirst($supplier['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick='editSupplier(<?= json_encode($supplier) ?>)'>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this supplier?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="supplier_id" value="<?= $supplier['id'] ?>">
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

<!-- Add Supplier Modal -->
<div class="modal fade" id="addSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Supplier</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Supplier Name *</label>
                            <input type="text" name="supplier_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Person</label>
                            <input type="text" name="contact_person" class="form-control">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" placeholder="+254...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="2" placeholder="Physical address..."></textarea>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Supply Type *</label>
                            <select name="supply_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="feed">Feed</option>
                                <option value="medication">Medication</option>
                                <option value="equipment">Equipment</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-select" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Payment Terms</label>
                            <input type="text" name="payment_terms" class="form-control" placeholder="e.g., Net 30 days">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Account Balance (KSh)</label>
                            <input type="number" name="account_balance" class="form-control" step="0.01" value="0">
                            <small class="text-muted">Positive = We owe them, Negative = They owe us</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Supplier Modal -->
<div class="modal fade" id="editSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Supplier</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="supplier_id" id="edit_supplier_id">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Supplier Name *</label>
                            <input type="text" name="supplier_name" id="edit_supplier_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Contact Person</label>
                            <input type="text" name="contact_person" id="edit_contact_person" class="form-control">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" id="edit_phone" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="edit_email" class="form-control">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" id="edit_address" class="form-control" rows="2"></textarea>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Supply Type *</label>
                            <select name="supply_type" id="edit_supply_type" class="form-select" required>
                                <option value="">Select Type</option>
                                <option value="feed">Feed</option>
                                <option value="medication">Medication</option>
                                <option value="equipment">Equipment</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status *</label>
                            <select name="status" id="edit_status" class="form-select" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Payment Terms</label>
                            <input type="text" name="payment_terms" id="edit_payment_terms" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Account Balance (KSh)</label>
                            <input type="number" name="account_balance" id="edit_account_balance" class="form-control" step="0.01">
                            <small class="text-muted">Positive = We owe them, Negative = They owe us</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editSupplier(supplier) {
    document.getElementById('edit_supplier_id').value = supplier.id;
    document.getElementById('edit_supplier_name').value = supplier.supplier_name;
    document.getElementById('edit_contact_person').value = supplier.contact_person || '';
    document.getElementById('edit_phone').value = supplier.phone || '';
    document.getElementById('edit_email').value = supplier.email || '';
    document.getElementById('edit_address').value = supplier.address || '';
    document.getElementById('edit_supply_type').value = supplier.supply_type;
    document.getElementById('edit_status').value = supplier.status;
    document.getElementById('edit_payment_terms').value = supplier.payment_terms || '';
    document.getElementById('edit_account_balance').value = supplier.account_balance || 0;
    
    new bootstrap.Modal(document.getElementById('editSupplierModal')).show();
}
</script>

<?php include '../../includes/footer.php'; ?>
