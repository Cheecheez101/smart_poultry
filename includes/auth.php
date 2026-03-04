<?php
require_once __DIR__ . '/config.php';

class Auth {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // Login user
    public function login($email, $password, $remember = false) {
        try {
            // Check for lockout
            if ($this->isAccountLocked($email)) {
                return ['success' => false, 'message' => 'Account is temporarily locked. Try again later.'];
            }
            
            $stmt = $this->pdo->prepare("
                SELECT id, username, full_name, email, role, password, status, last_login 
                FROM users 
                WHERE (email = ? OR username = ?) AND status = 'active'
            ");
            $stmt->execute([$email, $email]);
            $user = $stmt->fetch();

            if ($user) {
                $storedPassword = (string)($user['password'] ?? '');
                $verified = password_verify($password, $storedPassword);

                // Legacy support: if DB contains plaintext passwords, migrate on successful match.
                if (!$verified && !$this->isPasswordHash($storedPassword) && hash_equals($storedPassword, (string)$password)) {
                    $newHash = password_hash((string)$password, PASSWORD_DEFAULT);
                    $upd = $this->pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                    $upd->execute([$newHash, $user['id']]);
                    $verified = true;
                }

                if ($verified) {
                // Reset login attempts
                $this->resetLoginAttempts($email);
                
                // Update last login
                $this->updateLastLogin($user['id']);
                
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['login_time'] = time();
                
                // Set remember me cookie if requested
                if ($remember) {
                    $token = $this->generateRememberToken($user['id']);
                    setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/'); // 30 days
                }
                
                // Log activity
                logActivity($user['id'], 'User logged in');
                
                return ['success' => true, 'user' => $user];
                }
            }

            // Record failed login attempt
                // Record failed login attempt
                $this->recordLoginAttempt($email);
                return ['success' => false, 'message' => 'Invalid username/email or password'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Login error occurred'];
        }
    }
    
    // Check if user is logged in
    public function isLoggedIn() {
        if (!isset($_SESSION['user_id'])) {
            // Check remember me cookie
            if (isset($_COOKIE['remember_token'])) {
                return $this->loginByRememberToken($_COOKIE['remember_token']);
            }
            return false;
        }
        
        // Check session timeout
        if (isset($_SESSION['login_time']) && 
            (time() - $_SESSION['login_time']) > SESSION_TIMEOUT) {
            $this->logout();
            return false;
        }
        
        return true;
    }
    
    // Logout user
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            logActivity($_SESSION['user_id'], 'User logged out');
        }
        
        // Clear remember me cookie
        if (isset($_COOKIE['remember_token'])) {
            $this->clearRememberToken($_COOKIE['remember_token']);
            setcookie('remember_token', '', time() - 3600, '/');
        }
        
        // Clear session
        $_SESSION = [];
        session_destroy();
    }
    
    // Check user role/permissions
    public function hasRole($required_role) {
        if (!$this->isLoggedIn()) return false;
        
        $user_role = $_SESSION['role'];
        $role_hierarchy = ['worker' => 1, 'manager' => 2, 'admin' => 3];
        
        // Handle array of roles (user needs to have at least one)
        if (is_array($required_role)) {
            foreach ($required_role as $role) {
                if (isset($role_hierarchy[$user_role]) && 
                    isset($role_hierarchy[$role]) && 
                    $role_hierarchy[$user_role] >= $role_hierarchy[$role]) {
                    return true;
                }
            }
            return false;
        }
        
        // Handle single role
        return isset($role_hierarchy[$user_role]) && 
               isset($role_hierarchy[$required_role]) && 
               $role_hierarchy[$user_role] >= $role_hierarchy[$required_role];
    }
    
    // Change password
    public function changePassword($user_id, $current_password, $new_password) {
        try {
            $stmt = $this->pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            if (!$user || !password_verify($current_password, $user['password'])) {
                return ['success' => false, 'message' => 'Current password is incorrect'];
            }
            
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $this->pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            
            logActivity($user_id, 'Password changed');
            
            return ['success' => true, 'message' => 'Password changed successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error changing password'];
        }
    }
    
    // Register new user (admin only)
    public function register($data) {
        try {
            $username = trim((string)($data['username'] ?? ''));
            $email = trim((string)($data['email'] ?? ''));

            if ($username === '') {
                return ['success' => false, 'message' => 'Username is required'];
            }

            // Check if username/email already exists
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ? OR (email IS NOT NULL AND email = ?)");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'Username or email already exists'];
            }
            
            $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO users (username, full_name, email, phone, role, password, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");
            
            $stmt->execute([
                $username,
                $data['full_name'],
                $email !== '' ? $email : null,
                $data['phone'] ?? null,
                $data['role'],
                $hashed_password
            ]);
            
            $user_id = $this->pdo->lastInsertId();
            logActivity($_SESSION['user_id'], 'New user registered', 'users', $user_id);
            
            return ['success' => true, 'message' => 'User registered successfully', 'user_id' => $user_id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Registration error occurred'];
        }
    }
    
    // Update user profile
    public function updateProfile($user_id, $data) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET full_name = ?, phone = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            
            $stmt->execute([
                $data['full_name'],
                $data['phone'],
                $user_id
            ]);
            
            // Update session data
            $_SESSION['full_name'] = $data['full_name'];
            
            logActivity($user_id, 'Profile updated');
            
            return ['success' => true, 'message' => 'Profile updated successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error updating profile'];
        }
    }
    
    // Get user info
    public function getUserInfo($user_id) {
        $stmt = $this->pdo->prepare("
            SELECT id, username, full_name, email, phone, role, status, last_login, created_at 
            FROM users WHERE id = ?
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    }

    private function isPasswordHash(string $value): bool {
        // Common prefixes for password_hash() output.
        return (bool)preg_match('/^\$(2y|2a|argon2i|argon2id)\$/', $value);
    }
    
    // Private methods
    private function isAccountLocked($email) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as attempts 
            FROM login_attempts 
            WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL " . LOGIN_LOCKOUT_TIME . " SECOND)
        ");
        $stmt->execute([$email]);
        $result = $stmt->fetch();
        
        return $result['attempts'] >= MAX_LOGIN_ATTEMPTS;
    }
    
    private function recordLoginAttempt($email) {
        $stmt = $this->pdo->prepare("
            INSERT INTO login_attempts (email, ip_address, attempted_at) 
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$email, $_SERVER['REMOTE_ADDR'] ?? '']);
    }
    
    private function resetLoginAttempts($email) {
        $stmt = $this->pdo->prepare("DELETE FROM login_attempts WHERE email = ?");
        $stmt->execute([$email]);
    }
    
    private function updateLastLogin($user_id) {
        $stmt = $this->pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user_id]);
    }
    
    private function generateRememberToken($user_id) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 days
        
        $stmt = $this->pdo->prepare("
            INSERT INTO remember_tokens (user_id, token, expires_at) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)
        ");
        $stmt->execute([$user_id, hash('sha256', $token), $expires]);
        
        return $token;
    }
    
    private function loginByRememberToken($token) {
        $stmt = $this->pdo->prepare("
            SELECT rt.user_id, u.full_name, u.email, u.role 
            FROM remember_tokens rt
            JOIN users u ON rt.user_id = u.id
            WHERE rt.token = ? AND rt.expires_at > NOW() AND u.status = 'active'
        ");
        $stmt->execute([hash('sha256', $token)]);
        $user = $stmt->fetch();
        
        if ($user) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['login_time'] = time();
            
            $this->updateLastLogin($user['user_id']);
            logActivity($user['user_id'], 'Auto-login via remember token');
            
            return true;
        }
        
        return false;
    }
    
    private function clearRememberToken($token) {
        $stmt = $this->pdo->prepare("DELETE FROM remember_tokens WHERE token = ?");
        $stmt->execute([hash('sha256', $token)]);
    }
}

// Initialize auth system
$auth = new Auth($pdo);

// Helper functions
function requireLogin() {
    global $auth;
    if (!$auth->isLoggedIn()) {
        header('Location: ' . APP_URL . 'login.php');
        exit();
    }
}

function requireRole($role) {
    global $auth;
    requireLogin();
    if (!$auth->hasRole($role)) {
        http_response_code(403);
        die('Access denied. Insufficient permissions.');
    }
}

function getCurrentUser() {
    global $auth;
    if ($auth->isLoggedIn()) {
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'full_name' => $_SESSION['full_name'] ?? '',
            'email' => $_SESSION['email'] ?? '',
            'role' => $_SESSION['role'] ?? 'worker'
        ];
    }
    return null;
}

function logoutUser() {
    global $auth;
    $auth->logout();
}

// Add login_attempts table if it doesn't exist
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            email VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45),
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS remember_tokens (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
} catch (PDOException $e) {
    // Tables might already exist, ignore error
}
?>