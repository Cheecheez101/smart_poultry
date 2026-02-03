<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$page_title = 'My Profile';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$success_message = '';
$error_message = '';

// Fetch user record
$stmt = $pdo->prepare("SELECT id, username, full_name, email, role, created_at, last_login, status, password FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    die('User not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        if ($action === 'update_profile') {
            $username = trim($_POST['username'] ?? '');
            $full_name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if ($username === '' || $full_name === '') {
                throw new Exception('Username and full name are required.');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }

            // Ensure username is unique
            $check = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
            $check->execute([$username, $user_id]);
            if ($check->fetch()) {
                throw new Exception('That username is already taken.');
            }

            $stmt = $pdo->prepare('UPDATE users SET username = ?, full_name = ?, email = ? WHERE id = ?');
            $stmt->execute([$username, $full_name, $email !== '' ? $email : null, $user_id]);

            // Refresh session display values
            $_SESSION['full_name'] = $full_name;
            $_SESSION['email'] = $email;

            logActivity($user_id, 'Updated profile', 'users', $user_id);

            $success_message = 'Profile updated successfully.';

            // Refresh user record
            $stmt = $pdo->prepare("SELECT id, username, full_name, email, role, created_at, last_login, status, password FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
        }

        if ($action === 'change_password') {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if ($new_password === '' || $confirm_password === '' || $current_password === '') {
                throw new Exception('All password fields are required.');
            }

            if (!password_verify($current_password, $user['password'])) {
                throw new Exception('Current password is incorrect.');
            }

            if (strlen($new_password) < 8) {
                throw new Exception('New password must be at least 8 characters.');
            }

            if ($new_password !== $confirm_password) {
                throw new Exception('New password and confirmation do not match.');
            }

            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->execute([$hash, $user_id]);

            logActivity($user_id, 'Changed password', 'users', $user_id);
            $success_message = 'Password changed successfully.';

            // Refresh user record (password hash updated)
            $user['password'] = $hash;
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    } catch (PDOException $e) {
        $error_message = 'Database error: ' . $e->getMessage();
    }
}

include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0"><i class="fas fa-user me-2"></i>My Profile</h2>
        <a class="btn btn-outline-secondary" href="dashboard.php"><i class="fas fa-arrow-left me-1"></i>Back</a>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width:56px;height:56px;">
                            <i class="fas fa-user fa-lg text-secondary"></i>
                        </div>
                        <div>
                            <div class="h5 mb-0"><?= htmlspecialchars($user['full_name']) ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($user['role']) ?> • <?= htmlspecialchars($user['status']) ?></div>
                        </div>
                    </div>

                    <div class="small text-muted">Username</div>
                    <div class="mb-2 fw-semibold"><?= htmlspecialchars($user['username']) ?></div>

                    <div class="small text-muted">Email</div>
                    <div class="mb-2 fw-semibold"><?= htmlspecialchars($user['email'] ?? '-') ?></div>

                    <div class="small text-muted">Created</div>
                    <div class="mb-2 fw-semibold"><?= htmlspecialchars($user['created_at']) ?></div>

                    <div class="small text-muted">Last Login</div>
                    <div class="fw-semibold"><?= htmlspecialchars($user['last_login'] ?? '-') ?></div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Update Profile</strong></div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="name@example.com">
                        </div>

                        <div class="col-12 d-flex justify-content-end">
                            <button class="btn btn-primary" type="submit"><i class="fas fa-save me-1"></i>Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong>Change Password</strong></div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="change_password">

                        <div class="col-md-4">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" minlength="8" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="8" required>
                        </div>

                        <div class="col-12 d-flex justify-content-end">
                            <button class="btn btn-warning" type="submit"><i class="fas fa-key me-1"></i>Update Password</button>
                        </div>
                    </form>
                    <div class="small text-muted mt-2">Password must be at least 8 characters.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
