<?php
/**
 * TMS — my_fleet.php  (SUBCONTRACTOR PORTAL)
 * View-only: the subbie's own trucks, drivers, trips, expenses and earnings.
 * Earnings logic matches the office view: gross payout − their expenses = net.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fleet_perf.php';
require_role('subcontractor');

$subId = $me['subcontractor_id'];
$pageTitle    = 'My Fleet';
$pageSubtitle = 'Subcontractor';

require_once __DIR__ . '/includes/header.php';
if (!$subId) { echo '<div class="card"><div class="empty"><div class="big">⚠</div>Your login isn\'t linked to a subcontractor record yet. Please contact the office.</div></div>'; require_once __DIR__ . '/includes/footer.php'; return; }

$sub    = db_row("SELECT * FROM subcontractors WHERE id=?", array($subId));
$period = period_bounds(get('period', 'mtd'));
$perf   = trip_perf('subcontractor_id', $subId, $period['from'], $period['to']);

$trucks  = db_all("SELECT id, registration, truck_type, has_genset, status, licence_expiry, insurance_expiry FROM trucks WHERE subcontractor_id=? ORDER BY registration", array($subId));
$drivers = db_all("SELECT id, name, status FROM drivers WHERE subcontractor_id=? ORDER BY name", array($subId));

// Trips for the period, with the subbie's pay in the same query (no per-row lookups)
$recent = db_all(
    "SELECT t.id, t.trip_ref, t.collection_date, t.route, t.container_number, t.container_size, t.load_state,
            t.status, t.pod_collected, t.subbie_payout, c.name AS client_name
     FROM trips t LEFT JOIN clients c ON c.id = t.billing_client_id
     WHERE t.subcontractor_id = ? ORDER BY COALESCE(t.collection_date, t.created_at) DESC, t.id DESC LIMIT 20",
    array($subId));

// Their reported expenses in the period (same calc the office uses)
try {
    $expenses = (float) db_val(
        "SELECT COALESCE(SUM(amount),0) FROM driver_expenses
         WHERE driver_id IN (SELECT id FROM drivers WHERE subcontractor_id=?) AND spent_on BETWEEN ? AND ?",
        array($subId, $period['from'], $period['to']));
} catch (Exception $e) { $expenses = 0; }

$payout = (float) $perf['payout'];
$net    = $payout - $expenses;
$vatNote = (int) $sub['vat_registered'] === 1;
?>

<div class="page-head">
  <div>
    <h1><?= e($sub['name']) ?>
      <?php if ($vatNote): ?><span class="badge info">VAT</span><?php endif; ?>
    </h1>
    <p><?= count($trucks) ?> truck<?= count($trucks)===1?'':'s' ?> · <?= count($drivers) ?> driver<?= count($drivers)===1?'':'s' ?></p>
  </div>
</div>

<div class="flex" style="justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
  <span class="muted" style="font-size:13px">Earnings &amp; activity — <b><?= e($period['label']) ?></b></span>
  <?= period_selector('my_fleet.php', $period['key']) ?>
</div>

<div class="kpis">
  <div class="kpi"><div class="label">Trips</div><div class="value"><?= num($perf['trips']) ?></div><div class="meta"><?= num($perf['km']) ?> km · <?= num($perf['genset_jobs']) ?> genset</div></div>
  <div class="kpi gold"><div class="label">Gross payout</div><div class="value" style="font-size:20px"><?= money($payout) ?></div><div class="meta">paid by us</div></div>
  <?php if ($sub['expense_capture'] || $expenses > 0): ?>
  <div class="kpi"><div class="label">Your expenses</div><div class="value" style="font-size:20px"><?= money($expenses) ?></div><div class="meta">diesel, tolls, repairs</div></div>
  <div class="kpi good"><div class="label">Net earnings</div><div class="value" style="font-size:20px"><?= money($net) ?></div><div class="meta">payout − expenses</div></div>
  <?php endif; ?>
  <div class="kpi"><div class="label">PODs collected</div><div class="value"><?= num($perf['pods_collected']) ?>/<?= num($perf['delivered']) ?></div><div class="meta">delivered trips</div></div>
</div>

<div class="grid-2">
  <div class="card">
    <h3>My trucks &amp; drivers</h3>
    <div class="two-col-lists">
      <div><div class="mini-h">Trucks</div>
        <?php if (!$trucks): ?><p class="muted" style="font-size:13px">None linked yet.</p>
        <?php else: foreach ($trucks as $t):
          $lic = expiry_status($t['licence_expiry'], 30); $ins = expiry_status($t['insurance_expiry'], 30); ?>
          <div class="mini-row">
            <span><?= e($t['registration']) ?> <span class="badge muted"><?= e($t['truck_type']) ?></span><?= $t['has_genset'] ? ' <span class="badge info">G</span>' : '' ?></span>
            <?php if ($lic[1]==='bad' || $ins[1]==='bad'): ?><span class="badge bad">doc expired</span>
            <?php elseif ($lic[1]==='warn' || $ins[1]==='warn'): ?><span class="badge warn">doc due</span>
            <?php elseif ($t['status']!=='active'): ?><span class="badge muted">off</span><?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
      <div><div class="mini-h">Drivers</div>
        <?php if (!$drivers): ?><p class="muted" style="font-size:13px">None linked yet.</p>
        <?php else: foreach ($drivers as $d): ?>
          <div class="mini-row"><span><?= e($d['name']) ?></span><?= $d['status']!=='active' ? '<span class="badge muted">off</span>' : '' ?></div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <?php if ($sub['phone'] || $sub['email']): ?>
      <p class="muted" style="font-size:12px;margin-top:14px">On file: <?= e($sub['phone'] ?: '—') ?> · <?= e($sub['email'] ?: '—') ?>. Contact the office to update fleet or details.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="flex" style="justify-content:space-between;align-items:center;margin-bottom:8px">
      <h3 style="margin:0">Recent trips</h3>
      <a href="<?= BASE_URL ?>/my_trips.php" class="btn ghost sm">All trips →</a>
    </div>
    <?php if (!$recent): ?>
      <div class="empty"><div class="big">▤</div>No trips recorded for your fleet yet.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Ref</th><th>Date</th><th>Client</th><th>Route</th><th>Container</th><th>Box</th><th>POD</th><th class="num">Your pay</th></tr></thead>
        <tbody>
          <?php foreach ($recent as $t):
            $podOk = ($t['pod_collected'] === 'collected'); ?>
            <tr>
              <td><b><?= e($t['trip_ref']) ?></b></td>
              <td class="muted"><?= $t['collection_date'] ? fdate($t['collection_date']) : '—' ?></td>
              <td><?= e($t['client_name'] ? $t['client_name'] : '—') ?></td>
              <td><?= e($t['route'] ? $t['route'] : '—') ?></td>
              <td><?= e($t['container_number'] ? $t['container_number'] : '—') ?></td>
              <td><span class="badge muted"><?= e(strtoupper($t['container_size'])) ?> <?= e($t['load_state']) ?></span></td>
              <td><?= $podOk ? '<span class="badge good">✓</span>' : '<span class="badge warn">—</span>' ?></td>
              <td class="num"><b><?= money($t['subbie_payout']) ?></b></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="muted" style="font-size:12px;margin-top:10px">Showing your most recent 20 trips. Pay shown is what the company pays your fleet per trip.</p>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
