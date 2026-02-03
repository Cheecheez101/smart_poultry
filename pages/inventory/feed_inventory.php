<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';

requireLogin();

$page_title = 'Feed Inventory Management';
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO feed_inventory 
                (feed_type, supplier_id, quantity, unit_price, expiry_date, reorder_level, storage_location, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $_POST['feed_type'],
                $_POST['supplier_id'] ?: null,
                $_POST['quantity'],
                $_POST['unit_price'] ?: null,
                $_POST['expiry_date'] ?: null,
                $_POST['reorder_level'] ?? 50,
                $_POST['storage_location'] ?? '',
                $_POST['notes'] ?? ''
            ]);
            
            $success_message = 'Feed inventory item added successfully!';
            logActivity($_SESSION['user_id'], 'Added feed inventory item', 'feed_inventory', $pdo->lastInsertId());
        } catch (PDOException $e) {
            $error_message = 'Error adding item: ' . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'update' && isset($_POST['id'])) {
        try {
            $stmt = $pdo->prepare("
                UPDATE feed_inventory 
                SET feed_type = ?, supplier_id = ?, quantity = ?, unit_price = ?, 
                    expiry_date = ?, reorder_level = ?, storage_location = ?, notes = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $_POST['feed_type'],
                $_POST['supplier_id'] ?: null,
                $_POST['quantity'],
                $_POST['unit_price'] ?: null,
                $_POST['expiry_date'] ?: null,
                $_POST['reorder_level'] ?? 50,
                $_POST['storage_location'] ?? '',
                $_POST['notes'] ?? '',
                $_POST['id']
            ]);
            
            $success_message = 'Feed inventory item updated successfully!';
            logActivity($_SESSION['user_id'], 'Updated feed inventory item', 'feed_inventory', $_POST['id']);
        } catch (PDOException $e) {
            $error_message = 'Error updating item: ' . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'delete' && isset($_POST['id'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM feed_inventory WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $success_message = 'Feed inventory item deleted successfully!';
            logActivity($_SESSION['user_id'], 'Deleted feed inventory item', 'feed_inventory', $_POST['id']);
        } catch (PDOException $e) {
            $error_message = 'Error deleting item: ' . $e->getMessage();
        }
    }
}

// Get feed inventory with supplier info
$search = $_GET['search'] ?? '';
$low_stock_only = isset($_GET['low_stock']);

$query = "
    SELECT fi.*, s.supplier_name
    FROM feed_inventory fi
    LEFT JOIN suppliers s ON fi.supplier_id = s.id
    WHERE 1=1
";

$params = [];

if ($search) {
    $query .= " AND (fi.feed_type LIKE ? OR s.supplier_name LIKE ? OR fi.storage_location LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($low_stock_only) {
    $query .= " AND fi.quantity <= fi.reorder_level";
}

$query .= " ORDER BY fi.feed_type ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$inventory_items = $stmt->fetchAll();

// Get suppliers for dropdown
$stmt = $pdo->query("SELECT id, supplier_name FROM suppliers WHERE supply_type = 'feed' ORDER BY supplier_name");
$suppliers = $stmt->fetchAll();

// Calculate summary statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_items,
        SUM(quantity) as total_quantity,
        SUM(quantity * unit_price) as total_value,
        COUNT(CASE WHEN quantity <= reorder_level THEN 1 END) as low_stock_items
    FROM feed_inventory
");
$stats = $stmt->fetch();

include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="fas fa-warehouse text-success"></i> Feed Inventory Management
        </h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addInventoryModal">
            <i class="fas fa-plus"></i> Add Feed Item
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
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Feed Types
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_items'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-boxes fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Total Quantity (kg)
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_quantity'] ?? 0, 2); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-weight fa-2x text-gray-300"></i>
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
                                Total Value
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo formatCurrency($stats['total_value'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-dollar-sign fa-2x text-gray-300"></i>
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
                                Low Stock Items
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['low_stock_items'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-exclamation-triangle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Filter Inventory</h6>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Feed type, supplier, or location...">
                </div>
                <div class="col-md-3">
                    <label class="form-label d-block">&nbsp;</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="low_stock" name="low_stock" 
                               <?php echo $low_stock_only ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="low_stock">
                            Show Low Stock Only
                        </label>
                    </div>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="feed_inventory.php" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Inventory Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Feed Inventory</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="inventoryTable">
                    <thead>
                        <tr>
                            <th>Feed Type</th>
                            <th>Supplier</th>
                            <th>Quantity (kg)</th>
                            <th>Unit Price</th>
                            <th>Total Value</th>
                            <th>Reorder Level</th>
                            <th>Status</th>
                            <th>Storage Location</th>
                            <th>Expiry Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inventory_items)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted">No inventory items found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inventory_items as $item): ?>
                                <?php 
                                $is_low_stock = $item['quantity'] <= $item['reorder_level'];
                                $total_value = $item['quantity'] * ($item['unit_price'] ?? 0);
                                ?>
                                <tr class="<?php echo $is_low_stock ? 'table-warning' : ''; ?>">
                                    <td><strong><?php echo htmlspecialchars($item['feed_type']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($item['supplier_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format($item['quantity'], 2); ?> kg</td>
                                    <td><?php echo $item['unit_price'] ? formatCurrency($item['unit_price']) : '-'; ?></td>
                                    <td><?php echo formatCurrency($total_value); ?></td>
                                    <td><?php echo number_format($item['reorder_level'], 2); ?> kg</td>
                                    <td>
                                        <?php if ($is_low_stock): ?>
                                            <span class="badge bg-danger">
                                                <i class="fas fa-exclamation-triangle"></i> Low Stock
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-check"></i> In Stock
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['storage_location'] ?: '-'); ?></td>
                                    <td><?php echo $item['expiry_date'] ? formatDate($item['expiry_date'], 'M j, Y') : '-'; ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info" 
                                                onclick="editItem(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" 
                                              onsubmit="return confirm('Are you sure you want to delete this item?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
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

<!-- Add Inventory Modal -->
<div class="modal fade" id="addInventoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add Feed Inventory Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="mb-3">
                        <label for="feed_type" class="form-label">Feed Type *</label>
                        <input type="text" class="form-control" id="feed_type" name="feed_type" required>
                    </div>

                    <div class="mb-3">
                        <label for="supplier_id" class="form-label">Supplier</label>
                        <select class="form-select" id="supplier_id" name="supplier_id">
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>">
                                    <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="quantity" class="form-label">Quantity (kg) *</label>
                            <input type="number" class="form-control" id="quantity" name="quantity" 
                                   step="0.01" min="0" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="unit_price" class="form-label">Unit Price</label>
                            <input type="number" class="form-control" id="unit_price" name="unit_price" 
                                   step="0.01" min="0">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="reorder_level" class="form-label">Reorder Level (kg)</label>
                            <input type="number" class="form-control" id="reorder_level" name="reorder_level" 
                                   step="0.01" min="0" value="50">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="expiry_date" class="form-label">Expiry Date</label>
                            <input type="date" class="form-control" id="expiry_date" name="expiry_date">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="storage_location" class="form-label">Storage Location</label>
                        <input type="text" class="form-control" id="storage_location" name="storage_location">
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Inventory Modal -->
<div class="modal fade" id="editInventoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Feed Inventory Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="edit_id">
                    
                    <div class="mb-3">
                        <label for="edit_feed_type" class="form-label">Feed Type *</label>
                        <input type="text" class="form-control" id="edit_feed_type" name="feed_type" required>
                    </div>

                    <div class="mb-3">
                        <label for="edit_supplier_id" class="form-label">Supplier</label>
                        <select class="form-select" id="edit_supplier_id" name="supplier_id">
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>">
                                    <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_quantity" class="form-label">Quantity (kg) *</label>
                            <input type="number" class="form-control" id="edit_quantity" name="quantity" 
                                   step="0.01" min="0" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_unit_price" class="form-label">Unit Price</label>
                            <input type="number" class="form-control" id="edit_unit_price" name="unit_price" 
                                   step="0.01" min="0">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_reorder_level" class="form-label">Reorder Level (kg)</label>
                            <input type="number" class="form-control" id="edit_reorder_level" name="reorder_level" 
                                   step="0.01" min="0">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_expiry_date" class="form-label">Expiry Date</label>
                            <input type="date" class="form-control" id="edit_expiry_date" name="expiry_date">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_storage_location" class="form-label">Storage Location</label>
                        <input type="text" class="form-control" id="edit_storage_location" name="storage_location">
                    </div>

                    <div class="mb-3">
                        <label for="edit_notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="edit_notes" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editItem(item) {
    document.getElementById('edit_id').value = item.id;
    document.getElementById('edit_feed_type').value = item.feed_type;
    document.getElementById('edit_supplier_id').value = item.supplier_id || '';
    document.getElementById('edit_quantity').value = item.quantity;
    document.getElementById('edit_unit_price').value = item.unit_price || '';
    document.getElementById('edit_reorder_level').value = item.reorder_level;
    document.getElementById('edit_expiry_date').value = item.expiry_date || '';
    document.getElementById('edit_storage_location').value = item.storage_location || '';
    document.getElementById('edit_notes').value = item.notes || '';
    
    new bootstrap.Modal(document.getElementById('editInventoryModal')).show();
}
</script>

<?php include '../../includes/footer.php'; ?>
