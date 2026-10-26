<?php
/**
 * TMS — my_trips.php  (DRIVER + SUBCONTRACTOR PORTAL)
 * A full, filterable list of the user's own trips (not the capped recent view).
 *  - Drivers: scoped to their driver_id; each row opens the trip (view / flag).
 *  - Subcontractors: scoped to their subcontractor_id; the key fields per trip.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fleet_perf.php';
require_role('driver', 'subcontractor');

$role = current_role();
if ($role === 'driver') {
    $col = 'driver_id';
    $id  = $me['driver_id'];
    $payCol = 'driver_pay';
    $canDrill = true;
    $showPay = $id ? ((int) db_val("SELECT pay_visibility FROM drivers WHERE id=?", array($id)) === 1) : false;
    $subtitle = 'Driver';
} else {
    $col = 'subcontractor_id';
    $id  = $me['subcontractor_id'];
    $payCol = 'subbie_payout';
    $canDrill = false;
    $showPay = true;
    $subtitle = 'Subcontractor';
}

$pageTitle    = 'My Trips';
$pageSubtitle = $subtitle;

require_once __DIR__ . '/includes/header.php';
if (!$id) { echo '<div class="card"><div class="empty"><div class="big">⚠</div>Your login isn\'t linked yet. Please contact the office.</div></div>'; require_once __DIR__ . '/includes/footer.php'; return; }

/* Date range: a custom from/to overrides the period preset (default: all time) */
if (get('from') !== null || get('to') !== null) {
    $from = get('from', '2000-01-01'); $to = get('to', date('Y-m-d'));
    $label = fdate($from) . ' – ' . fdate($to); $pk = 'custom';
} else {
    $pb = period_bounds(get('period', 'all'));
    $from = $pb['from']; $to = $pb['to']; $label = $pb['label']; $pk = $pb['key'];
}

$trips = db_all(
    "SELECT t.id, t.trip_ref, t.collection_date, t.route, t.container_number, t.container_size, t.load_state,
            t.job_type, t.status, t.pod_collected, t.$payCol AS pay, c.name AS client_name, sl.name AS line_name
     FROM trips t
     LEFT JOIN clients c  ON c.id = t.billing_client_id
     LEFT JOIN clients sl ON sl.id = t.shipping_line_id
     WHERE t.$col = ? AND t.collection_date BETWEEN ? AND ?
     ORDER BY t.collection_date DESC, t.id DESC
     LIMIT 2000",
    array($id, $from, $to));

$tCount = count($trips);
$tPay = 0; $tPods = 0; $tKm = 0;
foreach ($trips as $t) { $tPay += (float) $t['pay']; if ($t['pod_collected']==='collected') $tPods++; }

$keep = '';
if ($pk === 'custom') { $keep = '&from=' . urlencode($from) . '&to=' . urlencode($to); }
?>

<div class="page-head">
  <div><h1>My Trips</h1><p>Your full trip history — <b><?= e($label) ?></b> · <?= num($tCount) ?> trip<?= $tCount===1?'':'s' ?></p></div>
  <div class="actions">
    <a href="<?= BASE_URL ?>/<?= $role==='driver'?'my_dashboard.php':'my_fleet.php' ?>" class="btn ghost">← Back</a>
    <?php if ($role==='driver'): ?><a href="<?= BASE_URL ?>/my_trip_new.php" class="btn primary">＋ Log a trip</a><?php endif; ?>
  </div>
</div>

<div class="card">
  <form method="get" action="<?= BASE_URL ?>/my_trips.php" class="flex" style="gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0">
    <div class="form-row" style="margin:0"><label class="lbl">From</label><input type="date" name="from" value="<?= e($from==='2000-01-01'?'':$from) ?>"></div>
    <div class="form-row" style="margin:0"><label class="lbl">To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <button class="btn dark">Apply</button>
    <span class="period-pick" style="margin-left:auto">
      <?php foreach (array('mtd'=>'This month','last'=>'Last month','ytd'=>'This year','all'=>'All time') as $kk => $lbl): ?>
        <a class="<?= $pk===$kk?'on':'' ?>" href="<?= BASE_URL ?>/my_trips.php?period=<?= $kk ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </span>
  </form>
</div>

<div class="card">
  <?php if (!$trips): ?>
    <div class="empty"><div class="big">▤</div>No trips in this period.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Ref</th><th>Date</th><th>Client</th><th>Line</th><th>Route</th><th>Container</th><th>Box</th><th>Job</th><th>POD</th><th>Status</th>
        <?php if ($showPay): ?><th class="num">Your pay</th><?php endif; ?>
      </tr></thead>
      <tbody>
        <?php foreach ($trips as $t): ?>
          <tr>
            <td><?php if ($canDrill): ?><a href="<?= BASE_URL ?>/my_trip.php?id=<?= (int)$t['id'] ?>"><b><?= e($t['trip_ref']) ?></b></a><?php else: ?><b><?= e($t['trip_ref']) ?></b><?php endif; ?></td>
            <td class="muted"><?= $t['collection_date'] ? fdate($t['collection_date']) : '—' ?></td>
            <td><?= e($t['client_name'] ? $t['client_name'] : '—') ?></td>
            <td><?= e($t['line_name'] ? $t['line_name'] : '—') ?></td>
            <td><?= e($t['route'] ? $t['route'] : '—') ?></td>
            <td><?= e($t['container_number'] ? $t['container_number'] : '—') ?></td>
            <td><span class="badge muted"><?= e(strtoupper($t['container_size'])) ?> <?= e($t['load_state']) ?></span></td>
            <td><?= e($t['job_type'] ? ucfirst($t['job_type']) : '—') ?></td>
            <td><?= $t['pod_collected']==='collected' ? '<span class="badge good">✓</span>' : '<span class="badge warn">—</span>' ?></td>
            <td><?= trip_status_badge($t['status']) ?></td>
            <?php if ($showPay): ?><td class="num"><b><?= money($t['pay']) ?></b></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if ($showPay): ?>
      <tfoot><tr>
        <td colspan="8" class="num" style="font-weight:700">Totals · <?= num($tCount) ?> trip<?= $tCount===1?'':'s' ?> · PODs <?= num($tPods) ?>/<?= num($tCount) ?></td>
        <td colspan="2"></td>
        <td class="num"><b><?= money($tPay) ?></b></td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <?php if ($tCount >= 2000): ?><p class="hint">Showing the most recent 2000 trips — narrow the date range to see older ones.</p><?php endif; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
