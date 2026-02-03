<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireRole('admin');

$page_title = 'System Settings';
$breadcrumbs = [
    ['title' => 'Admin', 'url' => APP_URL . 'pages/dashboard.php'],
    ['title' => 'System Settings']
];

// For now, reuse the user settings page behavior but add a system overview.
$csrf = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($token)) {
        setMessage('Security check failed. Please try again.', 'danger');
        header('Location: ' . APP_URL . 'admin/settings.php');
        exit();
    }

    $action = $_POST['action'] ?? '';
    $current = getCurrentUser();
    $userId = (int)($current['id'] ?? 0);

    if ($action === 'reset_demo_passwords') {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, status = 'active' WHERE username IN ('admin','manager','worker') OR email IN ('admin@smartpoultry.com','manager@smartpoultry.com','worker@smartpoultry.com')");
        $stmt->execute([$hash]);
        logActivity($userId, 'Reset demo user passwords (admin/settings)');
        setMessage('Demo passwords reset to: admin123', 'success');
        header('Location: ' . APP_URL . 'admin/settings.php');
        exit();
    }

    if ($action === 'ensure_user_schema') {
        try {
            $cols = $pdo->query('DESCRIBE users')->fetchAll(PDO::FETCH_ASSOC);
            $fields = array_map(fn($c) => $c['Field'], $cols);

            if (!in_array('phone', $fields, true)) {
                $pdo->exec('ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER email');
            }
            if (!in_array('updated_at', $fields, true)) {
                $pdo->exec('ALTER TABLE users ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
            }

            logActivity($userId, 'Ensured users schema (admin/settings)');
            setMessage('User schema checked/updated.', 'success');
        } catch (PDOException $e) {
            setMessage('Schema update failed: ' . $e->getMessage(), 'danger');
        }

        header('Location: ' . APP_URL . 'admin/settings.php');
        exit();
    }
}

include '../includes/header.php';
?>

<div class="container-fluid px-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">System Settings</h1>
                    <p class="text-muted mb-0">Admin maintenance and system overview.</p>
                </div>
                <a href="<?php echo APP_URL; ?>pages/settings.php" class="btn btn-outline-primary">
                    <i class="fas fa-user-cog me-2"></i>User Settings
                </a>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-circle-info me-2 text-info"></i>System Overview</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted small">App Name</div>
                            <div><strong><?php echo htmlspecialchars(APP_NAME); ?></strong></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Version</div>
                            <div><?php echo htmlspecialchars(APP_VERSION); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Timezone</div>
                            <div><?php echo htmlspecialchars(DEFAULT_TIMEZONE); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Session Timeout</div>
                            <div><?php echo (int)SESSION_TIMEOUT; ?> seconds</div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Currency</div>
                            <div><?php echo htmlspecialchars(CURRENCY); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Eggs/Tray</div>
                            <div><?php echo (int)EGGS_PER_TRAY; ?></div>
                        </div>
                    </div>
                    <div class="alert alert-secondary mt-3 mb-0">
                        To change these values, edit <code>includes/config.php</code> (they’re currently constants).
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-tools me-2 text-warning"></i>Maintenance</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        These actions are intended for local/demo environments.
                    </div>

                    <form method="post" class="mb-3" onsubmit="return confirm('Reset demo passwords to admin123?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action" value="reset_demo_passwords">
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-undo me-2"></i>Reset Demo Passwords
                        </button>
                    </form>

                    <form method="post" onsubmit="return confirm('Apply schema check to users table?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action" value="ensure_user_schema">
                        <button type="submit" class="btn btn-outline-secondary">
                            <i class="fas fa-database me-2"></i>Ensure User Schema
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
