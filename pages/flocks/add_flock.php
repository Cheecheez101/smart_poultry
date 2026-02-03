<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';

$page_title = 'Add New Flock';
$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $batch_number = sanitizeInput($_POST['batch_number'] ?? '');
    $breed = sanitizeInput($_POST['breed'] ?? '');
    $purpose = sanitizeInput($_POST['purpose'] ?? 'egg_production');
    $initial_count = (int)($_POST['initial_count'] ?? 0);
    $arrival_date = $_POST['acquisition_date'] ?? '';
    $location = sanitizeInput($_POST['housing_location'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');

    // Validation
    if (empty($batch_number)) {
        $error = 'Batch number is required';
    } elseif ($initial_count <= 0) {
        $error = 'Initial count must be greater than 0';
    } elseif (empty($arrival_date)) {
        $error = 'Arrival date is required';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO flocks (
                    batch_number, breed, purpose, age_days, location, initial_count, current_count, 
                    arrival_date, status, notes, created_by
                ) VALUES (?, ?, ?, 0, ?, ?, ?, ?, 'active', ?, ?)
            ");
            
            $stmt->execute([
                $batch_number, $breed, $purpose, $location, $initial_count, $initial_count,
                $arrival_date, $notes, $_SESSION['user_id']
            ]);

            $flock_id = $pdo->lastInsertId();

            // Log activity
            logActivity($_SESSION['user_id'], 'add_flock', "Added new flock: {$batch_number}");

            // Send notification
            sendNotification($_SESSION['user_id'], 'Flock Added', "New flock '{$batch_number}' has been added successfully.", 'success');

            $success = "Flock '{$batch_number}' has been added successfully!";
            
            // Redirect after 2 seconds
            header("Refresh: 2; url=list_flocks.php");
        } catch (PDOException $e) {
            $error = "Error adding flock: " . $e->getMessage();
        }
    }
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Add New Flock</h1>
        <a href="list_flocks.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Flocks
        </a>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Flock Information</h6>
                </div>
                <div class="card-body">
                    <!-- Error/Success Messages -->
                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success" role="alert">
                            <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Add Flock Form -->
                    <form method="POST" action="" class="needs-validation" novalidate>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="batch_number" class="form-label">Batch Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="batch_number" name="batch_number" 
                                       value="<?php echo htmlspecialchars($_POST['batch_number'] ?? ''); ?>" required>
                                <div class="invalid-feedback">
                                    Please provide a valid batch number.
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="breed" class="form-label">Breed</label>
                                <select class="form-select" id="breed" name="breed">
                                    <option value="">Select breed...</option>
                                    <option value="Rhode Island Red" <?php echo ($_POST['breed'] ?? '') === 'Rhode Island Red' ? 'selected' : ''; ?>>Rhode Island Red</option>
                                    <option value="Leghorn" <?php echo ($_POST['breed'] ?? '') === 'Leghorn' ? 'selected' : ''; ?>>Leghorn</option>
                                    <option value="Sussex" <?php echo ($_POST['breed'] ?? '') === 'Sussex' ? 'selected' : ''; ?>>Sussex</option>
                                    <option value="Cornish Cross" <?php echo ($_POST['breed'] ?? '') === 'Cornish Cross' ? 'selected' : ''; ?>>Cornish Cross</option>
                                    <option value="Buff Orpington" <?php echo ($_POST['breed'] ?? '') === 'Buff Orpington' ? 'selected' : ''; ?>>Buff Orpington</option>
                                    <option value="New Hampshire" <?php echo ($_POST['breed'] ?? '') === 'New Hampshire' ? 'selected' : ''; ?>>New Hampshire</option>
                                    <option value="Other" <?php echo ($_POST['breed'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="purpose" class="form-label">Purpose</label>
                                <select class="form-select" id="purpose" name="purpose">
                                    <option value="egg_production" <?php echo ($_POST['purpose'] ?? 'egg_production') === 'egg_production' ? 'selected' : ''; ?>>Egg Production</option>
                                    <option value="meat_production" <?php echo ($_POST['purpose'] ?? '') === 'meat_production' ? 'selected' : ''; ?>>Meat Production</option>
                                    <option value="dual_purpose" <?php echo ($_POST['purpose'] ?? '') === 'dual_purpose' ? 'selected' : ''; ?>>Dual Purpose</option>
                                </select>
                            </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="initial_count" class="form-label">Initial Bird Count <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="initial_count" name="initial_count" 
                                       value="<?php echo htmlspecialchars($_POST['initial_count'] ?? ''); ?>" 
                                       min="1" required>
                                <div class="invalid-feedback">
                                    Please provide a valid bird count.
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="acquisition_date" class="form-label">Acquisition Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="acquisition_date" name="acquisition_date" 
                                       value="<?php echo htmlspecialchars($_POST['acquisition_date'] ?? date('Y-m-d')); ?>" required>
                                <div class="invalid-feedback">
                                    Please provide an acquisition date.
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="cost_per_bird" class="form-label">Cost per Bird ($)</label>
                                <input type="number" class="form-control" id="cost_per_bird" name="cost_per_bird" 
                                       value="<?php echo htmlspecialchars($_POST['cost_per_bird'] ?? ''); ?>" 
                                       min="0" step="0.01" data-type="currency">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="supplier" class="form-label">Supplier</label>
                                <input type="text" class="form-control" id="supplier" name="supplier" 
                                       value="<?php echo htmlspecialchars($_POST['supplier'] ?? ''); ?>" 
                                       placeholder="Hatchery or supplier name">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="purpose" class="form-label">Purpose</label>
                                <select class="form-select" id="purpose" name="purpose">
                                    <option value="layers" <?php echo ($_POST['purpose'] ?? 'layers') === 'layers' ? 'selected' : ''; ?>>Layers (Egg Production)</option>
                                    <option value="broilers" <?php echo ($_POST['purpose'] ?? '') === 'broilers' ? 'selected' : ''; ?>>Broilers (Meat Production)</option>
                                    <option value="breeders" <?php echo ($_POST['purpose'] ?? '') === 'breeders' ? 'selected' : ''; ?>>Breeders (Reproduction)</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="housing_location" class="form-label">Housing Location</label>
                                <input type="text" class="form-control" id="housing_location" name="housing_location" 
                                       value="<?php echo htmlspecialchars($_POST['housing_location'] ?? ''); ?>" 
                                       placeholder="e.g., House 1, Coop A, Pen 3">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" 
                                      placeholder="Additional notes about this flock..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="list_flocks.php" class="btn btn-secondary me-md-2">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Add Flock
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Cost Calculation -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-success">Cost Calculation</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-sm-6">
                            <strong>Birds:</strong>
                        </div>
                        <div class="col-sm-6 text-end">
                            <span id="calc-count">0</span>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6">
                            <strong>Cost per Bird:</strong>
                        </div>
                        <div class="col-sm-6 text-end">
                            $<span id="calc-cost">0.00</span>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-6">
                            <strong>Total Cost:</strong>
                        </div>
                        <div class="col-sm-6 text-end">
                            <strong class="text-success">$<span id="calc-total">0.00</span></strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tips -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-info">Tips</h6>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled">
                        <li><i class="fas fa-lightbulb text-warning"></i> Use descriptive flock names for easy identification</li>
                        <li><i class="fas fa-lightbulb text-warning"></i> Record accurate initial counts for proper tracking</li>
                        <li><i class="fas fa-lightbulb text-warning"></i> Include supplier information for future reference</li>
                        <li><i class="fas fa-lightbulb text-warning"></i> Housing location helps with flock management</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Update cost calculation
    function updateCostCalculation() {
        const count = parseInt(document.getElementById('initial_count').value) || 0;
        const cost = parseFloat(document.getElementById('cost_per_bird').value) || 0;
        const total = count * cost;

        document.getElementById('calc-count').textContent = count;
        document.getElementById('calc-cost').textContent = cost.toFixed(2);
        document.getElementById('calc-total').textContent = total.toFixed(2);
    }

    // Add event listeners for real-time calculation
    document.getElementById('initial_count').addEventListener('input', updateCostCalculation);
    document.getElementById('cost_per_bird').addEventListener('input', updateCostCalculation);

    // Initial calculation
    updateCostCalculation();
});
</script>

<?php include '../../includes/footer.php'; ?>