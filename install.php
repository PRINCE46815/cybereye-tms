<?php
/**
 * TMS — install.php
 * One-time setup. After you create an EMPTY database and fill in config.local.php:
 *   1. open /install.php
 *   2. enter the company name and the owner (master) login
 *   3. it builds all tables, loads the starter data and creates the master account
 *
 * It refuses to run again once a user or any trip exists, so it cannot wipe a live system.
 * DELETE this file from the server when you are done.
 */
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';   // session + password_strong()

/* ---------- Is this database already in use? ---------- */
function install_state()
{
    try {
        $tables = array();
        foreach (db_all("SHOW TABLES") as $r) { $tables[] = strtolower(reset($r)); }
        if (!in_array('users', $tables, true)) { return 'empty'; }
        $users = (int) db_val("SELECT COUNT(*) FROM users");
        $trips = in_array('trips', $tables, true) ? (int) db_val("SELECT COUNT(*) FROM trips") : 0;
        return ($users > 0 || $trips > 0) ? 'installed' : 'partial';
    } catch (Exception $e) {
        return 'empty';
    }
}

/** Split an SQL dump into statements (handles quotes and -- comments). */
function install_split_sql($sql)
{
    $out = array(); $buf = ''; $inStr = false; $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($inStr) {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $len) { $buf .= $sql[++$i]; continue; }
            if ($c === "'") {
                if ($i + 1 < $len && $sql[$i + 1] === "'") { $buf .= $sql[++$i]; continue; }
                $inStr = false;
            }
            continue;
        }
        if ($c === "'") { $inStr = true; $buf .= $c; continue; }
        if ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {          // line comment
            while ($i < $len && $sql[$i] !== "\n") { $i++; }
            continue;
        }
        if ($c === ';') { if (trim($buf) !== '') { $out[] = trim($buf); } $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') { $out[] = trim($buf); }
    return $out;
}

$state  = install_state();
$errors = array();
$done   = false;
$f = array('company' => '', 'name' => '', 'email' => '');

if ($state !== 'installed' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f['company'] = trim(post('company'));
    $f['name']    = trim(post('name'));
    $f['email']   = trim(post('email'));
    $pw  = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password2'] ?? '');

    if ($f['company'] === '') $errors[] = 'Enter the company name.';
    if ($f['name'] === '')    $errors[] = 'Enter the owner\'s name.';
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if ($pw !== $pw2) $errors[] = 'The two passwords do not match.';
    $why = '';
    if (!password_strong($pw, $why)) $errors[] = 'The password must be ' . $why . '.';

    if (!$errors) {
        try {
            $sqlFile = __DIR__ . '/database.sql';
            if (!is_file($sqlFile)) { throw new Exception('database.sql is missing from the app folder.'); }
            db()->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (install_split_sql(file_get_contents($sqlFile)) as $stmt) { db()->exec($stmt); }
            db()->exec('SET FOREIGN_KEY_CHECKS = 1');

            dbq("UPDATE settings SET setting_value=? WHERE setting_key='company_name'", array($f['company']));
            dbq("INSERT INTO users (name, email, password_hash, role, status) VALUES (?,?,?,?, 'active')",
                array($f['name'], $f['email'], password_hash($pw, PASSWORD_BCRYPT, array('cost' => 12)), 'master'));
            $done = true;
        } catch (Exception $e) {
            $errors[] = 'Setup failed: ' . $e->getMessage();
            if (IS_PROD) { error_log('install.php: ' . $e->getMessage()); }
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up · Transport Manager</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card" style="max-width:520px">
    <div class="head"><b>Transport Manager</b><span>First-time setup</span></div>
    <div class="body">
    <?php if ($done): ?>
      <div class="flash success">All set. Your system is ready.</div>
      <p>Sign in with <b><?= e($f['email']) ?></b> and the password you just chose, then:</p>
      <ol style="line-height:1.7">
        <li><b>Delete <code>install.php</code></b> from the server now.</li>
        <li>Open <b>Admin → Settings</b> and complete the company details (address, VAT no, bank) used on invoices.</li>
        <li>Open <b>Admin → Clients</b> and <b>Destinations</b> to check the starter clients and rates, and edit them to your own.</li>
        <li>Open <b>Admin → Driver Pay</b> to check what drivers earn per route.</li>
        <li>Add trucks, drivers and subcontractors, then turn on 2FA in <b>My Account</b>.</li>
      </ol>
      <a class="btn primary" href="<?= BASE_URL ?>/login.php">Go to sign in →</a>
    <?php elseif ($state === 'installed'): ?>
      <div class="flash error">This system is already set up.</div>
      <p>For safety the installer will not run again. If you can see this page on a live server, <b>delete <code>install.php</code></b>.</p>
      <a class="btn primary" href="<?= BASE_URL ?>/login.php">Go to sign in</a>
    <?php else: ?>
      <?php foreach ($errors as $er): ?><div class="flash error"><?= e($er) ?></div><?php endforeach; ?>
      <p class="muted" style="margin-top:0">Takes one minute. Creates the database tables, loads starter clients and rates (all editable later) and your master login.</p>
      <form method="post" action="<?= BASE_URL ?>/install.php">
        <?= csrf_field() ?>
        <div class="form-row"><label class="lbl">Company name</label>
          <input type="text" name="company" required value="<?= e($f['company']) ?>" placeholder="e.g. Acme Transport (Pty) Ltd"></div>
        <div class="form-row"><label class="lbl">Owner's name</label>
          <input type="text" name="name" required value="<?= e($f['name']) ?>"></div>
        <div class="form-row"><label class="lbl">Owner's email (the login)</label>
          <input type="email" name="email" required value="<?= e($f['email']) ?>"></div>
        <div class="form-row"><label class="lbl">Password <span class="muted">(10+ characters, letters and numbers)</span></label>
          <input type="password" name="password" required autocomplete="new-password"></div>
        <div class="form-row"><label class="lbl">Repeat password</label>
          <input type="password" name="password2" required autocomplete="new-password"></div>
        <button type="submit" class="btn primary" style="width:100%">Install</button>
      </form>
    <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
