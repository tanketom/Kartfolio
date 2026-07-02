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
 *
 * NOTE: returns silently on non-POST requests — an endpoint that mutates
 * state must ALSO reject non-POST methods, or this check never runs.
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

/**
 * Auto-submitting POST bridge — use INSTEAD of header("Location: ...") when
 * the destination is a POST+CSRF endpoint (a redirect can only carry GET).
 * Emits a minimal page with a pre-filled, token-carrying form that submits
 * itself immediately; the visible button is the no-JS fallback. Never returns.
 */
function csrf_bridge_post(string $action, array $fields = [], string $label = 'Continue'): void {
    $inputs = '';
    foreach ($fields as $k => $v) {
        $inputs .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars((string)$v) . '">';
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Working…</title></head>'
       . '<body style="font-family:sans-serif;text-align:center;padding:48px;">'
       . '<p>Working…</p>'
       . '<form method="POST" action="' . htmlspecialchars($action) . '">'
       . csrf_field() . $inputs
       . '<button type="submit">' . htmlspecialchars($label) . '</button></form>'
       . '<script>document.forms[0].submit();</script>'
       . '</body></html>';
    exit;
}
