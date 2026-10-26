<?php
/**
 * TMS — account.php
 * Every user manages their own security here: enable/disable two-factor
 * authentication, view and regenerate recovery codes, change password.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/totp.php';
require_login();

$pageTitle    = 'My Account';
$pageSubtitle = 'Security';

$u = db_row("SELECT totp_enabled, totp_secret, totp_recovery FROM users WHERE id=?", array($me['id']));
$enabled = $u && (int) $u['totp_enabled'] === 1;
$newCodes = null;   // plaintext codes to show once after (re)generation

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    // ---- Change password ----
    if ($action === 'change_password') {
        $cur = post('current_password'); $new = post('new_password');
        $row = db_row("SELECT password_hash FROM users WHERE id=?", array($me['id']));
        if (!password_verify($cur, $row['password_hash'])) { flash('error', 'Your current password is incorrect.'); redirect('account.php'); }
        if (!password_strong($new, $why)) { flash('error', 'New password must be ' . $why . '.'); redirect('account.php'); }
        dbq("UPDATE users SET password_hash=? WHERE id=?", array(password_hash($new, PASSWORD_BCRYPT), $me['id']));
        audit('password_change', 'user', $me['id'], $me['email']);
        flash('success', 'Password changed.');
        redirect('account.php');
    }

    // ---- Confirm + enable 2FA ----
    if ($action === 'enable_2fa') {
        $secret = isset($_SESSION['totp_setup_secret']) ? $_SESSION['totp_setup_secret'] : '';
        $codes  = isset($_SESSION['totp_setup_codes']) ? $_SESSION['totp_setup_codes'] : array();
        if ($secret === '') { flash('error', 'Setup expired. Start again.'); redirect('account.php'); }
        if (!totp_verify($secret, post('code'))) { flash('error', 'That code was not valid. Scan the QR and try the current code.'); redirect('account.php?setup=1'); }
        dbq("UPDATE users SET totp_secret=?, totp_enabled=1, totp_recovery=? WHERE id=?",
            array($secret, totp_recovery_hash($codes), $me['id']));
        unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_codes']);
        audit('2fa_enabled', 'user', $me['id'], $me['email']);
        flash('success', 'Two-factor authentication is now ON. Keep your recovery codes safe.');
        redirect('account.php');
    }

    // ---- Disable 2FA (requires a current code) ----
    if ($action === 'disable_2fa') {
        if (!$enabled) { redirect('account.php'); }
        if (!totp_verify($u['totp_secret'], post('code'))) { flash('error', 'Enter a valid code to turn 2FA off.'); redirect('account.php'); }
        dbq("UPDATE users SET totp_secret=NULL, totp_enabled=0, totp_recovery=NULL WHERE id=?", array($me['id']));
        audit('2fa_disabled', 'user', $me['id'], $me['email']);
        flash('info', 'Two-factor authentication has been turned off.');
        redirect('account.php');
    }

    // ---- Regenerate recovery codes (requires a current code) ----
    if ($action === 'regen_recovery') {
        if (!$enabled) { redirect('account.php'); }
        if (!totp_verify($u['totp_secret'], post('code'))) { flash('error', 'Enter a valid code to regenerate recovery codes.'); redirect('account.php'); }
        $codes = totp_recovery_generate(8);
        dbq("UPDATE users SET totp_recovery=? WHERE id=?", array(totp_recovery_hash($codes), $me['id']));
        $_SESSION['totp_just_generated'] = $codes;
        audit('2fa_recovery_regen', 'user', $me['id'], $me['email']);
        flash('success', 'New recovery codes generated. Save them now — the old ones no longer work.');
        redirect('account.php');
    }
}

// Start a setup session (generate a fresh secret + codes to display)
$setup = false;
if (!$enabled && get('setup') === '1') {
    $setup = true;
    if (empty($_SESSION['totp_setup_secret'])) {
        $_SESSION['totp_setup_secret'] = totp_secret();
        $_SESSION['totp_setup_codes']  = totp_recovery_generate(8);
    }
    $setupSecret = $_SESSION['totp_setup_secret'];
    $setupCodes  = $_SESSION['totp_setup_codes'];
    $otpUri = totp_uri($setupSecret, $me['email'], company_name());
}

// Show freshly regenerated codes once
if (!empty($_SESSION['totp_just_generated'])) {
    $newCodes = $_SESSION['totp_just_generated'];
    unset($_SESSION['totp_just_generated']);
}

$recoveryLeft = ($enabled && $u['totp_recovery']) ? count((array) json_decode($u['totp_recovery'], true)) : 0;

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div><h1>My Account</h1><p>Secure your login. We strongly recommend turning on two-factor authentication.</p></div>
</div>

<?php if ($newCodes): ?>
<div class="card" style="border:2px solid var(--gold)">
  <h3>⚑ Your new recovery codes</h3>
  <p class="sub">Save these somewhere safe. Each one works once if you lose your phone. They won't be shown again.</p>
  <div class="recovery-grid">
    <?php foreach ($newCodes as $c): ?><code class="rcode"><?= e($c) ?></code><?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="grid-2">
  <!-- 2FA -->
  <div>
    <div class="card">
      <h3>Two-factor authentication
        <?= $enabled ? '<span class="badge good">ON</span>' : '<span class="badge muted">OFF</span>' ?></h3>

      <?php if ($enabled): ?>
        <p class="sub">Your account asks for a code from your authenticator app at sign-in. You have <b><?= $recoveryLeft ?></b> recovery code<?= $recoveryLeft===1?'':'s' ?> left.</p>

        <form method="post" action="<?= BASE_URL ?>/account.php" style="margin-top:12px">
          <?= csrf_field() ?><input type="hidden" name="action" value="regen_recovery">
          <div class="form-row"><label class="lbl">Regenerate recovery codes — enter a current code</label>
            <input type="text" name="code" inputmode="numeric" placeholder="123456" required></div>
          <button class="btn ghost">Regenerate recovery codes</button>
        </form>

        <hr style="border:none;border-top:1px solid var(--line);margin:18px 0">

        <form method="post" action="<?= BASE_URL ?>/account.php" onsubmit="return confirm('Turn 2FA off? Your account will be less secure.');">
          <?= csrf_field() ?><input type="hidden" name="action" value="disable_2fa">
          <div class="form-row"><label class="lbl">Turn off 2FA — enter a current code</label>
            <input type="text" name="code" inputmode="numeric" placeholder="123456" required></div>
          <button class="btn danger-ghost">Turn off 2FA</button>
        </form>

      <?php elseif ($setup): ?>
        <p class="sub">1. Scan this with Google Authenticator, Authy, or Microsoft Authenticator. 2. Save your recovery codes. 3. Enter the current 6-digit code to switch it on.</p>

        <div class="twofa-setup">
          <div class="qr-box"><div id="qr">Loading…</div></div>
          <div class="qr-side">
            <div class="mini-h">Can't scan? Enter this key manually</div>
            <code class="secret-key"><?= e(chunk_split($setupSecret, 4, ' ')) ?></code>
            <div class="mini-h" style="margin-top:14px">Recovery codes — save these now</div>
            <div class="recovery-grid">
              <?php foreach ($setupCodes as $c): ?><code class="rcode"><?= e($c) ?></code><?php endforeach; ?>
            </div>
          </div>
        </div>

        <form method="post" action="<?= BASE_URL ?>/account.php" style="margin-top:16px">
          <?= csrf_field() ?><input type="hidden" name="action" value="enable_2fa">
          <div class="form-row"><label class="lbl">Enter the 6-digit code to confirm</label>
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required
                   style="font-size:20px;letter-spacing:3px;text-align:center;max-width:200px"></div>
          <button class="btn primary">Turn on 2FA</button>
          <a href="<?= BASE_URL ?>/account.php" class="btn ghost">Cancel</a>
        </form>

      <?php else: ?>
        <p class="sub">Add a second layer to your login: a 6-digit code from an app on your phone. Even if someone gets your password, they can't get in without your phone.</p>
        <a href="<?= BASE_URL ?>/account.php?setup=1" class="btn primary" style="margin-top:12px">Enable 2FA</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Password -->
  <div>
    <div class="card">
      <h3>Password</h3>
      <p class="sub">At least 10 characters, with letters and numbers.</p>
      <form method="post" action="<?= BASE_URL ?>/account.php">
        <?= csrf_field() ?><input type="hidden" name="action" value="change_password">
        <div class="form-row"><label class="lbl">Current password</label>
          <input type="password" name="current_password" required></div>
        <div class="form-row"><label class="lbl">New password</label>
          <input type="password" name="new_password" required></div>
        <button class="btn primary">Change password</button>
      </form>
    </div>

    <div class="card">
      <h3>Signed in as</h3>
      <div class="dl">
        <div class="dl-row"><span class="dl-l">Name</span><span class="dl-v"><?= e($me['name']) ?></span></div>
        <div class="dl-row"><span class="dl-l">Email</span><span class="dl-v"><?= e($me['email']) ?></span></div>
        <div class="dl-row"><span class="dl-l">Role</span><span class="dl-v"><?= e($role) ?></span></div>
      </div>
    </div>
  </div>
</div>

<?php if ($setup): ?>
<script src="<?= BASE_URL ?>/assets/js/qrcode.js"></script>
<script>
(function(){
  try {
    var qr = qrcode(0, 'M');
    qr.addData(<?= json_encode($otpUri) ?>);
    qr.make();
    document.getElementById('qr').innerHTML = qr.createSvgTag(5, 8);
  } catch (e) {
    document.getElementById('qr').innerHTML = '<span style="color:#6b7280;font-size:13px">Use the manual key on the right →</span>';
  }
})();
</script>
<?php endif; ?>

<style>
.twofa-setup{display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start;margin-top:14px}
.qr-box{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px;min-width:180px}
.qr-box svg{display:block;width:180px;height:180px}
.qr-side{flex:1;min-width:220px}
.secret-key{display:inline-block;background:var(--card-2);border:1px solid var(--line);border-radius:8px;padding:8px 12px;font-size:16px;letter-spacing:2px}
.recovery-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:8px;max-width:360px}
.rcode{background:var(--card-2);border:1px solid var(--line);border-radius:7px;padding:7px 10px;text-align:center;font-size:15px;letter-spacing:1px}
.btn.danger-ghost{background:#fff;border:1px solid #E5B4B4;color:#B42318}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
