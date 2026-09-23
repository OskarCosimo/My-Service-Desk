<?php
// logout.php
// Session destruction, Remember Me token revocation, and logout
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/remember_me.php';

// Revoke persistent token from database and clear cookie safely
if (isset($pdo) && function_exists('clear_remember_me_token')) {
    clear_remember_me_token($pdo);
}

$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();
header("Location: /login.php");
exit;
