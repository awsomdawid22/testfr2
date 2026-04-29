<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

// ============================================
// Authentication & Session Functions
// ============================================

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_samesite', 'Lax');  // Lax required for OAuth redirects (Strict blocks them)
        session_name('lae_session');
        session_start();
    }
}

function getCurrentUser(): ?array {
    startSecureSession();

    // Remember-me: auto-login if no session but cookie exists
    if (!isset($_SESSION['user_id']) && !empty($_COOKIE['lae_remember'])) {
        $tokenHash = hash('sha256', $_COOKIE['lae_remember']);
        try {
            $db  = getDB();
            $row = $db->prepare("SELECT user_id FROM remember_tokens WHERE token_hash=? AND expires_at > NOW()")
                      ->execute([$tokenHash]) ? null : null;
            $stmt = $db->prepare("SELECT user_id FROM remember_tokens WHERE token_hash=? AND expires_at > NOW()");
            $stmt->execute([$tokenHash]);
            $row = $stmt->fetch();
            if ($row) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $row['user_id'];
            } else {
                // Expired/invalid — clear cookie
                setcookie('lae_remember', '', time() - 3600, '/');
            }
        } catch (\Throwable $e) { /* ignore */ }
    }

    if (!isset($_SESSION['user_id'])) return null;

    $db = getDB();
    // Check if animation column exists to avoid errors
    $hasAnimationColumn = false;
    try {
        $check = $db->query("SHOW COLUMNS FROM roles LIKE 'animation'");
        $hasAnimationColumn = $check->rowCount() > 0;
    } catch (Exception $e) {}
    
    $animationSelect = $hasAnimationColumn ? ", r.animation as role_animation" : ", NULL as role_animation";
    
    $stmt = $db->prepare("
        SELECT u.*, r.name as role_name, r.display_name as role_display,
               r.color as role_color, r.badge_color{$animationSelect},
               r.can_post, r.can_create_threads,
               r.can_moderate, r.can_admin, r.can_ban, r.priority as role_priority
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.id = ? AND u.is_banned = 0
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if ($user) {
        // Update last seen
        $db->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?")->execute([$user['id']]);
    }
    return $user ?: null;
}

function login(string $username, string $password, array $args = []): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT u.*, r.name as role_name, r.can_post, r.can_moderate, r.can_admin
        FROM users u JOIN roles r ON u.role_id = r.id
        WHERE (u.username = ? OR u.email = ?)
    ");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        // Track failed login attempt
        if ($user) {
            try {
                $db->prepare("UPDATE users SET failed_login_attempts = COALESCE(failed_login_attempts, 0) + 1, last_failed_login = NOW() WHERE id = ?")
                   ->execute([$user['id']]);
            } catch (\Exception $e) {}
        }
        return ['success' => false, 'error' => 'Invalid username or password.'];
    }
    
    // Check if account is locked
    if (!empty($user['account_locked'])) {
        $reason = $user['lock_reason'] ?? 'Your account has been locked.';
        return ['success' => false, 'error' => "Account locked: $reason Please contact staff."];
    }
    
    // Reset failed login attempts on successful login
    try {
        $db->prepare("UPDATE users SET failed_login_attempts = 0 WHERE id = ?")->execute([$user['id']]);
    } catch (\Exception $e) {}
    
    if ($user['is_banned']) {
        // Allow banned users to log in so they can submit a ban appeal
        startSecureSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        return ['success' => true, 'banned' => true, 'ban_reason' => $user['ban_reason'] ?? 'No reason given'];
    }

    startSecureSession();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];

    // Remember Me — 30-day persistent cookie
    if (!empty($args['remember'])) {
        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expires   = date('Y-m-d H:i:s', strtotime('+30 days'));
        try {
            $db->prepare("INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?,?,?)")
               ->execute([$user['id'], $tokenHash, $expires]);
            setcookie('lae_remember', $token, strtotime('+30 days'), '/', '', true, true);
        } catch (\Throwable $e) {
            error_log('[Auth] Remember-me failed: ' . $e->getMessage());
        }
    }

    // Log login
    logAudit($user['id'], 'login', 'user', $user['id'], 'User logged in');

    // Send login notification email with location
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // If there are multiple IPs (from proxies), take the first one (original client)
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    // Track IP and session for security
    logIPHistory($user['id'], $ip, 'login', $userAgent);
    trackUserSession($user['id'], $ip, $userAgent);
    
    sendLoginNotificationEmail($user['email'], $user['username'], $ip);

    return ['success' => true];
}

function logout(): void {
    startSecureSession();
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId) logAudit($userId, 'logout', 'user', $userId, 'User logged out');

    // Clear remember-me
    if (!empty($_COOKIE['lae_remember'])) {
        $tokenHash = hash('sha256', $_COOKIE['lae_remember']);
        try {
            getDB()->prepare("DELETE FROM remember_tokens WHERE token_hash=?")->execute([$tokenHash]);
        } catch (\Throwable $e) {}
        setcookie('lae_remember', '', time() - 3600, '/');
    }

    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
}

function register(string $username, string $email, string $password): array {
    $db = getDB();

    // Validation
    if (strlen($username) < 3 || strlen($username) > 30) {
        return ['success' => false, 'error' => 'Username must be 3-30 characters.'];
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return ['success' => false, 'error' => 'Username can only contain letters, numbers, and underscores.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid email address.'];
    }
    if (strlen($password) < 8) {
        return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
    }

    // Check uniqueness
    $check = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $check->execute([$username, $email]);
    if ($check->fetch()) {
        return ['success' => false, 'error' => 'Username or email already taken.'];
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $newMemberRole = $db->query("SELECT id FROM roles WHERE name = 'new_member'")->fetchColumn();
    
    // Get registration IP
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    // Check if registration_ip column exists, insert accordingly
    try {
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role_id, email_verified, registration_ip, last_ip, last_user_agent) VALUES (?,?,?,?,1,?,?,?)");
        $stmt->execute([$username, $email, $hash, $newMemberRole, $ip, $ip, $userAgent]);
    } catch (\PDOException $e) {
        // Fallback if new columns don't exist yet
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role_id, email_verified) VALUES (?,?,?,?,1)");
        $stmt->execute([$username, $email, $hash, $newMemberRole]);
    }
    
    $userId = $db->lastInsertId();
    
    // Log IP history for registration
    logIPHistory($userId, $ip, 'register', $userAgent);
    
    // Create initial session record
    trackUserSession($userId, $ip, $userAgent);

    return ['success' => true, 'user_id' => $userId];
}

/**
 * Log IP history for a user action
 */
function logIPHistory(int $userId, string $ip, string $action, ?string $userAgent = null): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO ip_history (user_id, ip_address, action, user_agent) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $ip, $action, $userAgent]);
    } catch (\Exception $e) {
        // Table may not exist yet, ignore
    }
}

/**
 * Track user session
 */
function trackUserSession(int $userId, string $ip, ?string $userAgent = null): void {
    try {
        $db = getDB();
        $sessionId = session_id();
        
        // Deactivate other sessions for this user if force_logout is set
        $user = $db->prepare("SELECT force_logout FROM users WHERE id = ?");
        $user->execute([$userId]);
        $userData = $user->fetch();
        if ($userData && $userData['force_logout']) {
            $db->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ?")->execute([$userId]);
            $db->prepare("UPDATE users SET force_logout = 0 WHERE id = ?")->execute([$userId]);
        }
        
        // Check if session already exists
        $existing = $db->prepare("SELECT id FROM user_sessions WHERE session_id = ? AND user_id = ?");
        $existing->execute([$sessionId, $userId]);
        
        if ($existing->fetch()) {
            // Update existing session
            $db->prepare("UPDATE user_sessions SET ip_address = ?, user_agent = ?, last_activity = NOW(), is_active = 1 WHERE session_id = ? AND user_id = ?")
               ->execute([$ip, $userAgent, $sessionId, $userId]);
        } else {
            // Create new session record
            $stmt = $db->prepare("INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $sessionId, $ip, $userAgent]);
        }
        
        // Update user's last IP
        $db->prepare("UPDATE users SET last_ip = ?, last_user_agent = ? WHERE id = ?")->execute([$ip, $userAgent, $userId]);
    } catch (\Exception $e) {
        // Table may not exist yet, ignore
    }
}

function isAdmin(?array $user = null): bool {
    $user = $user ?? getCurrentUser();
    return $user && $user['can_admin'];
}

function isModerator(?array $user = null): bool {
    $user = $user ?? getCurrentUser();
    return $user && $user['can_moderate'];
}

function canPost(?array $user = null): bool {
    $user = $user ?? getCurrentUser();
    return $user && $user['can_post'];
}

function generateCSRF(): string {
    startSecureSession();
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function verifyCSRF(string $token): bool {
    startSecureSession();
    return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

function logAudit(?int $userId, string $action, ?string $targetType = null, ?int $targetId = null, ?string $details = null): void {
    try {
        $db = getDB();
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $stmt = $db->prepare("INSERT INTO audit_log (user_id, action, target_type, target_id, details, ip_address) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$userId, $action, $targetType, $targetId, $details, $ip]);
    } catch (Exception $e) {
        // Non-fatal
    }
}

function requireLogin(): void {
    $user = getCurrentUser();
    if (!$user) {
        header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . '/?error=unauthorized');
        exit;
    }
}

function requireModerator(): void {
    requireLogin();
    if (!isModerator()) {
        header('Location: ' . SITE_URL . '/?error=unauthorized');
        exit;
    }
}
