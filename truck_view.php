<?php
/**
 * TMS — truck_view.php
 * Full truck profile: details, compliance (licence/insurance/service),
 * and performance pulled from its trips.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fleet_perf.php';
require_role('master', 'admin', 'finance', 'viewer');

$id = (int) get('id', 0);
$truck = db_row("SELECT t.*, s.name AS sub_name FROM trucks t LEFT JOIN subcontractors s ON s.id=t.subcontractor_id WHERE t.id=?", array($id));
if (!$truck) { flash('error', 'Truck not found.'); redirect('trucks.php'); }

$pageTitle    = $truck['registration'];
$pageSubtitle = 'Truck profile';

$f      = profile_filters('truck_id', $id);
$perf   = trip_perf_f($f['where'], $f['params']);
$recent = recent_trips_f($f['where_t'], $f['params'], 50);
$clientsOpt = db_all("SELECT id, name FROM clients WHERE client_type='billing' ORDER BY name");
$jobOpts = array('empty','crosshaul','packing','unpacking','combi-dual','standing');
$alertDays = (int) setting('expiry_alert_days', 30);
$commPct = $truck['truck_type'] === '6x2' ? setting_num('commission_6x2') : setting_num('commission_6x4');

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/trucks.php" class="active">Trucks</a>
  <a href="<?= BASE_URL ?>/drivers.php">Drivers</a>
  <a href="<?= BASE_URL ?>/subcontractors.php">Subcontractors</a>
</div>

<div class="page-head">
  <div>
    <h1>⛟ <?= e($truck['registration']) ?>
      <span class="badge muted"><?= e($truck['truck_type']) ?></span>
      <?= $truck['status']==='active' ? '<span class="badge good">Active</span>' : '<span class="badge muted">Inactive</span>' ?></h1>
    <p><?= $truck['owner_type']==='own' ? 'Company-owned' : 'Subcontractor: ' . e($truck['sub_name']) ?>
       · commission <?= num($commPct,0) ?>% · <?= $truck['has_genset'] ? 'genset-capable' : 'no genset' ?></p>
  </div>
  <div class="actions">
    <a href="<?= BASE_URL ?>/trucks.php" class="btn ghost">← All trucks</a>
    <a href="<?= BASE_URL ?>/trucks.php?edit=<?= $id ?>#truck-form" class="btn primary">Edit</a>
  </div>
</div>

<?= profile_filter_bar('truck_view.php', $id, $f, $clientsOpt, $jobOpts) ?>

<div class="kpis">
  <div class="kpi"><div class="label">Trips</div><div class="value"><?= num($perf['trips']) ?></div><div class="meta"><?= num($perf['km']) ?> km</div></div>
  <div class="kpi good"><div class="label">Revenue</div><div class="value" style="font-size:20px"><?= money($perf['revenue']) ?></div></div>
  <div class="kpi"><div class="label">Net profit</div><div class="value" style="font-size:20px"><?= money($perf['profit']) ?></div></div>
  <div class="kpi"><div class="label">Commission</div><div class="value" style="font-size:20px"><?= money($perf['commission']) ?></div><div class="meta"><?= num($perf['genset_jobs']) ?> genset jobs</div></div>
</div>

<div class="grid-2">
  <div>
    <div class="card">
      <h3>Profile</h3>
      <div class="dl">
        <?php
          dl_row('Registration', e($truck['registration']));
          dl_row('Type', e($truck['truck_type']) . ($truck['truck_type']==='6x4' ? ' — full + empty' : ' — empty only'));
          dl_row('Owner', $truck['owner_type']==='own' ? '<span class="badge gold">Own</span>' : e($truck['sub_name']));
          dl_row('Size capability', e($truck['size_capability']));
          dl_row('Genset', $truck['has_genset'] ? '<span class="badge info">Yes</span>' : 'No');
          dl_row('Odometer', $truck['odometer'] ? num($truck['odometer']) . ' km' : '—');
          dl_row('Status', e(ucfirst($truck['status'])));
        ?>
      </div>
    </div>

    <div class="card">
      <h3>📋 Compliance</h3>
      <table class="data"><tbody>
        <?php
        $docs = array('Licence disc'=>'licence_expiry','Insurance'=>'insurance_expiry','Next service'=>'service_expiry');
        foreach ($docs as $label => $col):
          list($st, $cls) = expiry_status($truck[$col], $alertDays);
        ?>
          <tr>
            <td><?= e($label) ?></td>
            <td class="num"><?= fdate($truck[$col]) ?></td>
            <td class="num" style="width:110px"><span class="badge <?= $cls ?>"><?= e($st) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
      <p class="hint">Anything within <?= $alertDays ?> days shows as “Due soon”.</p>
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
