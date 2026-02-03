<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$page_title = 'Settings';
$breadcrumbs = [
    ['title' => 'Settings']
];

$current_user = getCurrentUser();
$userId = (int)($current_user['id'] ?? 0);

// Ensure user_settings table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_setting (user_id, setting_key),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
} catch (PDOException $e) {
    // Ignore table creation errors in case of permissions
}

function getUserSetting(PDO $pdo, int $userId, string $key, $default = null) {
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = ? LIMIT 1');
        $stmt->execute([$userId, $key]);
        $val = $stmt->fetchColumn();
        return ($val === false || $val === null) ? $default : $val;
    } catch (PDOException $e) {
        return $default;
    }
}

function setUserSetting(PDO $pdo, int $userId, string $key, string $value): bool {
    try {
        $stmt = $pdo->prepare('INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        return $stmt->execute([$userId, $key, $value]);
    } catch (PDOException $e) {
        return false;
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($token)) {
        setMessage('Security check failed. Please try again.', 'danger');
        header('Location: ' . APP_URL . 'pages/settings.php');
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_preferences') {
        $sidebarCollapsed = isset($_POST['sidebar_collapsed_default']) ? '1' : '0';
        $reorderAlerts = isset($_POST['pref_reorder_alerts']) ? '1' : '0';
        $vaccinationAlerts = isset($_POST['pref_vaccination_alerts']) ? '1' : '0';

        setUserSetting($pdo, $userId, 'sidebar_collapsed_default', $sidebarCollapsed);
        setUserSetting($pdo, $userId, 'pref_reorder_alerts', $reorderAlerts);
        setUserSetting($pdo, $userId, 'pref_vaccination_alerts', $vaccinationAlerts);

        logActivity($userId, 'Updated settings');
        setMessage('Settings saved.', 'success');
        header('Location: ' . APP_URL . 'pages/settings.php');
        exit();
    }

    if ($action === 'clear_remember_token') {
        try {
            $stmt = $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            // ignore
        }

        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/');
            unset($_COOKIE['remember_token']);
        }

        logActivity($userId, 'Cleared remember me token');
        setMessage('Remember-me token cleared for this device.', 'success');
        header('Location: ' . APP_URL . 'pages/settings.php');
        exit();
    }

    // Admin maintenance actions (demo/dev convenience)
    if (($current_user['role'] ?? '') === 'admin' && $action === 'reset_demo_passwords') {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, status = 'active' WHERE username IN ('admin','manager','worker') OR email IN ('admin@smartpoultry.com','manager@smartpoultry.com','worker@smartpoultry.com')");
        $stmt->execute([$hash]);

        logActivity($userId, 'Reset demo user passwords');
        setMessage('Demo passwords reset to: admin123', 'success');
        header('Location: ' . APP_URL . 'pages/settings.php');
        exit();
    }

    if (($current_user['role'] ?? '') === 'admin' && $action === 'ensure_user_schema') {
        try {
            $cols = $pdo->query('DESCRIBE users')->fetchAll(PDO::FETCH_ASSOC);
            $fields = array_map(fn($c) => $c['Field'], $cols);

            if (!in_array('phone', $fields, true)) {
                $pdo->exec('ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER email');
            }
            if (!in_array('updated_at', $fields, true)) {
                $pdo->exec('ALTER TABLE users ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
            }

            logActivity($userId, 'Ensured users schema');
            setMessage('User schema checked/updated.', 'success');
        } catch (PDOException $e) {
            setMessage('Schema update failed: ' . $e->getMessage(), 'danger');
        }

        header('Location: ' . APP_URL . 'pages/settings.php');
        exit();
    }
}

$csrf = generateCSRFToken();

// Load current preferences
$sidebarCollapsedDefault = getUserSetting($pdo, $userId, 'sidebar_collapsed_default', '0') === '1';
$prefReorder = getUserSetting($pdo, $userId, 'pref_reorder_alerts', '1') === '1';
$prefVaccination = getUserSetting($pdo, $userId, 'pref_vaccination_alerts', '1') === '1';

// Load user info for display
$userRow = null;
try {
    $stmt = $pdo->prepare('SELECT id, username, full_name, email, phone, role, status, last_login, created_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $userRow = $stmt->fetch();
} catch (PDOException $e) {
    $userRow = null;
}

include '../includes/header.php';
?>

<div class="container-fluid px-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Settings</h1>
                    <p class="text-muted mb-0">Preferences and security options for your account.</p>
                </div>
                <a href="<?php echo APP_URL; ?>pages/profile.php" class="btn btn-outline-primary">
                    <i class="fas fa-user me-2"></i>Manage Profile
                </a>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-sliders-h me-2 text-primary"></i>Preferences</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action" value="save_preferences">

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="sidebarCollapsed" name="sidebar_collapsed_default" <?php echo $sidebarCollapsedDefault ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="sidebarCollapsed">Collapse sidebar by default</label>
                            <div class="form-text">Keeps the sidebar minimized on desktop screens.</div>
                        </div>

                        <hr>

                        <h6 class="text-muted">Alerts</h6>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="prefReorder" name="pref_reorder_alerts" <?php echo $prefReorder ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="prefReorder">Show reorder / low stock alerts</label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="prefVaccination" name="pref_vaccination_alerts" <?php echo $prefVaccination ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="prefVaccination">Show vaccination reminders</label>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Settings
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-shield-alt me-2 text-success"></i>Security</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>Password</strong>
                                <div class="text-muted small">Change password from your profile page.</div>
                            </div>
                            <a class="btn btn-outline-secondary" href="<?php echo APP_URL; ?>pages/profile.php">
                                <i class="fas fa-key me-2"></i>Change
                            </a>
                        </div>
                    </div>

                    <hr>

                    <form method="post" action="" onsubmit="return confirm('Clear remember-me token for this device?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action" value="clear_remember_token">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>Remember Me</strong>
                                <div class="text-muted small">If you used “Remember me”, you can revoke it here.</div>
                            </div>
                            <button type="submit" class="btn btn-outline-danger">
                                <i class="fas fa-trash me-2"></i>Clear
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-id-card me-2 text-info"></i>Account</h5>
                </div>
                <div class="card-body">
                    <?php if ($userRow): ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="text-muted small">Username</div>
                                <div><strong><?php echo htmlspecialchars($userRow['username'] ?? ''); ?></strong></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Role</div>
                                <div><span class="badge bg-secondary"><?php echo htmlspecialchars($userRow['role'] ?? ''); ?></span></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Email</div>
                                <div><?php echo htmlspecialchars($userRow['email'] ?? '-'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Status</div>
                                <div><span class="badge <?php echo ($userRow['status'] ?? '') === 'active' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo htmlspecialchars($userRow['status'] ?? ''); ?></span></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Last Login</div>
                                <div><?php echo !empty($userRow['last_login']) ? htmlspecialchars($userRow['last_login']) : '-'; ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small">Created</div>
                                <div><?php echo !empty($userRow['created_at']) ? htmlspecialchars($userRow['created_at']) : '-'; ?></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-muted">Unable to load account details.</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (($current_user['role'] ?? '') === 'admin'): ?>
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-tools me-2 text-warning"></i>Maintenance (Admin)</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning mb-3">
                        These actions are intended for local/demo environments.
                    </div>

                    <form method="post" class="mb-3" onsubmit="return confirm('Reset demo passwords to admin123?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action" value="reset_demo_passwords">
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-undo me-2"></i>Reset Demo Passwords
                        </button>
                        <div class="form-text">Resets admin/manager/worker passwords to <strong>admin123</strong>.</div>
                    </form>

                    <form method="post" onsubmit="return confirm('Apply schema check to users table?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="action" value="ensure_user_schema">
                        <button type="submit" class="btn btn-outline-secondary">
                            <i class="fas fa-database me-2"></i>Ensure User Schema
                        </button>
                        <div class="form-text">Adds missing columns (phone/updated_at) if needed.</div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
