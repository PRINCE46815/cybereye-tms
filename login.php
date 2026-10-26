<?php
/**
 * TMS — login.php
 */
require_once __DIR__ . '/includes/auth.php';

// Brand-new install (no users yet)? Send them to the one-time setup.
try {
    if ((int) db_val("SELECT COUNT(*) FROM users") === 0) { redirect('install.php'); }
} catch (Exception $e) {
    redirect('install.php');
}

// Already signed in? Go to dashboard.
if (is_logged_in()) {
    redirect(role_home());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = post('email');
    $pass  = post('password');

    if ($email === '' || $pass === '') {
        flash('error', 'Enter your email and password.');
    } elseif (login_throttled($email)) {
        flash('error', 'Too many failed attempts. Please wait 15 minutes and try again.');
    } else {
        $user = verify_credentials($email, $pass);
        if (!$user) {
            flash('error', 'Those credentials did not match. Try again.');
        } elseif ((int) $user['totp_enabled'] === 1) {
            // Password OK, but 2FA required — hold a short-lived pending state.
            $_SESSION['2fa_user']  = (int) $user['id'];
            $_SESSION['2fa_at']    = time();
            $_SESSION['2fa_tries'] = 0;
            redirect('twofa.php');
        } else {
            establish_session($user);
            flash('success', 'Welcome back, ' . $user['name'] . '.');
            redirect(role_home());
        }
    }
    redirect('login.php');
}

$HEX = '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">'
     . '<polygon points="50,4 91,27 91,73 50,96 9,73 9,27" fill="none" stroke="' . brand_color() . '" stroke-width="6"/>'
     . '<polygon points="50,20 77,35 77,65 50,80 23,65 23,35" fill="' . brand_color() . '" opacity="0.16"/>'
     . '<rect x="34" y="44" width="24" height="13" rx="1.5" fill="' . brand_color() . '"/>'
     . '<rect x="58" y="48" width="9" height="9" rx="1" fill="' . brand_color() . '"/>'
     . '<circle cx="40" cy="59" r="3.4" fill="#15181D"/><circle cx="61" cy="59" r="3.4" fill="#15181D"/></svg>';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?= e(company_name()) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<?= brand_style() ?>
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="head">
      <?php if (is_file(__DIR__ . '/assets/img/logo.png')): ?>
      <img class="login-logo" src="<?= BASE_URL ?>/assets/img/logo.png" alt="<?= e(company_name()) ?>">
      <b style="display:none"><?= e(brand_word()) ?></b>
      <?php else: ?>
      <div class="hex"><?= $HEX ?></div>
      <b><?= e(brand_word()) ?></b>
      <?php endif; ?>
      <span><?= e(setting('company_tagline', 'Logistics & Transport')) ?></span>
    </div>
    <div class="body">
      <?php $flashes = get_flashes(); foreach ($flashes as $f): ?>
        <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>

      <form method="post" action="<?= BASE_URL ?>/login.php">
        <?= csrf_field() ?>
        <div class="form-row">
          <label class="lbl" for="email">Email</label>
          <input type="email" id="email" name="email" required autofocus
                 placeholder="you@company.com" value="<?= e(post('email')) ?>">
        </div>
        <div class="form-row">
          <label class="lbl" for="password">Password</label>
          <input type="password" id="password" name="password" required placeholder="••••••••">
        </div>
        <button type="submit" class="btn primary block">Sign in</button>
      </form>

    </div>
  </div>
</div>
</body>
</html>
