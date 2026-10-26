<?php
/**
 * TMS — dashboard.php
 * Landing page. Shows live KPIs, the current rate snapshot, and the
 * build roadmap so progress is always visible.
 */
require_once __DIR__ . '/includes/auth.php';
require_login();
if (has_role('driver', 'subcontractor')) { redirect(role_home()); }

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Operations overview · ' . date('d M Y');

/* ---- Live figures (proves DB + settings are wired) ---- */
$thisMonthStart = date('Y-m-01');

$tripCount   = (int) db_val("SELECT COUNT(*) FROM trips");
$tripsMonth  = (int) db_val("SELECT COUNT(*) FROM trips WHERE collection_date >= ?", array($thisMonthStart));
$revenueMTD  = (float) db_val("SELECT COALESCE(SUM(gross_revenue),0) FROM trips WHERE collection_date >= ?", array($thisMonthStart));
$profitMTD   = (float) db_val("SELECT COALESCE(SUM(net_profit),0) FROM trips WHERE collection_date >= ?", array($thisMonthStart));
$outstanding = (float) db_val("SELECT COALESCE(SUM(outstanding),0) FROM trips");

$destCount   = (int) db_val("SELECT COUNT(*) FROM destinations WHERE active = 1");
$truckCount  = (int) db_val("SELECT COUNT(*) FROM trucks WHERE status = 'active'");
$driverCount = (int) db_val("SELECT COUNT(*) FROM drivers WHERE status = 'active'");
$subCount    = (int) db_val("SELECT COUNT(*) FROM subcontractors WHERE status = 'active'");
$billingCount= (int) db_val("SELECT COUNT(*) FROM clients WHERE client_type = 'billing'");
// Empty rates may not exist until the Step 2.5 migration is run — fail safe.
try { $emptyCount = (int) db_val("SELECT COUNT(*) FROM empty_rates"); } catch (Exception $e) { $emptyCount = 0; }

/* ---- Compliance: licence / insurance / service expiries ---- */
require_once __DIR__ . '/includes/fleet_perf.php';
$alertDays = (int) setting('expiry_alert_days', 30);
$cutoff    = date('Y-m-d', strtotime("+$alertDays days"));
$alerts = array();
$trucksDocs = db_all("SELECT id, registration, licence_expiry, insurance_expiry, service_expiry FROM trucks WHERE status='active'");
foreach ($trucksDocs as $t) {
    $docs = array('Licence'=>'licence_expiry','Insurance'=>'insurance_expiry','Service'=>'service_expiry');
    foreach ($docs as $doc => $col) {
        if ($t[$col] && $t[$col] < $cutoff) {
            $alerts[] = array('kind'=>'truck','id'=>$t['id'],'who'=>$t['registration'],'doc'=>$doc,'exp'=>$t[$col]);
        }
    }
}
$driverDocs = db_all("SELECT id, name, licence_expiry FROM drivers WHERE status='active'");
foreach ($driverDocs as $d) {
    if ($d['licence_expiry'] && $d['licence_expiry'] < $cutoff) {
        $alerts[] = array('kind'=>'driver','id'=>$d['id'],'who'=>$d['name'],'doc'=>'Licence','exp'=>$d['licence_expiry']);
    }
}
usort($alerts, function ($a, $b) { return strcmp($a['exp'], $b['exp']); });

/* ---- Driver-flagged trips awaiting review ---- */
$flaggedTrips = db_all("SELECT t.id, t.trip_ref, t.route, t.flag_note, t.collection_date, d.name AS driver_name
                        FROM trips t LEFT JOIN drivers d ON d.id=t.driver_id
                        WHERE t.flagged=1 ORDER BY t.id DESC LIMIT 20");

$adjClients = db_all("SELECT name, mechanism, adjust_pct FROM clients WHERE client_type='billing' AND active=1 AND mechanism IN ('levy','markup') ORDER BY name");
$billingNames = implode(' · ', array_map(function ($r) { return $r['name']; },
    db_all("SELECT name FROM clients WHERE client_type='billing' AND active=1 ORDER BY name")));
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Welcome, <?= e(explode(' ', $me['name'])[0]) ?></h1>
    <p>Here is the state of <?= e(company_name()) ?> today. Capture loads and quotes to bring these numbers to life.</p>
  </div>
</div>

<!-- KPI cards -->
<div class="kpis">
  <div class="kpi">
    <div class="label">Trips this month</div>
    <div class="value"><?= num($tripsMonth) ?></div>
    <div class="meta"><?= num($tripCount) ?> total on record</div>
  </div>
  <div class="kpi good">
    <div class="label">Revenue (MTD)</div>
    <div class="value" style="font-size:21px"><?= money($revenueMTD) ?></div>
    <div class="meta">Gross, current month</div>
  </div>
  <div class="kpi">
    <div class="label">Net profit (MTD)</div>
    <div class="value" style="font-size:21px"><?= money($profitMTD) ?></div>
    <div class="meta">Revenue less running cost</div>
  </div>
  <div class="kpi bad">
    <div class="label">Outstanding</div>
    <div class="value" style="font-size:21px"><?= money($outstanding) ?></div>
    <div class="meta">Across all unpaid trips</div>
  </div>
</div>

<div class="grid-2">

  <!-- Rate snapshot: proves settings are live -->
  <div class="card">
    <h3>⚙ Current rate snapshot</h3>
    <p class="sub">The single source of truth. Every value becomes editable in the Rates &amp; Costs module.</p>
    <div class="table-wrap" style="border:none">
      <table class="data">
        <tbody>
          <tr><td>Diesel price</td><td class="num"><b><?= money(setting_num('diesel_price')) ?></b> / L</td></tr>
          <?php foreach ($adjClients as $ac): ?>
          <tr><td><?= e($ac['name']) ?> — <?= $ac['mechanism'] === 'levy' ? 'fuel levy' : 'increase' ?></td><td class="num"><b><?= num((float)$ac['adjust_pct'],2) ?>%</b></td></tr>
          <?php endforeach; ?>
          <tr><td>VAT</td><td class="num"><b><?= num(setting_num('vat_pct'),0) ?>%</b></td></tr>
          <tr><td>Commission — 6×4 fullbox</td><td class="num"><b><?= num(setting_num('commission_6x4'),0) ?>%</b></td></tr>
          <tr><td>Commission — 6×2 empty-only</td><td class="num"><b><?= num(setting_num('commission_6x2'),0) ?>%</b></td></tr>
          <tr><td>Running cost</td><td class="num"><b><?= money(setting_num('running_cost')) ?></b> / km</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Data loaded -->
  <div class="card">
    <h3>▦ Data loaded</h3>
    <p class="sub">What is set up in the system right now.</p>
    <div class="table-wrap" style="border:none">
      <table class="data">
        <tbody>
          <tr><td><a href="<?= BASE_URL ?>/destinations.php">Destinations</a></td><td class="num"><span class="badge gold"><?= $destCount ?> routes</span></td></tr>
          <tr><td>Empty rates loaded</td><td class="num"><span class="badge <?= $emptyCount ? 'gold' : 'muted' ?>"><?= $emptyCount ?> rates</span></td></tr>
          <tr><td><a href="<?= BASE_URL ?>/clients.php">Billing clients</a></td><td class="num"><span class="badge gold"><?= e($billingNames ?: 'none yet') ?></span></td></tr>
          <tr><td><a href="<?= BASE_URL ?>/trucks.php">Trucks on fleet</a></td><td class="num"><span class="badge <?= $truckCount?'good':'muted' ?>"><?= $truckCount ?></span></td></tr>
          <tr><td><a href="<?= BASE_URL ?>/drivers.php">Drivers</a></td><td class="num"><span class="badge <?= $driverCount?'good':'muted' ?>"><?= $driverCount ?></span></td></tr>
          <tr><td><a href="<?= BASE_URL ?>/subcontractors.php">Subcontractors</a></td><td class="num"><span class="badge <?= $subCount?'good':'muted' ?>"><?= $subCount ?></span></td></tr>
        </tbody>
      </table>
    </div>
    <p class="hint">Click any truck, driver or subcontractor to open its full profile with performance.</p>
  </div>
</div>

<!-- Driver-flagged trips -->
<?php if ($flaggedTrips): ?>
<div class="card">
  <h3>⚑ Flagged by drivers <span class="badge bad" style="margin-left:6px"><?= count($flaggedTrips) ?></span></h3>
  <p class="sub">Trips a driver marked as needing a second look.</p>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Ref</th><th>Driver</th><th>Date</th><th>Route</th><th>What they said</th><th style="text-align:right">·</th></tr></thead>
      <tbody>
        <?php foreach ($flaggedTrips as $f): ?>
          <tr>
            <td><a href="<?= BASE_URL ?>/trip_view.php?id=<?= (int)$f['id'] ?>"><b><?= e($f['trip_ref']) ?></b></a></td>
            <td><?= e($f['driver_name'] ? $f['driver_name'] : '—') ?></td>
            <td class="muted"><?= fdate($f['collection_date']) ?></td>
            <td><?= e($f['route'] ? $f['route'] : '—') ?></td>
            <td class="muted"><?= e($f['flag_note'] ? $f['flag_note'] : '—') ?></td>
            <td style="text-align:right"><a href="<?= BASE_URL ?>/trip_view.php?id=<?= (int)$f['id'] ?>" class="btn ghost sm">Review</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Compliance alerts -->
<div class="card">
  <h3>⚠ Compliance &amp; expiry alerts
    <?php if ($alerts): ?><span class="badge bad" style="margin-left:6px"><?= count($alerts) ?></span>
    <?php else: ?><span class="badge good" style="margin-left:6px">all clear</span><?php endif; ?>
  </h3>
  <p class="sub">Licence, insurance and service dates due within <?= $alertDays ?> days or already past.</p>
  <?php if (!$alerts): ?>
    <div class="empty"><div class="big">✓</div>Nothing expiring soon. Every active truck and driver is current.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>What</th><th>Who</th><th>Document</th><th>Expires</th><th style="width:110px">Status</th></tr></thead>
        <tbody>
          <?php foreach ($alerts as $a): list($st, $cls) = expiry_status($a['exp'], $alertDays);
            $link = $a['kind']==='truck' ? 'truck_view.php' : 'driver_view.php'; ?>
            <tr>
              <td><span class="badge muted"><?= $a['kind']==='truck' ? '⛟ Truck' : '☺ Driver' ?></span></td>
              <td><a href="<?= BASE_URL ?>/<?= $link ?>?id=<?= (int)$a['id'] ?>"><b><?= e($a['who']) ?></b></a></td>
              <td><?= e($a['doc']) ?></td>
              <td><?= fdate($a['exp']) ?></td>
              <td><span class="badge <?= $cls ?>"><?= e($st) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Build roadmap -->
<div class="card">
  <h3>◷ Build roadmap — <span class="muted" style="font-weight:400;font-size:13px">small steps, continuously</span></h3>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th style="width:46px">Step</th><th>Module</th><th>What it does</th><th style="width:130px">Status</th></tr>
      </thead>
      <tbody>
        <tr><td>1</td><td><b>Foundation</b></td><td>Database, login, roles, layout, seed data</td><td><span class="badge good">✓ Done</span></td></tr>
        <tr><td>2</td><td><b>Rates &amp; Costs</b></td><td>Master-key editor for every rate and cost variable</td><td><span class="badge good">✓ Done</span></td></tr>
        <tr><td>3</td><td><b>Quote &amp; Price</b></td><td>Single quoting with commission and best-client banner</td><td><span class="badge good">✓ Done</span></td></tr>
        <tr><td>4</td><td><b>Loads / Trips</b></td><td>Capture trips, auto revenue/cost/profit/commission</td><td><span class="badge good">✓ Done</span></td></tr>
        <tr><td>5</td><td><b>Fleet &amp; People</b></td><td>Trucks, drivers, subcontractors with profiles</td><td><span class="badge good">✓ Done</span></td></tr>
        <tr><td>6</td><td><b>Invoicing</b></td><td>Per-client invoice + statement PDFs</td><td><span class="badge good">✓ Done</span></td></tr>
        <tr><td>7</td><td><b>Finance &amp; Analytics</b></td><td>Debtors ageing, dashboards, commission breakdown</td><td><span class="badge good">✓ Done</span></td></tr>
        <tr><td>8</td><td><b>Portals</b></td><td>Driver mobile + subcontractor view-only portals</td><td><span class="badge good">✓ Done</span></td></tr>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
