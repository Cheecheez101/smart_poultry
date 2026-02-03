<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireLogin();

$page_title = 'Alerts & Notifications';

$user_id = $_SESSION['user_id'];

$success_message = '';
$error_message = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $alert_id = isset($_POST['alert_id']) ? (int)$_POST['alert_id'] : 0;

    try {
        if ($action === 'mark_read' && $alert_id > 0) {
            $stmt = $pdo->prepare("UPDATE alerts SET status = 'read' WHERE id = ? AND created_for = ?");
            $stmt->execute([$alert_id, $user_id]);
            $success_message = 'Alert marked as read.';
        }

        if ($action === 'dismiss' && $alert_id > 0) {
            $stmt = $pdo->prepare("UPDATE alerts SET status = 'dismissed' WHERE id = ? AND created_for = ?");
            $stmt->execute([$alert_id, $user_id]);
            $success_message = 'Alert dismissed.';
        }

        if ($action === 'mark_all_read') {
            $stmt = $pdo->prepare("UPDATE alerts SET status = 'read' WHERE created_for = ? AND status = 'unread'");
            $stmt->execute([$user_id]);
            $success_message = 'All alerts marked as read.';
        }

        if ($action === 'dismiss_all') {
            $stmt = $pdo->prepare("UPDATE alerts SET status = 'dismissed' WHERE created_for = ? AND status IN ('unread','read')");
            $stmt->execute([$user_id]);
            $success_message = 'All alerts dismissed.';
        }

        if ($action === 'generate_alerts') {
            // Create low-stock alerts (existing helper assigns to admin id=1 by default)
            // We will additionally create alerts for the current user.

            // Low stock (feed)
            $stmt = $pdo->query("SELECT id, feed_type, quantity, reorder_level FROM feed_inventory WHERE quantity <= reorder_level AND quantity > 0");
            $count_created = 0;
            while ($row = $stmt->fetch()) {
                $check = $pdo->prepare("SELECT id FROM alerts WHERE alert_type='reorder' AND related_id=? AND created_for=? AND status='unread'");
                $check->execute([$row['id'], $user_id]);
                if (!$check->fetch()) {
                    createAlert(
                        $user_id,
                        'reorder',
                        'Low Stock Alert',
                        "Feed type '{$row['feed_type']}' is running low. Current stock: {$row['quantity']} kg (Reorder level: {$row['reorder_level']} kg)",
                        $row['id'],
                        'high'
                    );
                    $count_created++;
                }
            }

            // Vaccination/medication due soon
            $stmt = $pdo->query("SELECT id, flock_id, medication_name, next_due_date FROM medications WHERE next_due_date IS NOT NULL AND next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
            while ($row = $stmt->fetch()) {
                $check = $pdo->prepare("SELECT id FROM alerts WHERE alert_type='vaccination' AND related_id=? AND created_for=? AND status='unread'");
                $check->execute([$row['id'], $user_id]);
                if (!$check->fetch()) {
                    createAlert(
                        $user_id,
                        'vaccination',
                        'Vaccination Due',
                        "Medication/vaccine '{$row['medication_name']}' is due on {$row['next_due_date']}.",
                        $row['id'],
                        'medium'
                    );
                    $count_created++;
                }
            }

            $success_message = "Generated {$count_created} new alert(s).";
        }
    } catch (PDOException $e) {
        $error_message = 'Action failed: ' . $e->getMessage();
    }
}

// Filters
$status = $_GET['status'] ?? 'all'; // all|unread|read|dismissed
$type = $_GET['type'] ?? 'all'; // all|reorder|vaccination|health|system
$priority = $_GET['priority'] ?? 'all'; // all|low|medium|high|critical
$search = trim($_GET['search'] ?? '');

$where = "WHERE created_for = ?";
$params = [$user_id];

if ($status !== 'all') {
    $where .= " AND status = ?";
    $params[] = $status;
}
if ($type !== 'all') {
    $where .= " AND alert_type = ?";
    $params[] = $type;
}
if ($priority !== 'all') {
    $where .= " AND priority = ?";
    $params[] = $priority;
}
if ($search !== '') {
    $where .= " AND (title LIKE ? OR message LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$stmt = $pdo->prepare("SELECT * FROM alerts $where ORDER BY FIELD(status,'unread','read','dismissed'), FIELD(priority,'critical','high','medium','low'), created_at DESC");
$stmt->execute($params);
$alerts = $stmt->fetchAll();

// Counts
$stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM alerts WHERE created_for = ? GROUP BY status");
$stmt->execute([$user_id]);
$count_rows = $stmt->fetchAll();
$counts = ['unread' => 0, 'read' => 0, 'dismissed' => 0];
foreach ($count_rows as $row) {
    $counts[$row['status']] = (int)$row['cnt'];
}

include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0"><i class="fas fa-bell me-2"></i>Alerts & Notifications</h2>
        <div class="d-flex gap-2">
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="generate_alerts">
                <button type="submit" class="btn btn-outline-primary">
                    <i class="fas fa-rotate me-1"></i>Generate Alerts
                </button>
            </form>
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn btn-outline-success">
                    <i class="fas fa-check-double me-1"></i>Mark All Read
                </button>
            </form>
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="dismiss_all">
                <button type="submit" class="btn btn-outline-secondary">
                    <i class="fas fa-ban me-1"></i>Dismiss All
                </button>
            </form>
        </div>
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

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Unread</div>
                    <div class="h3 mb-0 text-danger"><?= $counts['unread'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Read</div>
                    <div class="h3 mb-0"><?= $counts['read'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Dismissed</div>
                    <div class="h3 mb-0 text-secondary"><?= $counts['dismissed'] ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="all" <?= $status==='all'?'selected':'' ?>>All</option>
                        <option value="unread" <?= $status==='unread'?'selected':'' ?>>Unread</option>
                        <option value="read" <?= $status==='read'?'selected':'' ?>>Read</option>
                        <option value="dismissed" <?= $status==='dismissed'?'selected':'' ?>>Dismissed</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Type</label>
                    <select name="type" class="form-select">
                        <option value="all" <?= $type==='all'?'selected':'' ?>>All</option>
                        <option value="reorder" <?= $type==='reorder'?'selected':'' ?>>Reorder</option>
                        <option value="vaccination" <?= $type==='vaccination'?'selected':'' ?>>Vaccination</option>
                        <option value="health" <?= $type==='health'?'selected':'' ?>>Health</option>
                        <option value="system" <?= $type==='system'?'selected':'' ?>>System</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-select">
                        <option value="all" <?= $priority==='all'?'selected':'' ?>>All</option>
                        <option value="low" <?= $priority==='low'?'selected':'' ?>>Low</option>
                        <option value="medium" <?= $priority==='medium'?'selected':'' ?>>Medium</option>
                        <option value="high" <?= $priority==='high'?'selected':'' ?>>High</option>
                        <option value="critical" <?= $priority==='critical'?'selected':'' ?>>Critical</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Search</label>
                    <input name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="title/message">
                </div>
                <div class="col-md-1 d-grid">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-filter me-1"></i>Go</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white"><strong>Alerts</strong></div>
        <div class="card-body">
            <?php if (empty($alerts)): ?>
                <div class="text-muted">No alerts found for the selected filters.</div>
            <?php else: ?>
                <div class="list-group">
                    <?php foreach ($alerts as $a): ?>
                        <?php
                            $badge = 'secondary';
                            if ($a['priority'] === 'high') $badge = 'warning';
                            if ($a['priority'] === 'critical') $badge = 'danger';
                            if ($a['priority'] === 'medium') $badge = 'info';

                            $status_class = $a['status'] === 'unread' ? 'border-start border-4 border-danger' : '';
                        ?>
                        <div class="list-group-item <?= $status_class ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="me-3">
                                    <div class="d-flex gap-2 align-items-center flex-wrap">
                                        <h6 class="mb-0"><?= htmlspecialchars($a['title']) ?></h6>
                                        <span class="badge bg-<?= $badge ?> text-uppercase"><?= htmlspecialchars($a['priority']) ?></span>
                                        <span class="badge bg-light text-dark text-uppercase"><?= htmlspecialchars($a['alert_type']) ?></span>
                                        <span class="badge bg-<?= $a['status']==='unread' ? 'danger' : ($a['status']==='read' ? 'secondary' : 'dark') ?> text-uppercase"><?= htmlspecialchars($a['status']) ?></span>
                                    </div>
                                    <div class="mt-2 text-muted">
                                        <?= nl2br(htmlspecialchars($a['message'])) ?>
                                    </div>
                                    <div class="small text-muted mt-2">
                                        Created: <?= htmlspecialchars($a['created_at']) ?>
                                        <?php if (!empty($a['expires_at'])): ?>
                                            • Expires: <?= htmlspecialchars($a['expires_at']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <?php if ($a['status'] === 'unread'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="mark_read">
                                            <input type="hidden" name="alert_id" value="<?= (int)$a['id'] ?>">
                                            <button class="btn btn-sm btn-outline-success" type="submit"><i class="fas fa-check me-1"></i>Read</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($a['status'] !== 'dismissed'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="dismiss">
                                            <input type="hidden" name="alert_id" value="<?= (int)$a['id'] ?>">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="fas fa-ban me-1"></i>Dismiss</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
