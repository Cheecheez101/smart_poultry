<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function isPasswordHash(string $value): bool {
    return (bool)preg_match('/^\$(2y|2a|argon2i|argon2id)\$/', $value);
}

// 1) Ensure users table has columns expected by the app
$alterStatements = [];
if (!columnExists($pdo, 'users', 'phone')) {
    $alterStatements[] = "ADD COLUMN phone VARCHAR(20) NULL AFTER email";
}
if (!columnExists($pdo, 'users', 'updated_at')) {
    $alterStatements[] = "ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at";
}

if ($alterStatements) {
    $sql = "ALTER TABLE users\n    " . implode(",\n    ", $alterStatements);
    $pdo->exec($sql);
    echo "Updated users table columns.\n";
} else {
    echo "Users table columns already up to date.\n";
}

// 2) Ensure default users exist; repair plaintext passwords
$resetPasswords = in_array('--reset-password', $argv, true);
$defaultPassword = 'admin123';

$defaults = [
    ['admin', 'System Administrator', 'admin@smartpoultry.com', '+254700000000', 'admin'],
    ['manager', 'Farm Manager', 'manager@smartpoultry.com', '+254700000001', 'manager'],
    ['worker', 'Farm Worker', 'worker@smartpoultry.com', '+254700000002', 'worker'],
];

$select = $pdo->prepare('SELECT id, password FROM users WHERE username = ?');
$insert = $pdo->prepare(
    "INSERT INTO users (username, full_name, email, phone, role, password, status) VALUES (?, ?, ?, ?, ?, ?, 'active')"
);
$update = $pdo->prepare(
    "UPDATE users SET full_name = ?, email = ?, phone = ?, role = ?, status = 'active' WHERE id = ?"
);
$updatePw = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');

foreach ($defaults as [$username, $fullName, $email, $phone, $role]) {
    $select->execute([$username]);
    $row = $select->fetch(PDO::FETCH_ASSOC);

    $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);

    if (!$row) {
        $insert->execute([$username, $fullName, $email, $phone, $role, $hash]);
        echo "Inserted user {$username} ({$email}).\n";
        continue;
    }

    $update->execute([$fullName, $email, $phone, $role, $row['id']]);
    echo "Updated user {$username} ({$email}).\n";

    $stored = (string)($row['password'] ?? '');
    if ($resetPasswords || ($stored !== '' && !isPasswordHash($stored))) {
        $updatePw->execute([$hash, $row['id']]);
        echo "- Reset password for {$username} to {$defaultPassword}.\n";
    }
}

echo "Done. Login: admin@smartpoultry.com / {$defaultPassword}\n";
