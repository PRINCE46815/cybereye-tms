<?php
/**
 * TMS — my_dashboard.php  (DRIVER PORTAL)
 * The driver's home: their trips, live pay this month, quick actions.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fleet_perf.php';
require_role('driver');

$driverId = $me['driver_id'];
$pageTitle    = 'My Dashboard';
$pageSubtitle = 'Driver';

require_once __DIR__ . '/includes/header.php';

if (!$driverId) {
    echo '<div class="card"><div class="empty"><div class="big">⚠</div>Your login isn\'t linked to a driver record yet. Ask the office to link it.</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    return;
}

$driver = db_row("SELECT * FROM drivers WHERE id=?", array($driverId));
$mtdFrom = date('Y-m-01');
$perf = trip_perf('driver_id', $driverId, $mtdFrom, date('Y-m-d'));
$recent = recent_trips('driver_id', $driverId, 12);
$flagged = (int) db_val("SELECT COUNT(*) FROM trips WHERE driver_id=? AND flagged=1", array($driverId));
?>

<div class="page-head">
  <div><h1>Hi <?= e(explode(' ', $driver['name'])[0]) ?> 👋</h1><p>Here's your month so far.</p></div>
  <div class="actions"><a href="<?= BASE_URL ?>/my_trip_new.php" class="btn primary">＋ Log a trip</a></div>
</div>

<div class="kpis">
  <div class="kpi gold"><div class="label">Pay this month</div><div class="value" style="font-size:21px"><?= money($perf['driver_pay']) ?></div><div class="meta"><?= num($perf['trips']) ?> trips</div></div>
  <div class="kpi"><div class="label">Distance</div><div class="value" style="font-size:20px"><?= num($perf['km']) ?> km</div></div>
  <div class="kpi"><div class="label">PODs collected</div><div class="value"><?= num($perf['pods_collected']) ?>/<?= num($perf['delivered']) ?></div></div>
  <div class="kpi <?= $flagged?'bad':'' ?>"><div class="label">Flagged by you</div><div class="value"><?= $flagged ?></div><div class="meta">awaiting office</div></div>
</div>

<div class="card">
  <div class="flex" style="justify-content:space-between;align-items:center">
    <h3 style="margin:0">My recent trips</h3>
    <span class="flex" style="gap:8px"><a href="<?= BASE_URL ?>/my_trips.php" class="btn ghost sm">All trips →</a><a href="<?= BASE_URL ?>/my_pay.php" class="btn ghost sm">My pay →</a></span>
  </div>
  <?php if (!$recent): ?>
    <div class="empty"><div class="big">▤</div>No trips yet. Tap “Log a trip” after your next delivery.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Ref</th><th>Date</th><th>Route</th><th>Container</th><th class="num">My pay</th><th>POD</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($recent as $t): ?>
          <tr>
            <td><b><?= e($t['trip_ref']) ?></b></td>
            <td class="muted"><?= fdate($t['collection_date']) ?></td>
            <td><?= e($t['route'] ? $t['route'] : '—') ?></td>
            <td><span class="badge muted"><?= e($t['container_size']) ?> <?= e($t['load_state']) ?></span></td>
            <td class="num"><b><?= money(db_val("SELECT driver_pay FROM trips WHERE id=?", array($t['id']))) ?></b></td>
            <td><?= $t['pod_collected']==='collected' ? '<span class="badge good">✓</span>' : '<span class="badge warn">—</span>' ?></td>
            <td style="text-align:right"><a href="<?= BASE_URL ?>/my_trip.php?id=<?= (int)$t['id'] ?>" class="btn ghost sm">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
