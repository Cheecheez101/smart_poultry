<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

$page_title = 'Dashboard';

// Initialize dashboard variables with safe defaults
$totalFlocks = 0;
$totalBirds = 0;
$todayEggs = 0;
$monthlySales = 0;
$recentActivities = [];
$lowStockItems = [];
$upcomingEvents = [];

// Get dashboard statistics
try {
    // Total flocks
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM flocks WHERE status = 'active'");
    $totalFlocks = $stmt->fetch()['total'] ?? 0;
    
    // Total birds
    $stmt = $pdo->query("SELECT SUM(current_count) as total FROM flocks WHERE status = 'active'");
    $totalBirds = $stmt->fetch()['total'] ?? 0;
    
    // Today's egg production
    $stmt = $pdo->query("
        SELECT SUM(eggs_collected) as total 
        FROM egg_production 
        WHERE DATE(production_date) = CURDATE()
    ");
    $todayEggs = $stmt->fetch()['total'] ?? 0;
    
    // This month's sales
    $stmt = $pdo->query("
        SELECT SUM(total_amount) as total 
        FROM sales 
        WHERE MONTH(sale_date) = MONTH(CURDATE()) 
        AND YEAR(sale_date) = YEAR(CURDATE())
    ");
    $monthlySales = $stmt->fetch()['total'] ?? 0;
    
    // Recent activities (use system_logs table)
    $stmt = $pdo->query("
        SELECT sl.*, u.username, sl.log_timestamp as created_at, sl.action as details
        FROM system_logs sl
        LEFT JOIN users u ON sl.user_id = u.id
        ORDER BY sl.log_timestamp DESC 
        LIMIT 5
    ");
    $recentActivities = $stmt->fetchAll();
    
    // Low inventory alerts (feed_inventory)
    $stmt = $pdo->query("
        SELECT * FROM feed_inventory 
        WHERE quantity <= reorder_level 
        ORDER BY (quantity - reorder_level) ASC
        LIMIT 5
    ");
    $lowStockItems = $stmt->fetchAll();
    
    // Upcoming events
    $upcomingEvents = getUpcomingEvents(7);
    
} catch (PDOException $e) {
    $error = "Error loading dashboard data";
}

include '../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Dashboard</h1>
        <div class="text-muted">
            Welcome back, <?php echo htmlspecialchars($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User')); ?>!
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Active Flocks
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo formatNumber($totalFlocks); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-dove fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Total Birds
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo formatNumber($totalBirds); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-feather-alt fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Today's Eggs
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo formatNumber($todayEggs); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-egg fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Monthly Sales
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo formatCurrency($monthlySales); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-money-bill-wave fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row mb-4">
        <div class="col-xl-8 col-lg-7">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">Egg Production Overview</h6>
                </div>
                <div class="card-body">
                    <div class="chart-area">
                        <canvas id="eggProductionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-lg-5">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">Flock Status</h6>
                </div>
                <div class="card-body">
                    <div class="chart-pie pt-4 pb-2">
                        <canvas id="flockStatusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Content Row -->
    <div class="row">
        <!-- Recent Activity -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Recent Activity</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($recentActivities)): ?>
                        <p class="text-muted">No recent activity</p>
                    <?php else: ?>
                        <?php foreach ($recentActivities as $activity): ?>
                            <div class="d-flex align-items-center mb-3">
                                <div class="mr-3">
                                    <i class="fas fa-clock text-muted"></i>
                                </div>
                                <div>
                                    <div class="small text-muted">
                                        <?php echo isset($activity['created_at']) ? formatDate($activity['created_at'], 'M j, Y g:i A') : 'Unknown time'; ?>
                                    </div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($activity['username'] ?? 'System'); ?></strong>
                                        <?php echo htmlspecialchars($activity['action'] ?? 'Unknown action'); ?>
                                    </div>
                                    <?php if (!empty($activity['details'])): ?>
                                        <div class="small text-muted">
                                            <?php echo htmlspecialchars($activity['details']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Alerts & Notifications -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-warning">Alerts & Reminders</h6>
                </div>
                <div class="card-body">
                    <!-- Low Stock Alerts -->
                    <?php if (!empty($lowStockItems)): ?>
                        <h6 class="text-danger">
                            <i class="fas fa-exclamation-triangle"></i> Low Stock Items
                        </h6>
                        <?php foreach ($lowStockItems as $item): ?>
                            <div class="alert alert-warning alert-sm py-2" role="alert">
                                <strong><?php echo htmlspecialchars($item['feed_type']); ?></strong> 
                                is running low (<?php echo $item['quantity']; ?> kg remaining)
                            </div>
                        <?php endforeach; ?>
                        <hr>
                    <?php endif; ?>

                    <!-- Upcoming Events -->
                    <?php if (!empty($upcomingEvents)): ?>
                        <h6 class="text-info">
                            <i class="fas fa-calendar"></i> Upcoming Events
                        </h6>
                        <?php foreach ($upcomingEvents as $event): ?>
                            <div class="alert alert-info alert-sm py-2" role="alert">
                                <strong><?php echo ucfirst($event['type']); ?></strong> for 
                                <?php echo htmlspecialchars($event['flock_name']); ?>
                                <?php if ($event['medication_name']): ?>
                                    (<?php echo htmlspecialchars($event['medication_name']); ?>)
                                <?php endif; ?>
                                <br>
                                <small>Due: <?php echo formatDate($event['next_dose_date']); ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">No upcoming events</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Sample chart data (replace with actual data from PHP)
document.addEventListener('DOMContentLoaded', function() {
    // Egg Production Chart
    const eggCtx = document.getElementById('eggProductionChart').getContext('2d');
    new Chart(eggCtx, {
        type: 'line',
        data: {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
            datasets: [{
                label: 'Eggs Collected',
                data: [1200, 1350, 1100, 1400, 1600, 1750],
                borderColor: 'rgb(78, 115, 223)',
                backgroundColor: 'rgba(78, 115, 223, 0.1)',
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });

    // Flock Status Chart
    const flockCtx = document.getElementById('flockStatusChart').getContext('2d');
    new Chart(flockCtx, {
        type: 'doughnut',
        data: {
            labels: ['Active', 'Inactive', 'Sold'],
            datasets: [{
                data: [<?php echo $totalFlocks; ?>, 2, 1],
                backgroundColor: ['#1cc88a', '#858796', '#36b9cc']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>