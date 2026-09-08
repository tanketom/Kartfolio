<?php
/**
 * Security headers, sent from PHP so they reach the browser on every host
 * (the .htaccess Header directives only work where Apache honours them).
 * db.php calls kartfolioSendSecurityHeaders() on every request.
 *
 * The Content-Security-Policy allows exactly what the pages load: scripts
 * from this origin plus cdnjs, jsdelivr and d3js.org; Google Fonts; the QR
 * service on the Game Room sign; data:/blob: images for the card downloads.
 * Inline scripts and styles stay allowed — the pages use onclick handlers and
 * inline <script> blocks throughout — so this blocks injected external
 * scripts and off-site connections rather than inline injection.
 */

/** True when this request arrived over TLS, directly or via a proxy that says so. */
function kartfolioRequestIsHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/**
 * Signage pages may be embedded off-site by the kiosk CMS at skrin.bgo.city
 * (the CDN info screen). Every other page stays frame-locked to this origin.
 * Keep this list tight — only pages actually iframed off-site belong here.
 * To let the kiosk show another screen (e.g. auto-vertical.php), add its
 * basename here.
 */
function kartfolioIsSignagePage(): bool {
    $signagePages = ['vertical.php'];
    return in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), $signagePages, true);
}

function kartfolioContentSecurityPolicy(): string {
    return implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://d3js.org",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com data:",
        "img-src 'self' data: blob: https://api.qrserver.com",
        "connect-src 'self'",
        // Signage pages allow the kiosk as an additional frame ancestor; all
        // other pages permit only same-origin framing.
        kartfolioIsSignagePage()
            ? "frame-ancestors 'self' https://skrin.bgo.city"
            : "frame-ancestors 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "object-src 'none'",
    ]);
}

function kartfolioSendSecurityHeaders(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    header('Content-Security-Policy: ' . kartfolioContentSecurityPolicy());
    header('X-Content-Type-Options: nosniff');
    // X-Frame-Options has no cross-origin allow-list in browsers, so it can't
    // express "self + skrin"; omit it on signage pages and let the CSP
    // frame-ancestors directive (above) carry the framing policy there.
    if (!kartfolioIsSignagePage()) header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (kartfolioRequestIsHttps()) header('Strict-Transport-Security: max-age=31536000');
}
