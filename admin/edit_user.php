<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Check if user is admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

$page_title = 'Edit User';
$error = '';
$success = '';
$user = null;

// Get user ID from URL
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$user_id) {
    header('Location: users.php');
    exit;
}

// Get user data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        $error = 'User not found.';
    }
} catch (PDOException $e) {
    $error = 'Error loading user: ' . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $username = sanitizeInput($_POST['username'] ?? '');
    $full_name = sanitizeInput($_POST['full_name'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'worker';
    $status = $_POST['status'] ?? 'active';
    $change_password = isset($_POST['change_password']);
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($username)) {
        $error = 'Username is required';
    } elseif (empty($full_name)) {
        $error = 'Full name is required';
    } elseif (!isValidEmail($email) && !empty($email)) {
        $error = 'Please enter a valid email address';
    } elseif (!in_array($role, ['admin', 'manager', 'worker'])) {
        $error = 'Invalid role selected';
    } elseif (!in_array($status, ['active', 'inactive'])) {
        $error = 'Invalid status selected';
    } elseif ($change_password && strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long';
    } elseif ($change_password && $password !== $confirm_password) {
        $error = 'Passwords do not match';
    } else {
        try {
            // Check if username already exists (excluding current user)
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$username, $user_id]);
            if ($stmt->fetch()) {
                $error = 'Username already exists';
            } else {
                // Update user
                if ($change_password) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        UPDATE users SET
                            username = ?, full_name = ?, email = ?, phone = ?,
                            role = ?, status = ?, password = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$username, $full_name, $email, $phone, $role, $status, $hashed_password, $user_id]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE users SET
                            username = ?, full_name = ?, email = ?, phone = ?,
                            role = ?, status = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$username, $full_name, $email, $phone, $role, $status, $user_id]);
                }

                // Log activity
                logActivity($_SESSION['user_id'], 'edit_user', "Edited user: {$username} (ID: {$user_id})");

                $success = "User '{$username}' has been updated successfully!";

                // Refresh user data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();

                // Redirect after 2 seconds
                header("Refresh: 2; url=users.php");
            }
        } catch (PDOException $e) {
            $error = "Error updating user: " . $e->getMessage();
        }
    }
}

include '../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Edit User</h1>
        <a href="users.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Users
        </a>
    </div>

    <?php if ($user): ?>
        <div class="row">
            <div class="col-lg-8">
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">User Information</h6>
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

                        <!-- Edit User Form -->
                        <form method="POST" action="" class="needs-validation" novalidate>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="username" name="username"
                                           value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                    <div class="invalid-feedback">
                                        Please provide a username.
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="full_name" name="full_name"
                                           value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                    <div class="invalid-feedback">
                                        Please provide a full name.
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email"
                                           value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                                    <div class="invalid-feedback">
                                        Please provide a valid email address.
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label">Phone</label>
                                    <input type="tel" class="form-control" id="phone" name="phone"
                                           value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                                    <select class="form-select" id="role" name="role" required>
                                        <option value="worker" <?php echo $user['role'] === 'worker' ? 'selected' : ''; ?>>Worker</option>
                                        <option value="manager" <?php echo $user['role'] === 'manager' ? 'selected' : ''; ?>>Manager</option>
                                        <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                                    </select>
                                    <div class="invalid-feedback">
                                        Please select a role.
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="active" <?php echo $user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $user['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                    <div class="invalid-feedback">
                                        Please select a status.
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="change_password" name="change_password">
                                    <label class="form-check-label" for="change_password">
                                        Change Password
                                    </label>
                                </div>
                            </div>

                            <div id="password_fields" style="display: none;">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="password" class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="password" name="password">
                                        <div class="invalid-feedback">
                                            Please provide a password.
                                        </div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                        <div class="invalid-feedback">
                                            Please confirm the password.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update User
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">User Statistics</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <strong>Created:</strong> <?php echo formatDate($user['created_at']); ?>
                        </div>
                        <div class="mb-3">
                            <strong>Last Login:</strong> <?php echo $user['last_login'] ? formatDate($user['last_login']) : 'Never'; ?>
                        </div>
                        <div class="mb-3">
                            <strong>Account Status:</strong>
                            <span class="badge bg-<?php echo $user['status'] === 'active' ? 'success' : 'secondary'; ?> ms-2">
                                <?php echo ucfirst($user['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Password Requirements</h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li><i class="fas fa-check text-success"></i> At least 6 characters long</li>
                            <li><i class="fas fa-info text-info"></i> Use a mix of letters, numbers, and symbols</li>
                            <li><i class="fas fa-info text-info"></i> Avoid common passwords</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-triangle"></i> User not found.
        </div>
    <?php endif; ?>
</div>

<script>
// Toggle password fields
document.getElementById('change_password').addEventListener('change', function() {
    const passwordFields = document.getElementById('password_fields');
    if (this.checked) {
        passwordFields.style.display = 'block';
        document.getElementById('password').required = true;
        document.getElementById('confirm_password').required = true;
    } else {
        passwordFields.style.display = 'none';
        document.getElementById('password').required = false;
        document.getElementById('confirm_password').required = false;
    }
});

// Password confirmation validation
document.getElementById('confirm_password').addEventListener('input', function() {
    const password = document.getElementById('password').value;
    const confirmPassword = this.value;

    if (password !== confirmPassword) {
        this.setCustomValidity('Passwords do not match');
    } else {
        this.setCustomValidity('');
    }
});
</script>

<?php include '../includes/footer.php'; ?>