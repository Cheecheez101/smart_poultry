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
                // Generate invoice number
                $stmt = $pdo->query("SELECT MAX(id) as max_id FROM sales");
                $result = $stmt->fetch();
                $next_id = ($result['max_id'] ?? 0) + 1;
                $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);
                
                // Calculate total
                $total_amount = $_POST['quantity'] * $_POST['unit_price'];
                
                $stmt = $pdo->prepare("
                    INSERT INTO sales (invoice_number, customer_id, sale_date, product_type, 
                    quantity, unit_price, total_amount, payment_method, payment_status, notes, sold_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $invoice_number,
                    !empty($_POST['customer_id']) ? $_POST['customer_id'] : null,
                    $_POST['sale_date'],
                    $_POST['product_type'],
                    $_POST['quantity'],
                    $_POST['unit_price'],
                    $total_amount,
                    $_POST['payment_method'],
                    $_POST['payment_status'],
                    $_POST['notes'] ?? '',
                    $_SESSION['user_id']
                ]);
                
                $sale_id = $pdo->lastInsertId();
                
                // Update customer total purchases if customer is selected
                if (!empty($_POST['customer_id'])) {
                    $stmt = $pdo->prepare("
                        UPDATE customers SET 
                        total_purchases = total_purchases + ?,
                        last_purchase_date = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$total_amount, $_POST['sale_date'], $_POST['customer_id']]);
                }
                
                logActivity('CREATE', 'sales', $sale_id, 'Added sale record');
                $_SESSION['success_message'] = 'Sale recorded successfully! Invoice: ' . $invoice_number;
                
            } elseif ($action === 'edit') {
                // Get old total to adjust customer balance
                $stmt = $pdo->prepare("SELECT total_amount, customer_id FROM sales WHERE id = ?");
                $stmt->execute([$_POST['sale_id']]);
                $old_sale = $stmt->fetch();
                
                // Calculate new total
                $total_amount = $_POST['quantity'] * $_POST['unit_price'];
                
                $stmt = $pdo->prepare("
                    UPDATE sales SET 
                    customer_id = ?, sale_date = ?, product_type = ?, 
                    quantity = ?, unit_price = ?, total_amount = ?, 
                    payment_method = ?, payment_status = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    !empty($_POST['customer_id']) ? $_POST['customer_id'] : null,
                    $_POST['sale_date'],
                    $_POST['product_type'],
                    $_POST['quantity'],
                    $_POST['unit_price'],
                    $total_amount,
                    $_POST['payment_method'],
                    $_POST['payment_status'],
                    $_POST['notes'] ?? '',
                    $_POST['sale_id']
                ]);
                
                // Update customer balances if needed
                if ($old_sale['customer_id']) {
                    $stmt = $pdo->prepare("UPDATE customers SET total_purchases = total_purchases - ? WHERE id = ?");
                    $stmt->execute([$old_sale['total_amount'], $old_sale['customer_id']]);
                }
                if (!empty($_POST['customer_id'])) {
                    $stmt = $pdo->prepare("UPDATE customers SET total_purchases = total_purchases + ?, last_purchase_date = ? WHERE id = ?");
                    $stmt->execute([$total_amount, $_POST['sale_date'], $_POST['customer_id']]);
                }
                
                logActivity('UPDATE', 'sales', $_POST['sale_id'], 'Updated sale record');
                $_SESSION['success_message'] = 'Sale updated successfully!';
                
            } elseif ($action === 'delete') {
                // Get sale info to adjust customer balance
                $stmt = $pdo->prepare("SELECT total_amount, customer_id FROM sales WHERE id = ?");
                $stmt->execute([$_POST['sale_id']]);
                $sale = $stmt->fetch();
                
                // Delete sale
                $stmt = $pdo->prepare("DELETE FROM sales WHERE id = ?");
                $stmt->execute([$_POST['sale_id']]);
                
                // Update customer balance
                if ($sale['customer_id']) {
                    $stmt = $pdo->prepare("UPDATE customers SET total_purchases = total_purchases - ? WHERE id = ?");
                    $stmt->execute([$sale['total_amount'], $sale['customer_id']]);
                }
                
                logActivity('DELETE', 'sales', $_POST['sale_id'], 'Deleted sale record');
                $_SESSION['success_message'] = 'Sale deleted successfully!';
            }
            
            header('Location: sales.php');
            exit;
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
        }
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$product_type = $_GET['product_type'] ?? '';
$payment_status = $_GET['payment_status'] ?? '';
$customer_filter = $_GET['customer_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$query = "
    SELECT s.*, c.customer_name, u.full_name as sold_by_name
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN users u ON s.sold_by = u.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $query .= " AND (s.invoice_number LIKE ? OR c.customer_name LIKE ? OR s.notes LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if (!empty($product_type)) {
    $query .= " AND s.product_type = ?";
    $params[] = $product_type;
}

if (!empty($payment_status)) {
    $query .= " AND s.payment_status = ?";
    $params[] = $payment_status;
}

if (!empty($customer_filter)) {
    $query .= " AND s.customer_id = ?";
    $params[] = $customer_filter;
}

if (!empty($date_from)) {
    $query .= " AND s.sale_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND s.sale_date <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY s.sale_date DESC, s.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$sales = $stmt->fetchAll();

// Get statistics
$stats = [
    'total_sales' => 0,
    'total_revenue' => 0,
    'pending_payments' => 0,
    'today_sales' => 0
];

$stmt = $pdo->query("SELECT COUNT(*) as total, COALESCE(SUM(total_amount), 0) as revenue FROM sales");
$result = $stmt->fetch();
$stats['total_sales'] = $result['total'];
$stats['total_revenue'] = $result['revenue'];

$stmt = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) as pending FROM sales WHERE payment_status IN ('pending', 'partial')");
$stats['pending_payments'] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) as today FROM sales WHERE sale_date = CURDATE()");
$stats['today_sales'] = $stmt->fetchColumn();

// Get customers for dropdowns
$customers = $pdo->query("SELECT id, customer_name, customer_type FROM customers ORDER BY customer_name")->fetchAll();

$pageTitle = 'Sales Management';
include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2><i class="bi bi-cart-check"></i> Sales Management</h2>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSaleModal">
                <i class="bi bi-plus-circle"></i> Record Sale
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
                    <h5 class="card-title">Total Sales</h5>
                    <h2><?= number_format($stats['total_sales']) ?></h2>
                    <small>All time sales records</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Revenue</h5>
                    <h2>KSh <?= number_format($stats['total_revenue'], 2) ?></h2>
                    <small>All time revenue</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Pending Payments</h5>
                    <h2>KSh <?= number_format($stats['pending_payments'], 2) ?></h2>
                    <small>Outstanding amounts</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Today's Sales</h5>
                    <h2>KSh <?= number_format($stats['today_sales'], 2) ?></h2>
                    <small>Sales recorded today</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Invoice, customer..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Product Type</label>
                    <select name="product_type" class="form-select">
                        <option value="">All Products</option>
                        <option value="eggs" <?= $product_type === 'eggs' ? 'selected' : '' ?>>Eggs</option>
                        <option value="chicken" <?= $product_type === 'chicken' ? 'selected' : '' ?>>Chicken</option>
                        <option value="feed" <?= $product_type === 'feed' ? 'selected' : '' ?>>Feed</option>
                        <option value="other" <?= $product_type === 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Payment Status</label>
                    <select name="payment_status" class="form-select">
                        <option value="">All Status</option>
                        <option value="paid" <?= $payment_status === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="pending" <?= $payment_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="partial" <?= $payment_status === 'partial' ? 'selected' : '' ?>>Partial</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Customer</label>
                    <select name="customer_id" class="form-select">
                        <option value="">All Customers</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= $customer['id'] ?>" <?= $customer_filter == $customer['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($customer['customer_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i> Filter
                    </button>
                    <a href="sales.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Sales Table -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales)): ?>
                            <tr>
                                <td colspan="10" class="text-center">No sales records found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sales as $sale): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($sale['invoice_number']) ?></strong>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($sale['sale_date'])) ?></td>
                                    <td>
                                        <?php if ($sale['customer_name']): ?>
                                            <?= htmlspecialchars($sale['customer_name']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Walk-in</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $productIcons = [
                                                'eggs' => 'egg-fill',
                                                'chicken' => 'bag-fill',
                                                'feed' => 'box-seam',
                                                'other' => 'question-circle'
                                            ];
                                            $icon = $productIcons[$sale['product_type']] ?? 'question-circle';
                                        ?>
                                        <i class="bi bi-<?= $icon ?>"></i> <?= ucfirst($sale['product_type']) ?>
                                    </td>
                                    <td><?= number_format($sale['quantity'], 2) ?></td>
                                    <td>KSh <?= number_format($sale['unit_price'], 2) ?></td>
                                    <td><strong>KSh <?= number_format($sale['total_amount'], 2) ?></strong></td>
                                    <td>
                                        <?php
                                            $methodBadges = [
                                                'cash' => 'bg-success',
                                                'mpesa' => 'bg-primary',
                                                'bank' => 'bg-info',
                                                'credit' => 'bg-warning'
                                            ];
                                        ?>
                                        <span class="badge <?= $methodBadges[$sale['payment_method']] ?? 'bg-secondary' ?>">
                                            <?= ucfirst($sale['payment_method']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                            $statusBadges = [
                                                'paid' => 'bg-success',
                                                'pending' => 'bg-danger',
                                                'partial' => 'bg-warning'
                                            ];
                                        ?>
                                        <span class="badge <?= $statusBadges[$sale['payment_status']] ?? 'bg-secondary' ?>">
                                            <?= ucfirst($sale['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick='editSale(<?= json_encode($sale) ?>)'>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this sale?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="sale_id" value="<?= $sale['id'] ?>">
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

<!-- Add Sale Modal -->
<div class="modal fade" id="addSaleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Record New Sale</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Customer</label>
                            <select name="customer_id" class="form-select">
                                <option value="">Walk-in Customer</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= $customer['id'] ?>">
                                        <?= htmlspecialchars($customer['customer_name']) ?> (<?= ucfirst($customer['customer_type']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Leave empty for walk-in customers</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Sale Date *</label>
                            <input type="date" name="sale_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Product Type *</label>
                            <select name="product_type" class="form-select" required>
                                <option value="">Select Product</option>
                                <option value="eggs">Eggs</option>
                                <option value="chicken">Chicken</option>
                                <option value="feed">Feed</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Quantity *</label>
                            <input type="number" name="quantity" class="form-control" step="0.01" min="0.01" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Unit Price (KSh) *</label>
                            <input type="number" name="unit_price" class="form-control" step="0.01" min="0.01" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Method *</label>
                            <select name="payment_method" class="form-select" required>
                                <option value="cash">Cash</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="credit">Credit</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Payment Status *</label>
                            <select name="payment_status" class="form-select" required>
                                <option value="paid">Paid</option>
                                <option value="pending">Pending</option>
                                <option value="partial">Partial</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Record Sale</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Sale Modal -->
<div class="modal fade" id="editSaleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Sale</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="sale_id" id="edit_sale_id">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Invoice Number</label>
                            <input type="text" id="edit_invoice_number" class="form-control" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Customer</label>
                            <select name="customer_id" id="edit_customer_id" class="form-select">
                                <option value="">Walk-in Customer</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= $customer['id'] ?>">
                                        <?= htmlspecialchars($customer['customer_name']) ?> (<?= ucfirst($customer['customer_type']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Sale Date *</label>
                            <input type="date" name="sale_date" id="edit_sale_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Product Type *</label>
                            <select name="product_type" id="edit_product_type" class="form-select" required>
                                <option value="">Select Product</option>
                                <option value="eggs">Eggs</option>
                                <option value="chicken">Chicken</option>
                                <option value="feed">Feed</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Quantity *</label>
                            <input type="number" name="quantity" id="edit_quantity" class="form-control" step="0.01" min="0.01" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Unit Price (KSh) *</label>
                            <input type="number" name="unit_price" id="edit_unit_price" class="form-control" step="0.01" min="0.01" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Payment Method *</label>
                            <select name="payment_method" id="edit_payment_method" class="form-select" required>
                                <option value="cash">Cash</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="credit">Credit</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Status *</label>
                            <select name="payment_status" id="edit_payment_status" class="form-select" required>
                                <option value="paid">Paid</option>
                                <option value="pending">Pending</option>
                                <option value="partial">Partial</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Sale</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editSale(sale) {
    document.getElementById('edit_sale_id').value = sale.id;
    document.getElementById('edit_invoice_number').value = sale.invoice_number;
    document.getElementById('edit_customer_id').value = sale.customer_id || '';
    document.getElementById('edit_sale_date').value = sale.sale_date;
    document.getElementById('edit_product_type').value = sale.product_type;
    document.getElementById('edit_quantity').value = sale.quantity;
    document.getElementById('edit_unit_price').value = sale.unit_price;
    document.getElementById('edit_payment_method').value = sale.payment_method;
    document.getElementById('edit_payment_status').value = sale.payment_status;
    document.getElementById('edit_notes').value = sale.notes || '';
    
    new bootstrap.Modal(document.getElementById('editSaleModal')).show();
}
</script>

<?php include '../../includes/footer.php'; ?>
