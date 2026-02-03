<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/config.php';

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
echo "Tables in DB '" . DB_NAME . "':\n";
foreach ($tables as $t) {
    echo "- {$t[0]}\n";
}

$check = ['users','login_attempts','remember_tokens'];
foreach ($check as $name) {
    $exists = false;
    foreach ($tables as $t) {
        if ($t[0] === $name) { $exists = true; break; }
    }
    echo "Exists {$name}: " . ($exists ? 'YES' : 'NO') . "\n";
}
