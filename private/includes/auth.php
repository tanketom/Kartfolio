<?php
/**
 * Simple Admin Authentication
 * Path: /cdnmk/private/includes/auth.php
 */

session_start();

require_once __DIR__ . '/csrf.php';

// Load the admin password from your config
$config = require __DIR__ . '/../config/config.php';
$admin_password = $config['admin_password'];

/**
 * Call this function at the top of any page 
 * that requires Admin access (e.g., racers.php or edit_recap.php)
 */
function require_admin() {
    if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
        // Not logged in? Send them to the login page
        header('Location: /login.php');
        exit;
    }
}

/**
 * Simple login check
 */
if (isset($_POST['login_password'])) {
    verify_csrf();
    $inputPassword = $_POST['login_password'];

    // Support both hashed and legacy plaintext passwords
    $isHashed = (strpos($admin_password, '$2y$') === 0 || strpos($admin_password, '$argon2') === 0);

    if ($isHashed) {
        $valid = password_verify($inputPassword, $admin_password);
    } else {
        // Legacy plaintext fallback — migrate by replacing admin_password in config.php
        // Generate a hash: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
        $valid = ($inputPassword === $admin_password);
    }

    if ($valid) {
        $_SESSION['is_admin'] = true;
        header('Location: /admin/racers.php');
        exit;
    } else {
        $login_error = "Invalid Password.";
    }
}
?>