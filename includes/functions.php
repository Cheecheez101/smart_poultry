<?php
/**
 * Helper Functions for SmartPoultry Management System
 */

/**
 * Format date for display
 */
if (!function_exists('formatDate')) {
    function formatDate($date, $format = 'M j, Y') {
        if (!$date) return '-';
        return date($format, strtotime($date));
    }
}

/**
 * Format currency
 */
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        return '$' . number_format($amount, 2);
    }
}

/**
 * Display alert message
 */
function showAlert($message, $type = 'info') {
    echo "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>
            {$message}
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
          </div>";
}

/**
 * Sanitize input
 */
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data) {
        return htmlspecialchars(trim($data));
    }
}

/**
 * Validate email
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Generate random password
 */
function generatePassword($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    return substr(str_shuffle($chars), 0, $length);
}

/**
 * Calculate age in weeks
 */
function calculateAge($date) {
    $diff = time() - strtotime($date);
    return floor($diff / (60 * 60 * 24 * 7));
}

/**
 * Get bird mortality percentage
 */
function getMortalityRate($deaths, $totalBirds) {
    if ($totalBirds == 0) return 0;
    return round(($deaths / $totalBirds) * 100, 2);
}

/**
 * Format number with commas
 */
function formatNumber($number) {
    return number_format($number);
}

/**
 * Get status badge class
 */
function getStatusBadge($status) {
    switch (strtolower($status)) {
        case 'active':
            return 'badge bg-success';
        case 'inactive':
            return 'badge bg-secondary';
        case 'sold':
            return 'badge bg-info';
        case 'pending':
            return 'badge bg-warning';
        case 'completed':
            return 'badge bg-success';
        case 'overdue':
            return 'badge bg-danger';
        default:
            return 'badge bg-secondary';
    }
}

/**
 * Check if stock is low
 */
function isLowStock($current, $minimum) {
    return $current <= $minimum;
}

/**
 * Log activity
 */
if (!function_exists('logActivity')) {
    function logActivity($userId, $action, $details = '') {
        global $pdo;
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO activity_log (user_id, action, details, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $action, $details]);
        } catch (PDOException $e) {
            error_log("Activity log error: " . $e->getMessage());
        }
    }
}

/**
 * Send notification
 */
function sendNotification($userId, $title, $message, $type = 'info') {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $title, $message, $type]);
    } catch (PDOException $e) {
        error_log("Notification error: " . $e->getMessage());
    }
}

/**
 * Get upcoming events/reminders
 */
function getUpcomingEvents($days = 7) {
    global $pdo;
    
    $events = [];
    
    // Vaccination reminders
    try {
        $stmt = $pdo->prepare("
            SELECT 'vaccination' as type, f.batch_number as flock_name, m.medication_name, m.next_due_date as next_dose_date
            FROM medications m
            JOIN flocks f ON m.flock_id = f.id
            WHERE m.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
            AND m.next_due_date IS NOT NULL
        ");
        $stmt->execute([$days]);
        $events = array_merge($events, $stmt->fetchAll());
    } catch (PDOException $e) {
        error_log("Events error: " . $e->getMessage());
    }
    
    return $events;
}

/**
 * Calculate feed consumption rate
 */
function calculateFeedConsumption($flockId, $days = 30) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT AVG(daily_consumption) as avg_consumption
            FROM feed_consumption 
            WHERE flock_id = ? 
            AND consumption_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        ");
        $stmt->execute([$flockId, $days]);
        $result = $stmt->fetch();
        
        return $result ? round($result['avg_consumption'], 2) : 0;
    } catch (PDOException $e) {
        return 0;
    }
}
?>