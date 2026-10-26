<?php
/**
 * TMS — rates.php
 * The single source of truth. Edit every rate, cost and commission value.
 * Master-key values are locked to the Master role (enforced server-side).
 */
require_once __DIR__ . '/includes/auth.php';
require_role('master', 'admin');

$pageTitle    = 'Rates & Costs';
$pageSubtitle = 'The single source of truth';
$isMaster     = is_master();

/* ---------------- Handle save ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_settings') {
    csrf_check();

    $rows    = db_all("SELECT setting_key, setting_value, value_type, master_only FROM settings");
    $changed = 0;
    $blocked = 0;
    $invalid = 0;
    $posted  = isset($_POST['s']) && is_array($_POST['s']) ? $_POST['s'] : array();

    foreach ($rows as $s) {
        $key = $s['setting_key'];
        if (!array_key_exists($key, $posted)) {
            continue; // field not submitted (e.g. locked/disabled)
        }
        // Server-side master-only gate — cannot be bypassed from the browser.
        if ((int) $s['master_only'] === 1 && !$isMaster) {
            $blocked++;
            continue;
        }

        $new = trim($posted[$key]);

        // Validate numeric types.
        if (in_array($s['value_type'], array('number', 'percent', 'money'), true)) {
            $clean = clean_number($new);
            if ($clean === null) {
                $invalid++;
                continue;
            }
            $new = $clean;
        }

        if (save_setting($key, $new, $s['setting_value'])) {
            $changed++;
        }
    }

    if ($changed > 0) {
        audit('update', 'settings', null, $changed . ' value(s) changed');
        flash('success', $changed . ' setting' . ($changed === 1 ? '' : 's') . ' updated. The system has recalculated.');
    } else {
        flash('info', 'No changes were made.');
    }
    if ($blocked > 0) {
        flash('warn', $blocked . ' master-key value' . ($blocked === 1 ? '' : 's') . ' could not be changed (Master only).');
    }
    if ($invalid > 0) {
        flash('error', $invalid . ' value' . ($invalid === 1 ? '' : 's') . ' skipped — must be a number of 0 or more.');
    }
    redirect('rates.php');
}

/* ---------------- Load settings, grouped ---------------- */
$all = db_all("SELECT * FROM settings ORDER BY setting_key");
$grouped = array();
foreach ($all as $row) {
    $grouped[$row['category']][] = $row;
}

// Ordered tab definitions: key => [Title, blurb, lock?]
$tabs = array(
    'cost'       => array('Cost Model',          'Volvo FH440 running economics. Powers every profit calculation.', true),
    'commission' => array('Commission',          'Charged on the rate (excl VAT), by truck type.',                  true),
    'tax'        => array('Tax',                 'VAT applied on invoices.',                                        true),
    'surcharge'  => array('Surcharges',          'Optional line-item add-ons available when quoting.',              false),
    'company'    => array('Company Identity',    'Auto-fills your invoices and statements.',                        false),
    'crane'      => array('Crane Truck',         'Diesel, crew rate, call-out mark-up and numbering for the Crane Truck tab.', false),
    'system'     => array('System',              'Reference format and alert thresholds.',                          false),
);

/* Render one editable setting field. */
function render_field($s, $isMaster)
{
    $locked   = ((int) $s['master_only'] === 1 && !$isMaster);
    $disabled = $locked ? ' disabled' : '';
    $name     = 's[' . e($s['setting_key']) . ']';
    $val      = e($s['setting_value']);
    $type     = $s['value_type'];

    $wrapClass = '';
    if ($type === 'money')   { $wrapClass = ' class="field-money"'; }
    if ($type === 'percent') { $wrapClass = ' class="field-pct"'; }

    echo '<div class="form-row">';
    echo '<label class="lbl">' . e($s['label']);
    if ((int) $s['master_only'] === 1) {
        echo '<span class="lock-note">🔒 Master' . ($locked ? '' : '') . '</span>';
    }
    echo '</label>';
    echo '<div' . $wrapClass . '>';
    if ($type === 'text') {
        echo '<input type="text" name="' . $name . '" value="' . $val . '"' . $disabled . '>';
    } else {
        echo '<input type="number" step="any" min="0" name="' . $name . '" value="' . $val . '"' . $disabled . '>';
    }
    echo '</div>';
    echo '</div>';
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/rates.php" class="active">Rates &amp; Costs</a>
  <a href="<?= BASE_URL ?>/clients.php">Clients</a>
  <a href="<?= BASE_URL ?>/destinations.php">Destinations</a>
  <a href="<?= BASE_URL ?>/empty_rates.php">Empty Rates</a>
  <a href="<?= BASE_URL ?>/driver_rates.php">Driver Pay</a>
</div>

<div class="page-head">
  <div>
    <h1>Rates &amp; Costs</h1>
    <p>Change a value here and the whole system recalculates — quoting, profit, commission and invoices.
       <?= $isMaster ? '' : 'Values marked 🔒 are Master-only and are locked for your role.' ?></p>
  </div>
</div>

<div class="card">
  <p class="sub" style="margin:0">Looking for a client's fuel levy / markup %, genset, standing rate or surcharges? They are set per company in
    <a href="<?= BASE_URL ?>/clients.php"><b>Clients</b></a>. Route rates per client are in
    <a href="<?= BASE_URL ?>/destinations.php"><b>Destinations</b></a>, and what drivers earn is in
    <a href="<?= BASE_URL ?>/driver_rates.php"><b>Driver Pay</b></a>.</p>
</div>

<form method="post" action="<?= BASE_URL ?>/rates.php">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_settings">

  <!-- Tab buttons -->
  <div class="tabs">
    <?php $first = true; foreach ($tabs as $cat => $meta): if (empty($grouped[$cat])) continue; ?>
      <button type="button" data-tab="tab-<?= e($cat) ?>" class="<?= $first ? 'active' : '' ?>">
        <?= e($meta[0]) ?><?= $meta[2] ? ' 🔒' : '' ?>
      </button>
    <?php $first = false; endforeach; ?>
  </div>

  <!-- Tab panes -->
  <?php $first = true; foreach ($tabs as $cat => $meta): if (empty($grouped[$cat])) continue; ?>
    <div class="tab-pane <?= $first ? 'active' : '' ?>" id="tab-<?= e($cat) ?>">
      <div class="card">
        <h3><?= e($meta[0]) ?><?= $meta[2] ? ' <span class="badge muted" style="font-weight:600">🔒 Master only</span>' : '' ?></h3>
        <p class="sub"><?= e($meta[1]) ?></p>
        <div class="form-grid">
          <?php foreach ($grouped[$cat] as $s) { render_field($s, $isMaster); } ?>
        </div>
      </div>
    </div>
  <?php $first = false; endforeach; ?>

  <div class="card" style="position:sticky;bottom:0;margin-bottom:0">
    <div class="form-actions" style="border-top:none;padding-top:0;margin-top:0">
      <button type="submit" class="btn primary">Save changes</button>
      <span class="note">Every change is logged with your name and the time.</span>
    </div>
  </div>
</form>

<?php
/* ---------------- Recent changes ---------------- */
$history = db_all(
    "SELECT h.setting_key, h.old_value, h.new_value, h.changed_at,
            u.name AS who, s.label
     FROM settings_history h
     LEFT JOIN users u    ON u.id = h.changed_by
     LEFT JOIN settings s ON s.setting_key = h.setting_key
     ORDER BY h.changed_at DESC LIMIT 15"
);
?>
<div class="card">
  <h3>◷ Recent changes</h3>
  <?php if (!$history): ?>
    <div class="empty"><div class="big">⚙</div>No changes yet. Edits you make will appear here.</div>
  <?php else: ?>
    <div class="table-wrap" style="border:none">
      <table class="data">
        <thead><tr><th>Setting</th><th>From</th><th>To</th><th>By</th><th>When</th></tr></thead>
        <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td><?= e($h['label'] ? $h['label'] : $h['setting_key']) ?></td>
              <td class="muted"><?= e($h['old_value']) ?></td>
              <td><b><?= e($h['new_value']) ?></b></td>
              <td><?= e($h['who'] ? $h['who'] : '—') ?></td>
              <td class="muted"><?= e(date('d M Y, H:i', strtotime($h['changed_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
