<?php
/**
 * TMS — subcontractor_view.php
 * Full subcontractor profile: details, their trucks & drivers, and
 * performance — earnings (payouts − reported expenses) + stats.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fleet_perf.php';
require_role('master', 'admin', 'finance', 'viewer');

$id  = (int) get('id', 0);
$sub = db_row("SELECT * FROM subcontractors WHERE id=?", array($id));
if (!$sub) { flash('error', 'Subcontractor not found.'); redirect('subcontractors.php'); }

$pageTitle    = $sub['name'];
$pageSubtitle = 'Subcontractor profile';

$f      = profile_filters('subcontractor_id', $id);
$perf   = trip_perf_f($f['where'], $f['params']);
$recent = recent_trips_f($f['where_t'], $f['params'], 50);
$clientsOpt = db_all("SELECT id, name FROM clients WHERE client_type='billing' ORDER BY name");
$jobOpts = array('empty','crosshaul','packing','unpacking','combi-dual','standing');

$trucks  = db_all("SELECT id, registration, truck_type, has_genset, status FROM trucks WHERE subcontractor_id=? ORDER BY registration", array($id));
$drivers = db_all("SELECT id, name, status FROM drivers WHERE subcontractor_id=? ORDER BY name", array($id));

// Reported expenses for this subbie's drivers in the period (feeds earnings).
try {
    $expenses = (float) db_val(
        "SELECT COALESCE(SUM(amount),0) FROM driver_expenses
         WHERE driver_id IN (SELECT id FROM drivers WHERE subcontractor_id=?) AND spent_on BETWEEN ? AND ?",
        array($id, $f['from'], $f['to']));
} catch (Exception $e) { $expenses = 0; }

$earnings = $perf['payout'] - $expenses;        // what they net from the company
$vatNote  = (int) $sub['vat_registered'] === 1;

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/trucks.php">Trucks</a>
  <a href="<?= BASE_URL ?>/drivers.php">Drivers</a>
  <a href="<?= BASE_URL ?>/subcontractors.php" class="active">Subcontractors</a>
</div>

<div class="page-head">
  <div>
    <h1>⚇ <?= e($sub['name']) ?>
      <?php if ($vatNote): ?><span class="badge info">VAT</span><?php endif; ?>
      <?= $sub['status']==='active' ? '<span class="badge good">Active</span>' : '<span class="badge muted">Inactive</span>' ?></h1>
    <p><?= count($trucks) ?> truck<?= count($trucks)===1?'':'s' ?> · <?= count($drivers) ?> driver<?= count($drivers)===1?'':'s' ?>
       <?= $sub['dedicated'] ? '· <span class="badge gold">dedicated</span>' : '' ?></p>
  </div>
  <div class="actions">
    <a href="<?= BASE_URL ?>/subcontractors.php" class="btn ghost">← All subcontractors</a>
    <a href="<?= BASE_URL ?>/subcontractors.php?edit=<?= $id ?>#sub-form" class="btn primary">Edit</a>
  </div>
</div>

<?= profile_filter_bar('subcontractor_view.php', $id, $f, $clientsOpt, $jobOpts) ?>

<div class="kpis">
  <div class="kpi"><div class="label">Trips</div><div class="value"><?= num($perf['trips']) ?></div><div class="meta"><?= num($perf['km']) ?> km</div></div>
  <div class="kpi good"><div class="label">Revenue (billed)</div><div class="value" style="font-size:20px"><?= money($perf['revenue']) ?></div></div>
  <div class="kpi gold"><div class="label">Their earnings</div><div class="value" style="font-size:20px"><?= money($earnings) ?></div><div class="meta">payouts <?= money($perf['payout']) ?> − exp <?= money($expenses) ?></div></div>
  <div class="kpi"><div class="label">Commission charged</div><div class="value" style="font-size:20px"><?= money($perf['commission']) ?></div><div class="meta">Company keeps</div></div>
</div>

<div class="grid-2">
  <div>
    <div class="card">
      <h3>Profile</h3>
      <div class="dl">
        <?php
          dl_row('Name', e($sub['name']));
          dl_row('Contact person', e($sub['contact_person'] ? $sub['contact_person'] : '—'));
          dl_row('Phone', e($sub['phone'] ? $sub['phone'] : '—'));
          dl_row('Email', e($sub['email'] ? $sub['email'] : '—'));
          dl_row('VAT registered', $vatNote ? '<span class="badge info">Yes — VAT added to payout</span>' : 'No');
          dl_row('Dedicated to us', $sub['dedicated'] ? 'Yes' : 'No');
          dl_row('Drivers see their pay', $sub['pay_visibility'] ? 'Yes' : 'No');
          dl_row('Captures expenses', $sub['expense_capture'] ? 'Yes' : 'No');
          dl_row('Status', e(ucfirst($sub['status'])));
        ?>
      </div>
    </div>

    <div class="card">
      <h3>Fleet under <?= e($sub['name']) ?></h3>
      <div class="two-col-lists">
        <div>
          <div class="mini-h">Trucks</div>
          <?php if (!$trucks): ?><p class="muted" style="font-size:13px">None linked.</p>
          <?php else: foreach ($trucks as $t): ?>
            <div class="mini-row"><a href="<?= BASE_URL ?>/truck_view.php?id=<?= (int)$t['id'] ?>"><?= e($t['registration']) ?></a>
              <span class="badge muted"><?= e($t['truck_type']) ?></span><?= $t['has_genset'] ? ' <span class="badge info">G</span>' : '' ?></div>
          <?php endforeach; endif; ?>
        </div>
        <div>
          <div class="mini-h">Drivers</div>
          <?php if (!$drivers): ?><p class="muted" style="font-size:13px">None linked.</p>
          <?php else: foreach ($drivers as $d): ?>
            <div class="mini-row"><a href="<?= BASE_URL ?>/driver_view.php?id=<?= (int)$d['id'] ?>"><?= e($d['name']) ?></a>
              <?= $d['status']!=='active' ? '<span class="badge muted">off</span>' : '' ?></div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <h3>Recent trips</h3>
      <?php render_recent_trips($recent); ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
