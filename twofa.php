<?php
/**
 * TMS — twofa.php
 * Second step of login for users with 2FA enabled. Verifies a TOTP code
 * or a one-time recovery code, then completes the session.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/totp.php';

if (is_logged_in()) { redirect(role_home()); }

// Must have a valid pending 2FA state (max 5 minutes old).
$pendingId = isset($_SESSION['2fa_user']) ? (int) $_SESSION['2fa_user'] : 0;
$pendingAt = isset($_SESSION['2fa_at']) ? (int) $_SESSION['2fa_at'] : 0;
if ($pendingId === 0 || (time() - $pendingAt) > 300) {
    unset($_SESSION['2fa_user'], $_SESSION['2fa_at'], $_SESSION['2fa_tries']);
    flash('error', 'Your sign-in timed out. Please enter your password again.');
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $_SESSION['2fa_tries'] = (isset($_SESSION['2fa_tries']) ? (int) $_SESSION['2fa_tries'] : 0) + 1;
    if ($_SESSION['2fa_tries'] > 5) {
        unset($_SESSION['2fa_user'], $_SESSION['2fa_at'], $_SESSION['2fa_tries']);
        flash('error', 'Too many incorrect codes. Please sign in again.');
        redirect('login.php');
    }

    $user = db_row("SELECT id, name, email, role, subcontractor_id, driver_id, status, totp_secret, totp_recovery
                    FROM users WHERE id=? LIMIT 1", array($pendingId));
    if (!$user || $user['status'] !== 'active') {
        unset($_SESSION['2fa_user'], $_SESSION['2fa_at'], $_SESSION['2fa_tries']);
        flash('error', 'Account unavailable. Please sign in again.');
        redirect('login.php');
    }

    $code = trim(post('code'));
    // Try TOTP first, then a recovery code.
    if (totp_verify($user['totp_secret'], $code)) {
        establish_session($user);
        flash('success', 'Welcome back, ' . $user['name'] . '.');
        redirect(role_home());
    }
    $newRecovery = totp_recovery_consume($user['totp_recovery'], $code);
    if ($newRecovery !== false) {
        dbq("UPDATE users SET totp_recovery=? WHERE id=?", array($newRecovery, $user['id']));
        $left = count(json_decode($newRecovery, true));
        establish_session($user);
        audit('2fa_recovery_used', 'user', $user['id'], $left . ' codes left');
        flash('info', 'Recovery code used — you have ' . $left . ' left. You can regenerate them in Account.');
        redirect(role_home());
    }
    flash('error', 'That code was not valid. Try again.');
    redirect('twofa.php');
}
$HEX = '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">'
     . '<polygon points="50,4 91,27 91,73 50,96 9,73 9,27" fill="none" stroke="' . brand_color() . '" stroke-width="6"/>'
     . '<polygon points="50,20 77,35 77,65 50,80 23,65 23,35" fill="' . brand_color() . '" opacity="0.16"/>'
     . '<path d="M42 47v-4a8 8 0 0 1 16 0v4" fill="none" stroke="' . brand_color() . '" stroke-width="5"/>'
     . '<rect x="35" y="47" width="30" height="22" rx="3" fill="' . brand_color() . '"/>'
     . '<circle cx="50" cy="56" r="3.4" fill="#15181D"/><rect x="48.4" y="58" width="3.2" height="7" rx="1.4" fill="#15181D"/></svg>';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify · <?= e(company_name()) ?></title>
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
      <span>Two-step verification</span>
    </div>
    <div class="body">
      <?php $flashes = get_flashes(); foreach ($flashes as $f): ?>
        <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>

      <p class="note" style="margin:0 0 14px">Enter the 6-digit code from your authenticator app.</p>

      <form method="post" action="<?= BASE_URL ?>/twofa.php">
        <?= csrf_field() ?>
        <div class="form-row">
          <label class="lbl" for="code">Authentication code</label>
          <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus
                 maxlength="9" placeholder="123456" style="font-size:22px;letter-spacing:4px;text-align:center">
          <p class="note" style="margin-top:8px">Lost your phone? Enter one of your <b>recovery codes</b> instead.</p>
        </div>
        <button type="submit" class="btn primary block">Verify</button>
      </form>

      <div class="motto"><a href="<?= BASE_URL ?>/logout.php" class="muted" style="font-size:13px">Cancel and sign in again</a></div>
    </div>
  </div>
</div>
</body>
</html>

