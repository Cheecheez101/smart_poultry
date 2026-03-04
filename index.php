<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Get current URI to avoid redirect loops
$request_uri = $_SERVER['REQUEST_URI'];

// Only redirect if we're accessing the root
if ($request_uri === '/smart_poultry/' || $request_uri === '/smart_poultry' || $request_uri === '/') {
    // Check if user is already logged in
    if ($auth->isLoggedIn()) {
        // Redirect to dashboard
        header('Location: ' . APP_URL . 'pages/dashboard.php', true, 302);
        exit();
    } else {
        // Redirect to login
        header('Location: ' . APP_URL . 'login.php', true, 302);
        exit();
    }
}
// If accessing index.php directly with query string, allow it through
?>