<?php
require_once 'includes/config.php';
$stmt = $pdo->query('SELECT * FROM flocks');
$flocks = $stmt->fetchAll();
foreach ($flocks as $f) {
    echo "Flock: " . $f['batch_number'] . ", status: " . $f['status'] . ", current_count: " . $f['current_count'] . "\n";
}
?>