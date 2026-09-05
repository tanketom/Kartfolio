<?php
require_once __DIR__ . '/session.php';
/**
 * Simple Admin Authentication
 * Path: /cdnmk/private/includes/auth.php
 */

kartfolioSessionStart();

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php'; // $pdo — needed for the login throttle

// Load the admin password from your config
$config = kartfolioConfig();
$admin_password = (string)($config['admin_password'] ?? '');

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

/** True if the current session is a logged-in admin. */
function is_admin(): bool {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

/** True if "tournament mode" is switched on in admin settings. */
function tournamentsEnabled(PDO $pdo): bool {
    require_once __DIR__ . '/settings.php';   // gate may run before header.php loads it
    // 'enable_tournaments' is a boolean-typed setting → getSetting returns a real bool.
    return (bool) getSetting($pdo, 'enable_tournaments', true);
}

/**
 * Gate for the tournament pages. Admins always pass. When the admin has
 * enabled tournament mode, ANY player (no login) may create and run
 * tournaments — these write only to the isolated tournament_* tables, never
 * to season results/Elo. Destructive management (delete) stays admin-only.
 * If tournaments are off and you're not an admin, you go to the login page.
 */
function require_tournament_host(PDO $pdo) {
    if (is_admin()) return;
    if (tournamentsEnabled($pdo)) return;
    header('Location: /login.php');
    exit;
}

/**
 * Simple login check — throttled to 8 failures per IP per 15 minutes.
 */
if (isset($_POST['login_password'])) {
    verify_csrf();

    $loginIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Opportunistic prune so the throttle table never grows unbounded.
    $pdo->prepare("DELETE FROM auth_throttle WHERE attempted_at < datetime('now', '-1 day')")->execute();

    $cntStmt = $pdo->prepare("
        SELECT COUNT(*) FROM auth_throttle
        WHERE ip = ? AND action = 'login' AND attempted_at > datetime('now', '-15 minutes')
    ");
    $cntStmt->execute([$loginIp]);

    if ($admin_password === '') {
        $login_error = "Admin login is disabled: copy private/config/config.example.php to config.php and set a password.";
    } elseif ((int)$cntStmt->fetchColumn() >= 8) {
        $login_error = "Too many failed attempts. Try again in 15 minutes.";
    } else {
        $inputPassword = $_POST['login_password'];

        // Support both hashed and legacy plaintext passwords
        $isHashed = (strpos($admin_password, '$2y$') === 0 || strpos($admin_password, '$argon2') === 0);

        if ($isHashed) {
            $valid = password_verify($inputPassword, $admin_password);
        } else {
            // Legacy plaintext fallback — migrate by replacing admin_password in config.php
            // Generate a hash: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
            $valid = hash_equals($admin_password, $inputPassword);
        }

        if ($valid) {
            // New session ID on privilege change — blocks session fixation.
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            header('Location: /admin/racers.php');
            exit;
        } else {
            $pdo->prepare("INSERT INTO auth_throttle (ip, action) VALUES (?, 'login')")->execute([$loginIp]);
            $login_error = "Invalid Password.";
        }
    }
}
?>