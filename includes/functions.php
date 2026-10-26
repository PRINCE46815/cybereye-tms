<?php
/**
 * TMS — includes/functions.php
 * Shared helpers used across every page.
 */

require_once __DIR__ . '/db.php';

/* -----------------------------------------------------------
 * Output / escaping
 * --------------------------------------------------------- */

/** HTML-escape for safe output. */
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Format a number as Rands, e.g. R 3 450.00 */
function money($amount)
{
    return 'R ' . number_format((float) $amount, 2, '.', ' ');
}

/** Format a number with thousands spaces, no currency. */
function num($value, $decimals = 0)
{
    return number_format((float) $value, $decimals, '.', ' ');
}

/** Friendly date, e.g. 22 Jun 2026. Returns '—' if empty. */
function fdate($date)
{
    if (empty($date) || $date === '0000-00-00') {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('d M Y', $ts) : '—';
}

/* -----------------------------------------------------------
 * Settings (single source of truth)
 * --------------------------------------------------------- */

/** Get one setting value (cached for the request). */
/** The company's display name (Admin → Settings → Company name), with a neutral fallback. */
function company_name()
{
    $n = trim((string) setting('company_name', ''));
    return $n !== '' ? $n : 'Transport Manager';
}

/** Brand colour from Settings (validated #rrggbb), default gold. */
function brand_color()
{
    $c = trim((string) setting('brand_color', ''));
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtoupper($c) : '#C19A3E';
}

/** A slightly darker shade of the brand colour (hover states). */
function brand_color_dark()
{
    $c = brand_color();
    $r = (int) round(hexdec(substr($c, 1, 2)) * 0.88);
    $g = (int) round(hexdec(substr($c, 3, 2)) * 0.88);
    $b = (int) round(hexdec(substr($c, 5, 2)) * 0.88);
    return sprintf('#%02X%02X%02X', $r, $g, $b);
}

/** <style> block that re-colours the whole app from the brand colour. */
function brand_style()
{
    return '<style>:root{--gold:' . brand_color() . ';--gold-2:' . brand_color_dark() . ';}</style>';
}

/** Short brand word for the sidebar / login: first word-ish of the company name, upper-cased. */
function brand_word()
{
    $n = company_name();
    $n = preg_replace('/\s*\((pty|proprietary)\)\s*(ltd|limited)?\.?/i', '', $n);
    return trim($n) !== '' ? trim($n) : 'Transport Manager';
}

function setting($key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        $rows = db_all("SELECT setting_key, setting_value FROM settings");
        foreach ($rows as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

/** Numeric setting as float. */
function setting_num($key, $default = 0)
{
    $v = setting($key, $default);
    return (float) $v;
}

/* -----------------------------------------------------------
 * Flash messages (one-shot notices across redirects)
 * --------------------------------------------------------- */

function flash($type, $message)
{
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = array();
    }
    $_SESSION['flash'][] = array('type' => $type, 'message' => $message);
}

function get_flashes()
{
    $out = isset($_SESSION['flash']) ? $_SESSION['flash'] : array();
    unset($_SESSION['flash']);
    return $out;
}

/* -----------------------------------------------------------
 * CSRF protection
 * --------------------------------------------------------- */

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check()
{
    $sent = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    if (!hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        die('Security check failed (CSRF). Please go back and try again.');
    }
}

/* -----------------------------------------------------------
 * Redirect helper
 * --------------------------------------------------------- */

function redirect($path)
{
    // Allow absolute paths or app-relative.
    if (strpos($path, 'http') === 0) {
        header('Location: ' . $path);
    } else {
        header('Location: ' . BASE_URL . '/' . ltrim($path, '/'));
    }
    exit;
}

/* -----------------------------------------------------------
 * Misc
 * --------------------------------------------------------- */

/** Trim + return POST value or default. */
function post($key, $default = '')
{
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

/** Trim + return GET value or default. */
function get($key, $default = '')
{
    return isset($_GET[$key]) ? trim($_GET[$key]) : $default;
}

/** Write an audit log entry. */
function audit($action, $entity, $entityId = null, $detail = null)
{
    $userId = isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : null;
    db_insert(
        "INSERT INTO audit_log (user_id, action, entity, entity_id, detail)
         VALUES (?, ?, ?, ?, ?)",
        array($userId, $action, $entity, $entityId, $detail)
    );
}

/**
 * Update a setting if the value changed; log the change to history.
 * Returns true if it was actually changed.
 */
function save_setting($key, $newValue, $oldValue)
{
    if ((string) $newValue === (string) $oldValue) {
        return false; // no change
    }
    $userId = isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : null;
    dbq("UPDATE settings SET setting_value = ?, updated_at = NOW(), updated_by = ? WHERE setting_key = ?",
        array($newValue, $userId, $key));
    db_insert("INSERT INTO settings_history (setting_key, old_value, new_value, changed_by)
               VALUES (?, ?, ?, ?)",
        array($key, $oldValue, $newValue, $userId));
    return true;
}

/** Normalise a numeric input to a clean stored string (or null on invalid). */
function clean_number($value)
{
    $value = str_replace(array(' ', ','), array('', ''), trim($value));
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    if ((float) $value < 0) {
        return null;
    }
    return $value;
}
