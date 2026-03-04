<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';

requireLogin();

$page_title = 'Sales Management';
$success_message = '';
$error_message = '';

// Get pricing for reference
$stmt = $pdo->query("SELECT * FROM product_pricing ORDER BY product_type, customer_type");
$all_pricing = $stmt->fetchAll(PDO::FETCH_GROUP);

// Get available egg inventory
$stmt = $pdo->query("SELECT COALESCE(SUM(eggs_stored), 0) as available_eggs FROM egg_production WHERE eggs_stored > 0");
$inventory = $stmt->fetch();
$available_eggs = $inventory['available_eggs'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_sale') {
        try {
            $pdo->beginTransaction();
            
            // Get customer type for pricing
            $customer_id = $_POST['customer_id'] ?? null;
            $customer_type = 'individual';
            
            if ($customer_id) {
                $stmt = $pdo->prepare("SELECT customer_type FROM customers WHERE id = ?");
                $stmt->execute([$customer_id]);
                $customer = $stmt->fetch();
                $customer_type = $customer['customer_type'] ?? 'individual';
            }
            
            // Determine product type and quantity
            $product_type = $_POST['product_type'];
            $quantity = $_POST['quantity'];
            $unit_price = $_POST['unit_price'];
            
            // Calculate eggs needed if selling eggs
            $eggs_needed = 0;
            if ($product_type == 'eggs') {
                if ($_POST['unit_type'] == 'tray') {
                    $eggs_needed = $quantity * 30; // 30 eggs per tray
                    $product_type = 'eggs';
                } else {
                    $eggs_needed = $quantity; // Individual eggs
                }
                
                // Check if enough eggs available
                if ($eggs_needed > $available_eggs) {
                    throw new Exception("Not enough eggs in storage! Available: {$available_eggs}, Needed: {$eggs_needed}");
                }
            }
            
            // Generate invoice number
            $stmt = $pdo->query("SELECT MAX(id) as max_id FROM sales");
            $result = $stmt->fetch();
            $next_id = ($result['max_id'] ?? 0) + 1;
            $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);
            
            // Calculate total
            $total_amount = $quantity * $unit_price;
            
            // Insert sale
            $stmt = $pdo->prepare("
                INSERT INTO sales 
                (invoice_number, customer_id, sale_date, product_type, quantity, unit_price, 
                 total_amount, payment_method, payment_status, notes, sold_by, eggs_from_storage, inventory_updated) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $invoice_number,
                $customer_id,
                $_POST['sale_date'],
                $product_type,
                $quantity,
                $unit_price,
                $total_amount,
                $_POST['payment_method'],
                $_POST['payment_status'],
                $_POST['notes'] ?? '',
                $_SESSION['user_id'],
                $eggs_needed,
                $eggs_needed > 0 ? 1 : 0
            ]);
            
            $sale_id = $pdo->lastInsertId();
            
            // Deduct eggs from storage if selling eggs
            if ($eggs_needed > 0) {
                deductEggsFromStorage($pdo, $eggs_needed, $sale_id);
            }
            
            // Update customer
            if ($customer_id) {
                $stmt = $pdo->prepare("
                    UPDATE customers SET 
                    total_purchases = total_purchases + ?,
                    last_purchase_date = ?
                    WHERE id = ?
                ");
                $stmt->execute([$total_amount, $_POST['sale_date'], $customer_id]);
            }
            
            $pdo->commit();
            $success_message = "Sale recorded successfully! Invoice: {$invoice_number}";
            logActivity($_SESSION['user_id'], "Created sale {$invoice_number}", 'sales', $sale_id);
            
            // Refresh available eggs
            $stmt = $pdo->query("SELECT COALESCE(SUM(eggs_stored), 0) as available_eggs FROM egg_production WHERE eggs_stored > 0");
            $inventory = $stmt->fetch();
            $available_eggs = $inventory['available_eggs'];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = 'Error: ' . $e->getMessage();
        }
    }
}

// Function to deduct eggs from storage
function deductEggsFromStorage($pdo, $eggs_needed, $sale_id) {
    // Get production records with available eggs, oldest first (FIFO)
    $stmt = $pdo->query("
        SELECT id, eggs_stored 
        FROM egg_production 
        WHERE eggs_stored > 0 
        ORDER BY production_date ASC, id ASC
    ");
    $production_records = $stmt->fetchAll();
    
    $remaining = $eggs_needed;
    
    foreach ($production_records as $record) {
        if ($remaining <= 0) break;
        
        $deduct = min($remaining, $record['eggs_stored']);
        
        // Update the production record
        $stmt = $pdo->prepare("
            UPDATE egg_production 
            SET eggs_stored = eggs_stored - ? 
            WHERE id = ?
        ");
        $stmt->execute([$deduct, $record['id']]);
        
        $remaining -= $deduct;
        
        // Log the deduction
        logActivity($_SESSION['user_id'], "Deducted {$deduct} eggs from production ID {$record['id']} for sale", 'egg_production', $record['id']);
    }
    
    if ($remaining > 0) {
        throw new Exception("Could not deduct all eggs from storage. Short by: {$remaining}");
    }
}

// Get sales records
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$query = "
    SELECT s.*, c.customer_name, c.customer_type, u.full_name as sold_by_name
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN users u ON s.sold_by = u.id
    WHERE 1=1
";

$params = [];

if ($search) {
    $query .= " AND (s.invoice_number LIKE ? OR c.customer_name LIKE ? OR s.product_type LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($date_from) {
    $query .= " AND s.sale_date >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND s.sale_date <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY s.sale_date DESC, s.created_at DESC LIMIT 100";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$sales = $stmt->fetchAll();

// Get customers
$stmt = $pdo->query("SELECT id, customer_name, customer_type, phone FROM customers WHERE 1=1 ORDER BY customer_name");
$customers = $stmt->fetchAll();

// Calculate stats
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_sales,
        SUM(total_amount) as total_revenue,
        SUM(CASE WHEN product_type = 'eggs' THEN eggs_from_storage ELSE 0 END) as total_eggs_sold
    FROM sales 
    WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$stats = $stmt->fetch();

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="fas fa-cash-register text-success"></i> Sales Management
        </h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSaleModal">
            <i class="fas fa-plus"></i> New Sale
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

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Total Revenue (30 Days)
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo formatCurrency($stats['total_revenue'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-money-bill-wave fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Total Sales (30 Days)
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_sales'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-shopping-cart fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Eggs Available in Storage
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($available_eggs); ?> eggs
                            </div>
                            <small class="text-muted"><?php echo number_format($available_eggs / 30, 1); ?> trays</small>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-egg fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Filter Sales</h6>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Invoice, customer, product...">
                </div>
                <div class="col-md-3">
                    <label for="date_from" class="form-label">From Date</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" 
                           value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-3">
                    <label for="date_to" class="form-label">To Date</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" 
                           value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="sales.php" class="btn btn-secondary">
                        <i class="fas fa-redo"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Sales Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Recent Sales</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
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
                                <td colspan="10" class="text-center text-muted">No sales records found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sales as $sale): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($sale['invoice_number']); ?></strong></td>
                                    <td><?php echo formatDate($sale['sale_date'], 'M j, Y'); ?></td>
                                    <td>
                                        <?php if ($sale['customer_name']): ?>
                                            <?php echo htmlspecialchars($sale['customer_name']); ?>
                                            <br><small class="text-muted"><?php echo ucfirst($sale['customer_type']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">Walk-in</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo ucfirst(htmlspecialchars($sale['product_type'])); ?></td>
                                    <td>
                                        <?php echo number_format($sale['quantity']); ?>
                                        <?php if ($sale['eggs_from_storage'] > 0): ?>
                                            <br><small class="text-muted">(<?php echo number_format($sale['eggs_from_storage']); ?> eggs)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatCurrency($sale['unit_price']); ?></td>
                                    <td><strong><?php echo formatCurrency($sale['total_amount']); ?></strong></td>
                                    <td><?php echo ucfirst($sale['payment_method']); ?></td>
                                    <td>
                                        <?php
                                        $badge_class = $sale['payment_status'] == 'paid' ? 'bg-success' : 
                                                      ($sale['payment_status'] == 'pending' ? 'bg-warning' : 'bg-info');
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo ucfirst($sale['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info" 
                                                onclick="viewInvoice('<?php echo $sale['invoice_number']; ?>')">
                                            <i class="fas fa-eye"></i>
                                        </button>
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
            <form method="POST" id="saleForm">
                <div class="modal-header">
                    <h5 class="modal-title">New Sale</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_sale">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="customer_id" class="form-label">Customer</label>
                            <select class="form-select" id="customer_id" name="customer_id" onchange="updatePricing()">
                                <option value="">Walk-in Customer</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>" 
                                            data-type="<?php echo $customer['customer_type']; ?>">
                                        <?php echo htmlspecialchars($customer['customer_name'] . ' - ' . ucfirst($customer['customer_type'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="sale_date" class="form-label">Sale Date *</label>
                            <input type="date" class="form-control" id="sale_date" name="sale_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="product_type" class="form-label">Product Type *</label>
                            <select class="form-select" id="product_type" name="product_type" required onchange="updatePricing()">
                                <option value="">Select Product</option>
                                <option value="eggs">Eggs</option>
                                <option value="chicken">Chicken</option>
                                <option value="feed">Feed</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3" id="unit_type_div" style="display:none;">
                            <label for="unit_type" class="form-label">Unit Type *</label>
                            <select class="form-select" id="unit_type" name="unit_type" onchange="updatePricing()">
                                <option value="single">Single Eggs</option>
                                <option value="tray">Tray (30 eggs)</option>
                            </select>
                        </div>
                    </div>

                    <div class="alert alert-info" id="inventory_alert" style="display:none;">
                        <i class="fas fa-info-circle"></i> 
                        Available eggs in storage: <strong><?php echo number_format($available_eggs); ?></strong> eggs 
                        (<strong><?php echo number_format($available_eggs / 30, 1); ?></strong> trays)
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="quantity" class="form-label">Quantity *</label>
                            <input type="number" class="form-control" id="quantity" name="quantity" 
                                   min="1" step="1" required onkeyup="calculateTotal()">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="unit_price" class="form-label">Unit Price (KSH) *</label>
                            <input type="number" class="form-control" id="unit_price" name="unit_price" 
                                   min="0" step="0.01" required onkeyup="calculateTotal()">
                            <small class="text-muted" id="price_hint"></small>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="total_display" class="form-label">Total Amount</label>
                            <input type="text" class="form-control" id="total_display" readonly>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="payment_method" class="form-label">Payment Method *</label>
                            <select class="form-select" id="payment_method" name="payment_method" required>
                                <option value="cash">Cash</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="credit">Credit</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="payment_status" class="form-label">Payment Status *</label>
                            <select class="form-select" id="payment_status" name="payment_status" required>
                                <option value="paid">Paid</option>
                                <option value="pending">Pending</option>
                                <option value="partial">Partial</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Record Sale
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Pricing data from PHP
const pricing = <?php echo json_encode($all_pricing); ?>;
const availableEggs = <?php echo $available_eggs; ?>;

function updatePricing() {
    const productType = document.getElementById('product_type').value;
    const unitTypeDiv = document.getElementById('unit_type_div');
    const inventoryAlert = document.getElementById('inventory_alert');
    const unitType = document.getElementById('unit_type').value;
    
    // Show unit type selection only for eggs
    if (productType === 'eggs') {
        unitTypeDiv.style.display = 'block';
        inventoryAlert.style.display = 'block';
    } else {
        unitTypeDiv.style.display = 'none';
        inventoryAlert.style.display = 'none';
    }
    
    // Get customer type
    const customerSelect = document.getElementById('customer_id');
    const selectedOption = customerSelect.options[customerSelect.selectedIndex];
    const customerType = selectedOption.getAttribute('data-type') || 'individual';
    
    // Determine price key
    let priceKey = '';
    if (productType === 'eggs') {
        priceKey = unitType === 'tray' ? 'egg_tray' : 'egg_single';
    } else if (productType === 'chicken') {
        priceKey = 'chicken_live';
    }
    
    // Set price if available
    if (priceKey && pricing[priceKey]) {
        const priceData = pricing[priceKey].find(p => p.customer_type === customerType);
        if (priceData) {
            document.getElementById('unit_price').value = priceData.price;
            document.getElementById('price_hint').textContent = `Recommended: KSH ${priceData.price}`;
            calculateTotal();
        }
    }
}

function calculateTotal() {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const unitPrice = parseFloat(document.getElementById('unit_price').value) || 0;
    const total = quantity * unitPrice;
    
    document.getElementById('total_display').value = 'KSH ' + total.toFixed(2);
    
    // Check egg availability if selling eggs
    const productType = document.getElementById('product_type').value;
    if (productType === 'eggs') {
        const unitType = document.getElementById('unit_type').value;
        const eggsNeeded = unitType === 'tray' ? quantity * 30 : quantity;
        
        if (eggsNeeded > availableEggs) {
            alert(`Not enough eggs in storage! You need ${eggsNeeded} eggs but only ${availableEggs} are available.`);
        }
    }
}

function viewInvoice(invoiceNumber) {
    alert('Invoice viewer coming soon: ' + invoiceNumber);
}
</script>

<?php include '../../includes/footer.php'; ?>
