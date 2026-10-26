<?php
/**
 * TMS — includes/auth.php
 * Session startup, login state, role guards.
 *
 * Include this at the TOP of every protected page:
 *   require_once __DIR__ . '/includes/auth.php';
 *   require_login();
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';   // headers + hardened cookie, BEFORE session_start

/* Start the session once, safely. */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* -----------------------------------------------------------
 * Idle timeout — but NEVER force logout on the login page.
 * --------------------------------------------------------- */
$currentScript = basename($_SERVER['SCRIPT_NAME']);
if (isset($_SESSION['user']) && $currentScript !== 'login.php') {
    $last = isset($_SESSION['last_activity']) ? $_SESSION['last_activity'] : time();
    if (time() - $last > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        // Restart a clean session so flash works.
        session_start();
        flash('info', 'You were signed out after a period of inactivity.');
        redirect('login.php');
    }
    $_SESSION['last_activity'] = time();
}

/* -----------------------------------------------------------
 * Login / logout
 * --------------------------------------------------------- */

/** Attempt login. Returns true on success, false on failure. */
/** Best-effort client IP. */
function client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

/** True if this email/IP has too many recent failed logins (5 in 15 min). */
function login_throttled($email)
{
    try {
        $cutoff = date('Y-m-d H:i:s', time() - 15 * 60);
        $fails = (int) db_val(
            "SELECT COUNT(*) FROM login_attempts WHERE success=0 AND attempted_at > ? AND (email=? OR ip=?)",
            array($cutoff, $email, client_ip()));
        return $fails >= 5;
    } catch (Exception $e) { return false; }
}

/** Record a login attempt (for throttling + audit). */
function record_login_attempt($email, $success)
{
    try {
        db_insert("INSERT INTO login_attempts (email, ip, success) VALUES (?,?,?)",
            array($email, client_ip(), $success ? 1 : 0));
    } catch (Exception $e) { /* table may not exist yet — ignore */ }
}

/** Password policy: at least 10 chars, with letters and numbers. */
function password_strong($pw, &$why = null)
{
    if (strlen($pw) < 10) { $why = 'at least 10 characters long'; return false; }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw)) { $why = 'a mix of letters and numbers'; return false; }
    return true;
}

/** Verify email + password only. Returns the full user row, or false. */
function verify_credentials($email, $password)
{
    $user = db_row(
        "SELECT id, name, email, password_hash, role, subcontractor_id, driver_id, status,
                totp_enabled, totp_secret, totp_recovery
         FROM users WHERE email = ? LIMIT 1",
        array($email)
    );
    if (!$user || $user['status'] !== 'active') { record_login_attempt($email, false); return false; }
    if (!password_verify($password, $user['password_hash'])) { record_login_attempt($email, false); return false; }
    return $user;
}

/** Create the logged-in session for a verified user (prevents fixation). */
function establish_session($user)
{
    record_login_attempt($user['email'], true);
    session_regenerate_id(true);
    $_SESSION['user'] = array(
        'id'               => (int) $user['id'],
        'name'             => $user['name'],
        'email'            => $user['email'],
        'role'             => $user['role'],
        'subcontractor_id' => $user['subcontractor_id'] !== null ? (int) $user['subcontractor_id'] : null,
        'driver_id'        => $user['driver_id'] !== null ? (int) $user['driver_id'] : null,
    );
    $_SESSION['last_activity'] = time();
    unset($_SESSION['2fa_user'], $_SESSION['2fa_at'], $_SESSION['2fa_tries']);
    db()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute(array($user['id']));
    audit('login', 'user', $user['id'], $user['email']);
}

/** Legacy one-shot login (no 2FA gate) — verify then establish. */
function attempt_login($email, $password)
{
    $user = verify_credentials($email, $password);
    if (!$user) return false;
    establish_session($user);
    return true;
}

function logout()
{
    if (isset($_SESSION['user'])) {
        audit('logout', 'user', $_SESSION['user']['id']);
    }
    session_unset();
    session_destroy();
}

/* -----------------------------------------------------------
 * Guards
 * --------------------------------------------------------- */

function is_logged_in()
{
    return isset($_SESSION['user']);
}

function current_user()
{
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function current_role()
{
    return isset($_SESSION['user']['role']) ? $_SESSION['user']['role'] : null;
}

/** Require any logged-in user. */
function require_login()
{
    if (!is_logged_in()) {
        flash('info', 'Please sign in to continue.');
        redirect('login.php');
    }
}

/** True if the current user has one of the given roles. */
function has_role()
{
    $roles = func_get_args();
    return in_array(current_role(), $roles, true);
}

/** Require one of the listed roles, else 403. */
function require_role()
{
    require_login();
    $roles = func_get_args();
    if (!in_array(current_role(), $roles, true)) {
        http_response_code(403);
        die('<div style="font-family:system-ui;max-width:480px;margin:80px auto;text-align:center">'
          . '<h2 style="color:#B42318">Access denied</h2>'
          . '<p>Your role does not have permission for this page.</p>'
          . '<p><a href="' . BASE_URL . '/dashboard.php">Back to dashboard</a></p></div>');
    }
}

/** Convenience: is this the Master (owner)? */
function is_master()
{
    return current_role() === 'master';
}

/** Can this role edit trip data? (Master + Admin only.) */
function can_edit_trips()
{
    return has_role('master', 'admin');
}

/** Landing page for the current user's role. */
function role_home()
{
    $r = current_role();
    if ($r === 'driver')        return 'my_dashboard.php';
    if ($r === 'subcontractor') return 'my_fleet.php';
    return 'dashboard.php';
}

/* -----------------------------------------------------------
 * Make the signed-in user available to every page as $me / $role,
 * immediately after this file is included (before header.php).
 * header.php re-assigns the same values; harmless.
 * --------------------------------------------------------- */
$me   = current_user();
$role = current_role();
