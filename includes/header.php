<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Get current user info
$current_user = getCurrentUser();
$current_page = basename($_SERVER['PHP_SELF'], '.php');

// Get unread alerts count
$unread_alerts = 0;
$sidebar_collapsed_default = false;
if ($current_user) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM alerts WHERE created_for = ? AND status = 'unread'");
    $stmt->execute([$current_user['id']]);
    $alert_count = $stmt->fetch();
    $unread_alerts = $alert_count['count'];

    // Sidebar preference (best-effort)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_setting (user_id, setting_key),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        $pref = $pdo->prepare("SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = 'sidebar_collapsed_default' LIMIT 1");
        $pref->execute([$current_user['id']]);
        $val = $pref->fetchColumn();
        $sidebar_collapsed_default = ($val === '1' || strtolower((string)$val) === 'true');
    } catch (PDOException $e) {
        $sidebar_collapsed_default = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Dashboard'; ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Custom CSS -->
    <style>
        :root {
            --sidebar-width: 280px;
            --header-height: 60px;
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
            --accent-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-bg: #f8f9fa;
            --border-color: #dee2e6;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light-bg);
            font-size: 14px;
        }
        
        .wrapper {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: all 0.3s;
        }
        
        .sidebar.collapsed {
            width: 70px;
        }
        
        .sidebar-header {
            padding: 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }
        
        .sidebar-header h4 {
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
        }
        
        .sidebar.collapsed .sidebar-header h4 {
            display: none;
        }
        
        .nav-menu {
            padding: 1rem 0;
        }
        
        .nav-item {
            margin: 0.25rem 0;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.25rem;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        
        .nav-link:hover, .nav-link.active {
            color: white;
            background-color: rgba(255,255,255,0.1);
            border-left-color: var(--accent-color);
        }
        
        .nav-link i {
            width: 20px;
            margin-right: 0.75rem;
            text-align: center;
        }
        
        .sidebar.collapsed .nav-link {
            justify-content: center;
            padding: 0.75rem;
        }
        
        .sidebar.collapsed .nav-link span {
            display: none;
        }
        
        .sidebar.collapsed .nav-link i {
            margin-right: 0;
        }
        
        .nav-submenu {
            background-color: rgba(0,0,0,0.2);
            margin-left: 2rem;
            border-radius: 0.375rem;
            overflow: hidden;
        }
        
        .sidebar.collapsed .nav-submenu {
            display: none;
        }
        
        .nav-submenu .nav-link {
            padding: 0.5rem 1rem;
            border-left: none;
            font-size: 0.9rem;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: all 0.3s;
        }
        
        .sidebar.collapsed + .main-content {
            margin-left: 70px;
        }
        
        /* Top Navigation */
        .top-navbar {
            background: white;
            padding: 0.75rem 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 999;
        }
        
        .navbar-left {
            display: flex;
            align-items: center;
        }
        
        .sidebar-toggle {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--secondary-color);
            cursor: pointer;
            margin-right: 1rem;
        }
        
        .breadcrumb {
            background: none;
            padding: 0;
            margin: 0;
            font-size: 0.9rem;
        }
        
        .navbar-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .notification-bell {
            position: relative;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--secondary-color);
            cursor: pointer;
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -8px;
            background: var(--danger-color);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .user-dropdown {
            position: relative;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 0.375rem;
            transition: background-color 0.3s;
        }
        
        .user-info:hover {
            background-color: var(--light-bg);
        }
        
        .user-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin-right: 0.5rem;
            font-weight: 600;
        }
        
        .user-details h6 {
            margin: 0;
            font-size: 0.9rem;
            color: var(--secondary-color);
        }
        
        .user-details small {
            color: #6c757d;
            font-size: 0.8rem;
        }
        
        /* Page Content */
        .page-content {
            padding: 1.5rem;
        }
        
        .page-header {
            margin-bottom: 1.5rem;
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .page-subtitle {
            color: #6c757d;
            font-size: 0.95rem;
        }
        
        /* Cards */
        .card {
            border: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-radius: 0.5rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 0.375rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-bottom: 1rem;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }
        
        .stat-change {
            font-size: 0.8rem;
            display: flex;
            align-items: center;
        }
        
        .stat-change.positive {
            color: var(--success-color);
        }
        
        .stat-change.negative {
            color: var(--danger-color);
        }
        
        /* Alerts */
        .alert {
            border: none;
            border-radius: 0.5rem;
            border-left: 4px solid;
        }
        
        .alert-success {
            background: #d4edda;
            border-left-color: var(--success-color);
            color: #155724;
        }
        
        .alert-danger {
            background: #f8d7da;
            border-left-color: var(--danger-color);
            color: #721c24;
        }
        
        .alert-warning {
            background: #fff3cd;
            border-left-color: var(--warning-color);
            color: #856404;
        }
        
        .alert-info {
            background: #d1ecf1;
            border-left-color: var(--accent-color);
            color: #0c5460;
        }
        
        /* Tables */
        .table {
            font-size: 0.9rem;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: var(--primary-color);
            background-color: var(--light-bg);
        }
        
        /* Buttons */
        .btn {
            border-radius: 0.375rem;
            font-weight: 500;
            padding: 0.5rem 1rem;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-success {
            background-color: var(--success-color);
            border-color: var(--success-color);
        }
        
        .btn-warning {
            background-color: var(--warning-color);
            border-color: var(--warning-color);
        }
        
        .btn-danger {
            background-color: var(--danger-color);
            border-color: var(--danger-color);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .sidebar-header h4,
            .nav-link span,
            .nav-submenu {
                display: none;
            }
            
            .nav-link {
                justify-content: center;
                padding: 0.75rem;
            }
            
            .nav-link i {
                margin-right: 0;
            }
        }
        
        /* Scrollbar Styles */
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.2);
            border-radius: 3px;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <nav class="sidebar<?php echo $sidebar_collapsed_default ? ' collapsed' : ''; ?>" id="sidebar">
            <div class="sidebar-header">
                <h4><i class="fas fa-feather-alt"></i> SmartPoultry</h4>
            </div>
            
            <div class="nav-menu">
                <div class="nav-item">
                    <a href="<?php echo APP_URL; ?>pages/dashboard.php" class="nav-link <?php echo $current_page == 'dashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </div>
                
                <div class="nav-item">
                    <a href="<?php echo APP_URL; ?>pages/flocks/list_flocks.php" class="nav-link <?php echo in_array($current_page, ['list_flocks', 'add_flock', 'edit_flock']) ? 'active' : ''; ?>">
                        <i class="fas fa-dove"></i>
                        <span>Flocks Management</span>
                    </a>
                    <div class="nav-submenu">
                        <a href="<?php echo APP_URL; ?>pages/flocks/list_flocks.php" class="nav-link">
                            <i class="fas fa-list"></i> <span>All Flocks</span>
                        </a>
                        <a href="<?php echo APP_URL; ?>pages/flocks/add_flock.php" class="nav-link">
                            <i class="fas fa-plus"></i> <span>Add Flock</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-item">
                    <a href="<?php echo APP_URL; ?>pages/production/egg_production.php" class="nav-link <?php echo in_array($current_page, ['egg_production', 'add_production']) ? 'active' : ''; ?>">
                        <i class="fas fa-egg"></i>
                        <span>Egg Production</span>
                    </a>
                </div>
                
                <div class="nav-item">
                    <a href="<?php echo APP_URL; ?>pages/inventory/feed_inventory.php" class="nav-link <?php echo in_array($current_page, ['feed_inventory', 'add_feed']) ? 'active' : ''; ?>">
                        <i class="fas fa-warehouse"></i>
                        <span>Feed Inventory</span>
                    </a>
                </div>
                
                <div class="nav-item">
                    <a href="<?php echo APP_URL; ?>pages/medication/medication.php" class="nav-link <?php echo in_array($current_page, ['medication', 'add_medication']) ? 'active' : ''; ?>">
                        <i class="fas fa-pills"></i>
                        <span>Health & Medication</span>
                    </a>
                </div>
                
                <div class="nav-item">
                    <a href="<?php echo APP_URL; ?>pages/sales/sales.php" class="nav-link <?php echo in_array($current_page, ['sales', 'add_sale']) ? 'active' : ''; ?>">
                        <i class="fas fa-cash-register"></i>
                        <span>Sales Management</span>
                    </a>
                </div>
                
                <div class="nav-item">
                    <a href="<?php echo APP_URL; ?>pages/suppliers/suppliers.php" class="nav-link <?php echo in_array($current_page, ['suppliers', 'add_supplier']) ? 'active' : ''; ?>">
                        <i class="fas fa-truck"></i>
                        <span>Suppliers</span>
                    </a>
                </div>
                
                <div class="nav-item">
                    <a href="<?php echo APP_URL; ?>pages/reports/reports.php" class="nav-link <?php echo $current_page == 'reports' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports & Analytics</span>
                    </a>
                    <div class="nav-submenu">
                        <a href="<?php echo APP_URL; ?>pages/reports/production_report.php" class="nav-link">
                            <i class="fas fa-chart-line"></i> <span>Production Report</span>
                        </a>
                        <a href="<?php echo APP_URL; ?>pages/reports/financial_report.php" class="nav-link">
                            <i class="fas fa-dollar-sign"></i> <span>Financial Report</span>
                        </a>
                        <a href="<?php echo APP_URL; ?>pages/reports/inventory_report.php" class="nav-link">
                            <i class="fas fa-boxes"></i> <span>Inventory Report</span>
                        </a>
                    </div>
                </div>
                
                <?php if ($current_user && $current_user['role'] == 'admin'): ?>
                <div class="nav-item">
                    <a href="<?php echo APP_URL; ?>admin/users.php" class="nav-link <?php echo $current_page == 'users' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        <span>User Management</span>
                    </a>
                </div>
                
                <div class="nav-item">
                    <a href="<?php echo APP_URL; ?>admin/settings.php" class="nav-link <?php echo $current_page == 'settings' ? 'active' : ''; ?>">
                        <i class="fas fa-cog"></i>
                        <span>System Settings</span>
                    </a>
                </div>
                <?php endif; ?>
                
                <div class="nav-item">
                    <a href="<?php echo APP_URL; ?>pages/alerts.php" class="nav-link <?php echo $current_page == 'alerts' ? 'active' : ''; ?>">
                        <i class="fas fa-bell"></i>
                        <span>Alerts & Notifications</span>
                        <?php if ($unread_alerts > 0): ?>
                        <span class="badge bg-danger ms-auto"><?php echo $unread_alerts; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
        </nav>
        
        <!-- Main Content -->
        <div class="main-content" id="main-content">
            <!-- Top Navigation -->
            <nav class="top-navbar">
                <div class="navbar-left">
                    <button class="sidebar-toggle" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?php echo APP_URL; ?>pages/dashboard.php">Home</a></li>
                            <?php if (isset($breadcrumbs) && is_array($breadcrumbs)): ?>
                                <?php foreach ($breadcrumbs as $crumb): ?>
                                    <?php if (isset($crumb['url'])): ?>
                                        <li class="breadcrumb-item"><a href="<?php echo $crumb['url']; ?>"><?php echo $crumb['title']; ?></a></li>
                                    <?php else: ?>
                                        <li class="breadcrumb-item active"><?php echo $crumb['title']; ?></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ol>
                    </nav>
                </div>
                
                <div class="navbar-right">
                    <a class="notification-bell" title="Notifications" href="<?php echo APP_URL; ?>pages/alerts.php" aria-label="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php if ($unread_alerts > 0): ?>
                        <span class="notification-badge"><?php echo $unread_alerts; ?></span>
                        <?php endif; ?>
                    </a>
                    
                    <div class="user-dropdown dropdown">
                        <button class="user-info" data-bs-toggle="dropdown">
                            <div class="user-avatar">
                                <?php echo $current_user ? strtoupper(substr($current_user['full_name'], 0, 1)) : 'U'; ?>
                            </div>
                            <div class="user-details">
                                <h6><?php echo $current_user['full_name'] ?? 'User'; ?></h6>
                                <small><?php echo ucfirst($current_user['role'] ?? 'Role'); ?></small>
                            </div>
                            <i class="fas fa-chevron-down ms-2"></i>
                        </button>
                        
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?php echo APP_URL; ?>pages/profile.php">
                                <i class="fas fa-user me-2"></i> My Profile
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo APP_URL; ?>pages/settings.php">
                                <i class="fas fa-cog me-2"></i> Settings
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo APP_URL; ?>logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i> Logout
                            </a></li>
                        </ul>
                    </div>
                </div>
            </nav>
            
            <!-- Page Content -->
            <div class="page-content">
                <?php 
                // Display flash messages
                $message = getMessage();
                if ($message): ?>
                <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('collapsed');
        }
        
        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>