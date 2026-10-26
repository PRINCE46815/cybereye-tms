<?php
/**
 * TMS — includes/security.php
 * Runs at include time, BEFORE the session starts. Sets HTTPS enforcement,
 * security headers, and hardened session cookie params.
 *
 * Behaviour is environment-aware:
 *   development (local WAMP) -> stays on HTTP, no secure-cookie, errors visible.
 *   production              -> forces HTTPS, secure cookies, HSTS.
 */
require_once __DIR__ . '/../config.php';

if (php_sapi_name() === 'cli') { return; }

/* ---- Force HTTPS in production ---- */
if (IS_PROD) {
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
          || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
          || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    if (!$https) {
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $uri  = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ($host !== '') { header('Location: https://' . $host . $uri, true, 301); exit; }
    }
}

/* ---- Security headers ---- */
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 0'); // modern browsers: rely on CSP, not the legacy auditor
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // NOTE: 'unsafe-inline' is required because the app uses inline <script>/onclick and
    // inline styles. Output is HTML-escaped (primary XSS defence); CSP is defence-in-depth.
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
         . "style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; "
         . "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
    if (IS_PROD) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header_remove('X-Powered-By');
}

/* ---- Hardened session cookie (must be set BEFORE session_start) ---- */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    $secure = IS_PROD ? true : false;   // Secure flag only over HTTPS (prod)
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0, 'path' => '/', 'domain' => '',
            'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
        ));
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
    }
    session_name('TMSSID');
}
