<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();
requireRole(['admin', 'manager']);

$page_title = 'Product Pricing Management';
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_pricing') {
        try {
            $pdo->beginTransaction();
            
            foreach ($_POST['prices'] as $key => $price) {
                list($product_type, $customer_type) = explode('_', $key);
                
                $stmt = $pdo->prepare("
                    INSERT INTO product_pricing (product_type, customer_type, price, updated_by)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        price = VALUES(price),
                        updated_by = VALUES(updated_by),
                        updated_at = CURRENT_TIMESTAMP
                ");
                
                $stmt->execute([
                    $product_type,
                    $customer_type,
                    $price,
                    $_SESSION['user_id']
                ]);
            }
            
            $pdo->commit();
            $success_message = 'Pricing updated successfully!';
            logActivity($_SESSION['user_id'], 'Updated product pricing', 'product_pricing', null);
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error_message = 'Error updating pricing: ' . $e->getMessage();
        }
    }
}

// Get current pricing
$stmt = $pdo->query("
    SELECT product_type, customer_type, price, pp.updated_at, u.full_name as updated_by_name
    FROM product_pricing pp
    LEFT JOIN users u ON pp.updated_by = u.id
    ORDER BY product_type, customer_type
");
$pricing = $stmt->fetchAll();

// Organize pricing by product type
$pricing_matrix = [];
foreach ($pricing as $p) {
    $pricing_matrix[$p['product_type']][$p['customer_type']] = $p;
}

$product_types = [
    'egg_single' => 'Single Egg',
    'egg_tray' => 'Egg Tray (30 eggs)',
    'chicken_live' => 'Live Chicken',
    'chicken_dressed' => 'Dressed Chicken'
];

$customer_types = ['wholesaler', 'retailer', 'individual', 'other'];

include '../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="fas fa-tags text-primary"></i> Product Pricing Management
        </h1>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Info Alert -->
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i>
        <strong>Important:</strong> These prices will be used automatically when sales staff create invoices. 
        Prices are applied based on the customer type. Update carefully as it affects all future sales.
    </div>

    <!-- Pricing Form -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Set Prices by Customer Type</h6>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_pricing">
                
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 25%;">Product Type</th>
                                <th>Wholesaler Price (KSH)</th>
                                <th>Retailer Price (KSH)</th>
                                <th>Individual Price (KSH)</th>
                                <th>Other Price (KSH)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($product_types as $type => $label): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($label); ?></strong></td>
                                    <?php foreach ($customer_types as $customer_type): ?>
                                        <?php 
                                        $current_price = $pricing_matrix[$type][$customer_type]['price'] ?? 0;
                                        $field_name = "prices[{$type}_{$customer_type}]";
                                        ?>
                                        <td>
                                            <div class="input-group">
                                                <span class="input-group-text">KSH</span>
                                                <input type="number" 
                                                       class="form-control" 
                                                       name="<?php echo $field_name; ?>" 
                                                       value="<?php echo number_format($current_price, 2, '.', ''); ?>" 
                                                       step="0.01" 
                                                       min="0" 
                                                       required>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 d-flex justify-content-between align-items-center">
                    <div class="text-muted">
                        <small>
                            <i class="fas fa-clock"></i> 
                            Last updated: 
                            <?php 
                            $latest = $pricing[0]['updated_at'] ?? null;
                            $updater = $pricing[0]['updated_by_name'] ?? 'System';
                            echo $latest ? formatDate($latest, 'M j, Y g:i A') . ' by ' . htmlspecialchars($updater) : 'Never';
                            ?>
                        </small>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save"></i> Update All Prices
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Pricing History Card -->
    <div class="card shadow">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Pricing Guidelines</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6 class="text-success"><i class="fas fa-lightbulb"></i> Best Practices</h6>
                    <ul>
                        <li>Wholesaler prices should be lowest (bulk purchases)</li>
                        <li>Retailer prices should be moderate (medium quantities)</li>
                        <li>Individual prices should be highest (single unit sales)</li>
                        <li>One tray = 30 eggs, price accordingly</li>
                        <li>Review and update prices regularly based on market conditions</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6 class="text-info"><i class="fas fa-calculator"></i> Quick Price Calculator</h6>
                    <div class="bg-light p-3 rounded">
                        <div class="mb-2">
                            <label class="form-label small">Egg Tray Price:</label>
                            <input type="number" id="trayPrice" class="form-control form-control-sm" 
                                   placeholder="e.g., 450" onkeyup="calculateEggPrice()">
                        </div>
                        <div>
                            <label class="form-label small">Suggested Single Egg Price:</label>
                            <input type="text" id="singleEggPrice" class="form-control form-control-sm" 
                                   readonly placeholder="Will calculate...">
                        </div>
                        <small class="text-muted">Single egg = Tray price ÷ 30</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function calculateEggPrice() {
    const trayPrice = parseFloat(document.getElementById('trayPrice').value);
    if (!isNaN(trayPrice) && trayPrice > 0) {
        const singlePrice = (trayPrice / 30).toFixed(2);
        document.getElementById('singleEggPrice').value = 'KSH ' + singlePrice;
    } else {
        document.getElementById('singleEggPrice').value = '';
    }
}
</script>

<?php include '../includes/footer.php'; ?>
