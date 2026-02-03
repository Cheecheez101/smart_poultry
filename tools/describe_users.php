<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/config.php';

$cols = $pdo->query('DESCRIBE users')->fetchAll(PDO::FETCH_ASSOC);
echo "Columns for users:\n";
foreach ($cols as $c) {
    echo "- {$c['Field']}\t{$c['Type']}\tNULL={$c['Null']}\tDEFAULT=" . ($c['Default'] ?? 'NULL') . "\n";
}
