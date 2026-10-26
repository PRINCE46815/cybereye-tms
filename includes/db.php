<?php
/**
 * TMS — includes/db.php
 * Single PDO connection, reused everywhere via db().
 */

require_once __DIR__ . '/../config.php';

function db()
{
    static $pdo = null;
    if ($pdo === null) {
        // Socket wins when set (MAMP-friendly); otherwise host + port.
        if (defined('DB_SOCKET') && DB_SOCKET !== '') {
            $dsn = 'mysql:unix_socket=' . DB_SOCKET . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        } else {
            $port = defined('DB_PORT') && DB_PORT !== '' ? DB_PORT : '3306';
            $dsn  = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        }
        $options = array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Friendly message instead of a raw stack trace.
            http_response_code(500);
            echo '<div style="font-family:system-ui;max-width:560px;margin:60px auto;padding:24px;'
               . 'border:1px solid #E4E2DC;border-radius:12px;background:#fff">'
               . '<h2 style="color:#B42318;margin:0 0 8px">Database connection failed</h2>'
               . '<p style="color:#1F232A">The app could not reach MySQL. Check that:</p>'
               . '<ul style="color:#1F232A">'
               . '<li>Your server is running (MAMP / XAMPP / WAMP started, MySQL green).</li>'
               . '<li>The database exists (empty is fine) — then open <b>/install.php</b> to build the tables.</li>'
               . '<li>Credentials in <b>config.local.php</b> match your MySQL '
               . '(local MAMP: port <b>8889</b>, user/password <b>root</b>; hosting: the user created in cPanel).</li>'
               . '</ul>'
               . '<p style="color:#6B7280;font-size:13px">Technical detail: '
               . htmlspecialchars($e->getMessage()) . '</p></div>';
            exit;
        }
    }
    return $pdo;
}

/** Run a query with bound params, return the statement. */
function dbq($sql, $params = array())
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Fetch a single row (or null). */
function db_row($sql, $params = array())
{
    $row = dbq($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows as an array. */
function db_all($sql, $params = array())
{
    return dbq($sql, $params)->fetchAll();
}

/** Fetch a single scalar value (first column of first row). */
function db_val($sql, $params = array())
{
    $val = dbq($sql, $params)->fetchColumn();
    return $val === false ? null : $val;
}

/** Insert helper — returns last insert id. */
function db_insert($sql, $params = array())
{
    dbq($sql, $params);
    return (int) db()->lastInsertId();
}
