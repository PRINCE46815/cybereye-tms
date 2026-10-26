<?php
/**
 * TMS — settings.php
 * Business settings: company identity, banking, and document config.
 * (The rate-engine numbers live under Rates & Costs.)
 */
require_once __DIR__ . '/includes/auth.php';
require_role('master', 'admin');

$pageTitle    = 'Settings';
$pageSubtitle = 'Admin';

/* Field map: key => [label, type, category, section]  (textarea if type 'long') */
$fields = array(
    // Company identity
    'company_name'     => array('Company name', 'text', 'company', 'Company identity'),
    'company_tagline'  => array('Tagline', 'text', 'company', 'Company identity'),
    'company_address'  => array('Registered address', 'text', 'company', 'Company identity'),
    'company_reg'      => array('Registration no.', 'text', 'company', 'Company identity'),
    'company_vat'      => array('VAT no.', 'text', 'company', 'Company identity'),
    'company_email'    => array('Email', 'text', 'company', 'Company identity'),
    'company_phone'    => array('Phone', 'text', 'company', 'Company identity'),
    'company_motto'    => array('Motto (footer of documents)', 'text', 'company', 'Company identity'),
    // Banking
    'company_bank'     => array('Bank', 'text', 'company', 'Banking'),
    'company_account'  => array('Account number', 'text', 'company', 'Banking'),
    'bank_branch_code' => array('Branch code', 'text', 'company', 'Banking'),
    'bank_account_type'=> array('Account type', 'text', 'company', 'Banking'),
    // Documents
    'ref_prefix'       => array('Trip reference prefix', 'text', 'system', 'Documents'),
    'invoice_due_days' => array('Invoice due (days)', 'number', 'invoice', 'Documents'),
    'invoice_terms'    => array('Invoice terms / note', 'long', 'invoice', 'Documents'),
    // Alerts
    'expiry_alert_days'=> array('Compliance alert window (days)', 'number', 'system', 'Alerts'),
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_settings') {
    csrf_check();
    $changed = 0;
    foreach ($fields as $key => $f) {
        if (!isset($_POST[$key])) continue;
        $new = trim($_POST[$key]);
        if (in_array($f[1], array('number'), true)) { $cn = clean_number($new); if ($cn === null) continue; $new = $cn; }

        // ensure the row exists (self-heal if a migration was skipped)
        $exists = (int) db_val("SELECT COUNT(*) FROM settings WHERE setting_key=?", array($key));
        if ($exists === 0) {
            $vtype = $f[1] === 'long' ? 'text' : $f[1];
            db_insert("INSERT INTO settings (setting_key, setting_value, value_type, category, label, master_only) VALUES (?,?,?,?,?,0)",
                array($key, $new, $vtype, $f[2], $f[0]));
            $changed++;
            continue;
        }
        $old = (string) db_val("SELECT setting_value FROM settings WHERE setting_key=?", array($key));
        if (save_setting($key, $new, $old)) { $changed++; }
    }
    audit('update', 'settings', null, $changed . ' field(s)');
    flash('success', $changed ? ($changed . ' setting(s) saved.') : 'No changes.');
    redirect('settings.php');
}

// group fields by section preserving order
$sections = array();
foreach ($fields as $key => $f) { $sections[$f[3]][$key] = $f; }

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div><h1>Settings</h1><p>Company details and document config. These auto-fill your invoices, statements and pay slips.</p></div>
  <div class="actions"><a href="<?= BASE_URL ?>/rates.php" class="btn ghost">Rates &amp; Costs →</a></div>
</div>

<form method="post" action="<?= BASE_URL ?>/settings.php">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_settings">

  <?php foreach ($sections as $section => $flds): ?>
  <div class="card">
    <h3><?= e($section) ?></h3>
    <div class="form-grid">
      <?php foreach ($flds as $key => $f):
        $val = setting($key, '');
        if ($f[1] === 'long'): ?>
          <div class="form-row" style="grid-column:1/-1"><label class="lbl"><?= e($f[0]) ?></label>
            <textarea name="<?= e($key) ?>" rows="2"><?= e($val) ?></textarea></div>
        <?php elseif ($f[1] === 'number'): ?>
          <div class="form-row"><label class="lbl"><?= e($f[0]) ?></label>
            <input type="number" min="0" name="<?= e($key) ?>" value="<?= e($val) ?>"></div>
        <?php else: ?>
          <div class="form-row"><label class="lbl"><?= e($f[0]) ?></label>
            <input type="text" name="<?= e($key) ?>" value="<?= e($val) ?>"></div>
        <?php endif; endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="card"><button class="btn primary block">Save settings</button></div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
