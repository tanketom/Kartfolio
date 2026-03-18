<?php
/**
 * CSRF Token Protection
 * Path: /cdnmk/private/includes/csrf.php
 *
 * Usage:
 *   In forms:  <?= csrf_field() ?>
 *   On POST:   verify_csrf() at top of handler
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate or retrieve the current CSRF token
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output a hidden input field with the CSRF token
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Verify the CSRF token from a POST request.
 * Halts execution with 403 if invalid.
 */
function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Invalid or missing CSRF token.');
    }
}
