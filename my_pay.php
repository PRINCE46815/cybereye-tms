<?php
/**
 * TMS — my_pay.php  (DRIVER PORTAL)
 * The driver's pay for a period, with a downloadable pay statement.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fleet_perf.php';
require_role('driver');

$driverId = $me['driver_id'];
$pageTitle    = 'My Pay';
$pageSubtitle = 'Driver';

require_once __DIR__ . '/includes/header.php';
if (!$driverId) { echo '<div class="card"><div class="empty"><div class="big">⚠</div>Your login isn\'t linked to a driver record yet.</div></div>'; require_once __DIR__ . '/includes/footer.php'; return; }

$period = period_bounds(get('period', 'mtd'));
$trips = db_all(
    "SELECT t.id, t.trip_ref, t.collection_date, t.route, t.container_size, t.load_state, t.driver_pay, c.name AS client_name
     FROM trips t LEFT JOIN clients c ON c.id=t.billing_client_id
     WHERE t.driver_id=? AND t.collection_date BETWEEN ? AND ?
     ORDER BY t.collection_date ASC, t.id ASC", array($driverId, $period['from'], $period['to']));
$total = 0; foreach ($trips as $t) { $total += (float) $t['driver_pay']; }
?>

<div class="page-head">
  <div><h1>My Pay</h1><p>What you've earned in the period.</p></div>
  <div class="actions">
    <a href="<?= BASE_URL ?>/driver_statement_doc.php?driver_id=<?= $driverId ?>&from=<?= urlencode($period['from']) ?>&to=<?= urlencode($period['to']) ?>" class="btn primary" target="_blank">Pay statement</a>
  </div>
</div>

<div class="flex" style="justify-content:space-between;align-items:center;margin-bottom:14px">
  <span class="muted" style="font-size:13px"><b><?= e($period['label']) ?></b></span>
  <?= period_selector('my_pay.php', $period['key']) ?>
</div>

<div class="kpis">
  <div class="kpi gold"><div class="label">Total pay</div><div class="value" style="font-size:22px"><?= money($total) ?></div><div class="meta"><?= count($trips) ?> trips</div></div>
  <div class="kpi"><div class="label">Avg per trip</div><div class="value" style="font-size:20px"><?= money(count($trips) ? $total/count($trips) : 0) ?></div></div>
</div>

<div class="card">
  <h3>Trips &amp; pay</h3>
  <?php if (!$trips): ?>
    <div class="empty"><div class="big">▤</div>No trips in this period.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Ref</th><th>Date</th><th>Client</th><th>Route</th><th>Box</th><th class="num">Pay</th></tr></thead>
      <tbody>
        <?php foreach ($trips as $t): ?>
          <tr>
            <td><b><?= e($t['trip_ref']) ?></b></td>
            <td class="muted"><?= fdate($t['collection_date']) ?></td>
            <td><?= e($t['client_name'] ? $t['client_name'] : '—') ?></td>
            <td><?= e($t['route'] ? $t['route'] : '—') ?></td>
            <td><span class="badge muted"><?= e($t['container_size']) ?> <?= e($t['load_state']) ?></span></td>
            <td class="num"><b><?= money($t['driver_pay']) ?></b></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr style="border-top:2px solid var(--line)"><td colspan="5"><b>Total</b></td><td class="num"><b><?= money($total) ?></b></td></tr></tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
