<?php
require_once 'includes/config.php';

try {
    // Add purpose column
    $pdo->exec("ALTER TABLE flocks ADD COLUMN purpose ENUM('egg_production', 'meat_production', 'dual_purpose') DEFAULT 'egg_production' AFTER breed");

    // Add status column
    $pdo->exec("ALTER TABLE flocks ADD COLUMN status ENUM('active', 'inactive', 'sold', 'slaughtered') DEFAULT 'active' AFTER expected_slaughter_date");

    echo "Database updated successfully!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>