<?php
// includes/config.php
// Core configuration and DB connection
require_once __DIR__ . '/rate_limiter.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/remember_me.php';

define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASS', '');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

/**
 * Fetch a setting value from the database
 * 
 * @param PDO $pdo
 * @param string $key
 * @param string $default
 * @return string
 */
function get_setting(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetchColumn();
    return $result !== false ? $result : $default;
}

// Load plugins system AFTER $pdo connection is initialized
require_once __DIR__ . '/../plugins/plugins.php';

// Initialize session and perform silent auto-login via remember-me cookie if needed
init_secure_session();
check_remember_me_login($pdo);
