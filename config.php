<?php
/**
 * TMS — config.php
 * Environment-aware configuration.
 *
 * LOCAL (WAMP): do nothing — it defaults to 'development' and your existing
 * root/no-password setup keeps working with errors shown on screen.
 *
 * PRODUCTION (live server): the easiest reliable way is to create a file named
 * config.local.php next to this one (copy config.local.example.php). On some
 * hosts you can instead set real environment variables. Never commit secrets.
 */

// Load local production overrides if present (sets env vars via putenv()).
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// ---- Environment ----
define('APP_ENV', getenv('TMS_ENV') ?: 'development');
define('IS_PROD', APP_ENV === 'production');

// ---- Database (env overrides; fall back to local defaults) ----
define('DB_HOST', getenv('TMS_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('TMS_DB_NAME') ?: 'tms');
define('DB_USER', getenv('TMS_DB_USER') ?: 'root');
define('DB_PASS', getenv('TMS_DB_PASS') !== false ? getenv('TMS_DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');
// Port: 3306 is standard. MAMP on Mac uses 8889 — set TMS_DB_PORT=8889.
define('DB_PORT', getenv('TMS_DB_PORT') ?: '3306');
// Optional unix socket (MAMP alternative). If set, it is used instead of host/port.
define('DB_SOCKET', getenv('TMS_DB_SOCKET') !== false ? getenv('TMS_DB_SOCKET') : '');

// ---- Application ----
define('APP_NAME', 'Transport Manager');
define('APP_MOTTO', '');
define('BASE_URL', getenv('TMS_BASE_URL') !== false ? getenv('TMS_BASE_URL') : '/tms');
define('APP_ROOT', __DIR__);
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('SESSION_TIMEOUT', 8 * 60 * 60);   // 8h idle timeout

// Secret used for CSRF/session salting. MUST be overridden in production.
define('APP_SECRET', getenv('TMS_SECRET') ?: 'dev-only-insecure-secret-change-me');

date_default_timezone_set('Africa/Johannesburg');

// ---- Error handling by environment ----
if (IS_PROD) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');            // never leak errors/paths to users
    ini_set('log_errors', '1');
    if (!is_dir(APP_ROOT . '/logs')) { @mkdir(APP_ROOT . '/logs', 0750, true); }
    ini_set('error_log', APP_ROOT . '/logs/php-error.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');            // local dev convenience
}
