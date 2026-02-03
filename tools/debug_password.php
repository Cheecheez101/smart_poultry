<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';

$stmt = $pdo->query("SELECT id, username, email, password, status FROM users ORDER BY id ASC LIMIT 3");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Users (first 3):\n";
foreach ($users as $u) {
    echo "- id={$u['id']} username=" . ($u['username'] ?? 'NULL') . " email=" . ($u['email'] ?? 'NULL') . " status={$u['status']}\n";
    echo "  hash={$u['password']}\n";
}

if (!$users) {
    echo "No users found.\n";
    exit(0);
}

$testPasswords = ['admin123', 'password', 'Admin123', 'admin'];
foreach ($testPasswords as $pw) {
    $ok = password_verify($pw, $users[0]['password']);
    echo "Verify '{$pw}' against first user: " . ($ok ? 'YES' : 'NO') . "\n";
}
