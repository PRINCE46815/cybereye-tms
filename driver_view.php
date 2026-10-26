<?php
/**
 * TMS — driver_view.php
 * Full driver profile: details, licence compliance, live earnings
 * (own drivers accrue fixed pay-per-trip), and performance.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fleet_perf.php';
require_role('master', 'admin', 'finance', 'viewer');

$id = (int) get('id', 0);
$driver = db_row("SELECT d.*, s.name AS sub_name FROM drivers d LEFT JOIN subcontractors s ON s.id=d.subcontractor_id WHERE d.id=?", array($id));
if (!$driver) { flash('error', 'Driver not found.'); redirect('drivers.php'); }

$pageTitle    = $driver['name'];
$pageSubtitle = 'Driver profile';

$f      = profile_filters('driver_id', $id);
$perf   = trip_perf_f($f['where'], $f['params']);
$recent = recent_trips_f($f['where_t'], $f['params'], 50);
$clientsOpt = db_all("SELECT id, name FROM clients WHERE client_type='billing' ORDER BY name");
$jobOpts = array('empty','crosshaul','packing','unpacking','combi-dual','standing');
$alertDays = (int) setting('expiry_alert_days', 30);

$isOwn   = $driver['subcontractor_id'] === null;
$payRate = (float) $driver['pay_per_trip'];
$earnings = (float) $perf['driver_pay'];          // sum of actual per-trip driver pay (rate book)
$avgPerTrip = $perf['trips'] > 0 ? $earnings / $perf['trips'] : 0;
$podRate = $perf['delivered'] > 0 ? ($perf['pods_collected'] / $perf['delivered']) * 100 : 0;
list($licSt, $licCls) = expiry_status($driver['licence_expiry'], $alertDays);

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/trucks.php">Trucks</a>
  <a href="<?= BASE_URL ?>/drivers.php" class="active">Drivers</a>
  <a href="<?= BASE_URL ?>/subcontractors.php">Subcontractors</a>
</div>

<div class="page-head">
  <div>
    <h1>☺ <?= e($driver['name']) ?>
      <?= $driver['status']==='active' ? '<span class="badge good">Active</span>' : '<span class="badge muted">Inactive</span>' ?></h1>
    <p><?= $isOwn ? '<span class="badge gold">Own driver</span>' : 'Subcontractor: ' . e($driver['sub_name']) ?>
       · paid per route</p>
  </div>
  <div class="actions">
    <a href="<?= BASE_URL ?>/drivers.php" class="btn ghost">← All drivers</a>
    <a href="<?= BASE_URL ?>/driver_statement_doc.php?id=<?= $id ?>&driver_id=<?= $id ?>&from=<?= urlencode($f['from']) ?>&to=<?= urlencode($f['to']) ?>" class="btn ghost" target="_blank">Pay statement</a>
    <a href="<?= BASE_URL ?>/drivers.php?edit=<?= $id ?>#driver-form" class="btn primary">Edit</a>
  </div>
</div>

<?= profile_filter_bar('driver_view.php', $id, $f, $clientsOpt, $jobOpts) ?>

<div class="kpis">
  <div class="kpi"><div class="label">Trips</div><div class="value"><?= num($perf['trips']) ?></div><div class="meta"><?= num($perf['km']) ?> km</div></div>
  <div class="kpi gold"><div class="label"><?= $isOwn ? 'Salary (accruing)' : 'Driver pay' ?></div>
    <div class="value" style="font-size:20px"><?= money($earnings) ?></div>
    <div class="meta"><?= num($perf['trips']) ?> trips · avg <?= money($avgPerTrip) ?></div></div>
  <div class="kpi good"><div class="label">Revenue generated</div><div class="value" style="font-size:20px"><?= money($perf['revenue']) ?></div></div>
  <div class="kpi"><div class="label">POD collection</div><div class="value"><?= num($podRate,0) ?>%</div><div class="meta"><?= num($perf['pods_collected']) ?>/<?= num($perf['delivered']) ?> delivered</div></div>
</div>

<div class="grid-2">
  <div>
    <div class="card">
      <h3>Profile</h3>
      <div class="dl">
        <?php
          dl_row('Name', e($driver['name']));
          dl_row('Belongs to', $isOwn ? '<span class="badge gold">Own</span>' : e($driver['sub_name']));
          dl_row('Phone', e($driver['phone'] ? $driver['phone'] : '—'));
          dl_row('Licence no.', e($driver['licence_no'] ? $driver['licence_no'] : '—'));
          dl_row('Licence expiry', fdate($driver['licence_expiry']) . ' <span class="badge ' . $licCls . '">' . e($licSt) . '</span>');
          dl_row('Fallback pay/trip', money($payRate) . ' <span class="muted" style="font-weight:400">(used if no route rate)</span>');
          dl_row('Captures expenses', $driver['expense_capture'] ? '<span class="badge info">Yes</span>' : 'No');
          dl_row('Banking', (($driver['bank_name'] ?? '') || ($driver['account_no'] ?? '')) ? e(trim(($driver['bank_name'] ?? '') . ' ' . ($driver['account_no'] ?? ''))) : '<span class="muted">not set</span>');
          dl_row('Can see own pay', $driver['pay_visibility'] ? 'Yes' : 'No');
          dl_row('Status', e(ucfirst($driver['status'])));
        ?>
      </div>
      <?php if ($isOwn): ?>
        <p class="hint">Salary accrues from the <a href="<?= BASE_URL ?>/driver_rates.php">driver pay rate book</a> — the actual amount for each trip's route &amp; size, summed over the period.</p>
      <?php else: ?>
        <p class="hint">Pay for subcontractor drivers is settled between the driver and <?= e($driver['sub_name']) ?>.</p>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="card">
      <h3>Recent trips</h3>
      <?php render_recent_trips($recent); ?>
    </div>

    <?php
      $exp = db_all("SELECT spent_on, type, station, litres, amount FROM driver_expenses
                     WHERE driver_id=? AND spent_on BETWEEN ? AND ? ORDER BY spent_on DESC, id DESC",
                    array($id, $f['from'], $f['to']));
      $expTot = 0; foreach ($exp as $x) { $expTot += (float) $x['amount']; }
      $tb = array('diesel'=>'gold','toll'=>'info','repair'=>'warn','other'=>'muted');
    ?>
    <div class="card">
      <div class="flex" style="justify-content:space-between;align-items:center">
        <h3 style="margin:0">Expenses <span class="muted" style="font-weight:400;font-size:13px">(<?= e($f['label']) ?>)</span></h3>
        <b><?= money($expTot) ?></b>
      </div>
      <?php if (!$exp): ?>
        <p class="muted" style="font-size:13px;margin:10px 0 0">No expenses logged in this period.</p>
      <?php else: ?>
      <div class="table-wrap" style="margin-top:10px">
        <table class="data">
          <thead><tr><th>Date</th><th>Type</th><th>Station</th><th class="num">Amount</th></tr></thead>
          <tbody>
            <?php foreach ($exp as $x): ?>
              <tr>
                <td class="muted"><?= fdate($x['spent_on']) ?></td>
                <td><span class="badge <?= $tb[$x['type']] ?>"><?= e(ucfirst($x['type'])) ?></span></td>
                <td><?= e($x['station'] ? $x['station'] : '—') ?></td>
                <td class="num"><b><?= money($x['amount']) ?></b></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
