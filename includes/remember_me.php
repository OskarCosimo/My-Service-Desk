<?php
// includes/remember_me.php
// Secure persistent authentication (Remember Me) token management

/**
 * Configure secure cookie parameters and start session if not yet started
 */
function init_secure_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        
        session_set_cookie_params([
            'lifetime' => 86400 * 180, // 6 months persistence for session cookie
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
}

/**
 * Generate a new persistent remember-me token and store it in database and cookie
 *
 * @param PDO $pdo
 * @param int $userId
 */
function create_remember_me_token(PDO $pdo, int $userId): void {
    $selector  = bin2hex(random_bytes(12));  // 24 characters
    $validator = bin2hex(random_bytes(32));  // 64 characters
    $tokenHash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + (86400 * 180)); // 6 months

    // Remove expired tokens for this user first
    $cleanup = $pdo->prepare("DELETE FROM user_remember_tokens WHERE user_id = ? AND expires_at < NOW()");
    $cleanup->execute([$userId]);

    // Insert new token
    $stmt = $pdo->prepare("INSERT INTO user_remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $selector, $tokenHash, $expiresAt]);

    // Set secure cookie (180 days)
    $cookieValue = $selector . ':' . $validator;
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    setcookie('remember_token', $cookieValue, [
        'expires'  => time() + (86400 * 180),
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

/**
 * Verify remember-me cookie and auto-authenticate user if session is missing
 *
 * @param PDO $pdo
 */
function check_remember_me_login(PDO $pdo): void {
    // If user is already authenticated in session, do nothing
    if (!empty($_SESSION['user_id'])) {
        return;
    }

    if (empty($_COOKIE['remember_token'])) {
        return;
    }

    $parts = explode(':', $_COOKIE['remember_token'], 2);
    if (count($parts) !== 2) {
        clear_remember_me_token($pdo);
        return;
    }

    [$selector, $validator] = $parts;

    $stmt = $pdo->prepare("SELECT * FROM user_remember_tokens WHERE selector = ? AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$selector]);
    $tokenRow = $stmt->fetch();

    if (!$tokenRow) {
        clear_remember_me_token($pdo);
        return;
    }

    // Verify validator hash safely against timing attacks
    if (!hash_equals($tokenRow['token_hash'], hash('sha256', $validator))) {
        clear_remember_me_token($pdo);
        return;
    }

    // Fetch corresponding user
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $userStmt->execute([$tokenRow['user_id']]);
    $user = $userStmt->fetch();

    if (!$user || !empty($user['is_banned']) || (isset($user['is_approved']) && (int)$user['is_approved'] === 0)) {
        clear_remember_me_token($pdo);
        return;
    }

    // Restore full session
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['username']   = $user['username'] ?? $user['email'];

    // Rotate token validator for extra security
    $newValidator = bin2hex(random_bytes(32));
    $newTokenHash = hash('sha256', $newValidator);
    $newExpiresAt = date('Y-m-d H:i:s', time() + (86400 * 180));

    $updateStmt = $pdo->prepare("UPDATE user_remember_tokens SET token_hash = ?, expires_at = ? WHERE id = ?");
    $updateStmt->execute([$newTokenHash, $newExpiresAt, $tokenRow['id']]);

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    setcookie('remember_token', $selector . ':' . $newValidator, [
        'expires'  => time() + (86400 * 180),
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

/**
 * Remove token from DB and revoke remember-me cookie
 *
 * @param PDO $pdo
 */
function clear_remember_me_token(PDO $pdo): void {
    if (!empty($_COOKIE['remember_token'])) {
        $parts = explode(':', $_COOKIE['remember_token'], 2);
        if (count($parts) === 2) {
            $stmt = $pdo->prepare("DELETE FROM user_remember_tokens WHERE selector = ?");
            $stmt->execute([$parts[0]]);
        }
    }

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    setcookie('remember_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}