<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Check if user is already logged in
if ($auth->isLoggedIn()) {
    header('Location: pages/dashboard.php');
    exit();
} else {
    header('Location: login.php');
    exit();
}
?>