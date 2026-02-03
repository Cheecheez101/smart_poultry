<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'smart_poultry');
define('DB_USER', 'root');  // Change this in production
define('DB_PASS', '');      // Change this in production
define('DB_CHARSET', 'utf8mb4');

// Application configuration
define('APP_NAME', 'SmartPoultry Management System');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/smart_poultry/');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', APP_URL . 'uploads/');

// Security settings
define('SESSION_TIMEOUT', 3600); // 1 hour
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes
define('CSRF_TOKEN_EXPIRE', 1800); // 30 minutes

// Default settings
define('DEFAULT_TIMEZONE', 'Africa/Nairobi');
define('DATE_FORMAT', 'Y-m-d');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');
define('CURRENCY', 'KSH');
define('EGGS_PER_TRAY', 30);

// Set timezone
date_default_timezone_set(DEFAULT_TIMEZONE);

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database connection using PDO
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    die('Connection failed: ' . $e->getMessage());
}

// Helper functions
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

function formatCurrency($amount) {
    return CURRENCY . ' ' . number_format($amount, 2);
}

function formatDate($date, $format = DATE_FORMAT) {
    if ($date) {
        return date($format, strtotime($date));
    }
    return '';
}

function formatDateTime($datetime, $format = DATETIME_FORMAT) {
    if ($datetime) {
        return date($format, strtotime($datetime));
    }
    return '';
}

// Generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token']) || time() > $_SESSION['csrf_token_expire']) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_expire'] = time() + CSRF_TOKEN_EXPIRE;
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || time() > $_SESSION['csrf_token_expire']) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Log user activity
function logActivity($user_id, $action, $table_name = null, $record_id = null) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        INSERT INTO system_logs (user_id, action, table_name, record_id, ip_address, user_agent) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $payload = [
        $user_id ?: null,
        $action,
        $table_name,
        $record_id,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ];

    try {
        $stmt->execute($payload);
    } catch (PDOException $e) {
        // If the provided user_id doesn't exist, FK will fail. Retry with NULL user_id.
        if (($e->getCode() ?? '') === '23000') {
            $payload[0] = null;
            try {
                $stmt->execute($payload);
                return;
            } catch (PDOException $e2) {
                // Fall through to error_log
            }
        }
        error_log('logActivity error: ' . $e->getMessage());
    }
}

// Create alert/notification
function createAlert($user_id, $type, $title, $message, $related_id = null, $priority = 'medium') {
    global $pdo;
    
    $stmt = $pdo->prepare("
        INSERT INTO alerts (alert_type, title, message, related_id, priority, created_for) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([$type, $title, $message, $related_id, $priority, $user_id]);
}

// Check for low stock and create alerts
function checkLowStock() {
    global $pdo;
    
    $stmt = $pdo->query("
        SELECT * FROM feed_inventory 
        WHERE quantity <= reorder_level AND quantity > 0
    ");
    
    while ($row = $stmt->fetch()) {
        // Check if alert already exists for this item
        $check = $pdo->prepare("
            SELECT id FROM alerts 
            WHERE alert_type = 'reorder' 
            AND related_id = ? 
            AND status = 'unread'
        ");
        $check->execute([$row['id']]);
        
        if (!$check->fetch()) {
            createAlert(
                1, // Admin user ID
                'reorder',
                'Low Stock Alert',
                "Feed type '{$row['feed_type']}' is running low. Current stock: {$row['quantity']} kg",
                $row['id'],
                'high'
            );
        }
    }
}

// Error handling and logging
function handleError($message, $file = null, $line = null) {
    $error_msg = date('[Y-m-d H:i:s] ') . $message;
    if ($file && $line) {
        $error_msg .= " in $file on line $line";
    }
    $error_msg .= PHP_EOL;
    
    // Log to file
    error_log($error_msg, 3, __DIR__ . '/../logs/app_errors.log');
    
    // In development, also display error
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        echo "<div class='alert alert-danger'>Error: $message</div>";
    }
}

// Success and error message handling
function setMessage($message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

function getMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'success';
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

// File upload handling
function handleFileUpload($file, $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'], $max_size = 2097152) {
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload error occurred'];
    }
    
    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File too large. Maximum size: 2MB'];
    }
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }
    
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $filepath = UPLOAD_DIR . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename, 'url' => UPLOAD_URL . $filename];
    }
    
    return ['success' => false, 'message' => 'Failed to save file'];
}

// Run low stock check periodically
if (rand(1, 100) == 1) { // 1% chance to run on each page load
    checkLowStock();
}
?>