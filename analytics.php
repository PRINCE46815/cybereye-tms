<?php
/**
 * TMS — analytics.php
 * Filterable performance dashboards: KPIs, monthly trend, profit by
 * client / destination / truck / driver, and the status pipeline.
 * Filters: date range + client + truck + driver + job type.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/charts.php';
require_role('master', 'admin', 'finance', 'viewer');

$pageTitle    = 'Analytics';
$pageSubtitle = 'Insight';

/* ---- Filters ---- */
$from     = get('from', date('Y-m-01', strtotime('-5 months')));
$to       = get('to', date('Y-m-d'));
$clientId = (int) get('client', 0);
$truckId  = (int) get('truck', 0);
$driverId = (int) get('driver', 0);
$jobType  = get('job', '');

/* Build a WHERE fragment for a given alias ('' for bare `FROM trips`, 't' for joins).
   All queries share the same $params order. */
function ana_where($p, $clientId, $truckId, $driverId, $jobType) {
    $x = $p ? $p . '.' : '';
    $w = "{$x}collection_date BETWEEN ? AND ?";
    if ($clientId)      $w .= " AND {$x}billing_client_id = ?";
    if ($truckId)       $w .= " AND {$x}truck_id = ?";
    if ($driverId)      $w .= " AND {$x}driver_id = ?";
    if ($jobType !== '') $w .= " AND {$x}job_type = ?";
    return $w;
}
$params = array($from, $to);
if ($clientId)      $params[] = $clientId;
if ($truckId)       $params[] = $truckId;
if ($driverId)      $params[] = $driverId;
if ($jobType !== '') $params[] = $jobType;
$wBare = ana_where('',  $clientId, $truckId, $driverId, $jobType);
$wT    = ana_where('t', $clientId, $truckId, $driverId, $jobType);
$anyFilter = ($clientId || $truckId || $driverId || $jobType !== '');

/* Quick-link suffix that preserves the active filters */
$qs = '';
if ($clientId)      $qs .= '&client=' . $clientId;
if ($truckId)       $qs .= '&truck=' . $truckId;
if ($driverId)      $qs .= '&driver=' . $driverId;
if ($jobType !== '') $qs .= '&job=' . urlencode($jobType);

/* ---- KPIs ---- */
$k = db_row("SELECT COUNT(*) trips, COALESCE(SUM(gross_revenue),0) rev, COALESCE(SUM(est_cost),0) cost,
                    COALESCE(SUM(net_profit),0) profit, COALESCE(SUM(commission),0) comm,
                    COALESCE(SUM(subbie_payout),0) payout, COALESCE(SUM(km),0) km
             FROM trips WHERE $wBare", $params);
$margin   = $k['rev'] > 0 ? $k['profit'] / $k['rev'] * 100 : 0;
$costPerKm= $k['km'] > 0 ? $k['cost'] / $k['km'] : 0;
$receivables = (float) db_val("SELECT COALESCE(SUM(i.total - COALESCE((SELECT SUM(amount) FROM payments p WHERE p.invoice_id=i.id),0)),0) FROM invoices i WHERE i.status<>'paid'");

/* ---- Trend (monthly, last 12 within range) ---- */
$trendRaw = db_all("SELECT DATE_FORMAT(collection_date,'%Y-%m') ym, SUM(gross_revenue) rev, SUM(net_profit) profit
                    FROM trips WHERE $wBare GROUP BY ym ORDER BY ym ASC", $params);
$trendRaw = array_slice($trendRaw, -12);
$trend = array();
foreach ($trendRaw as $r) { $trend[] = array('label' => date('M y', strtotime($r['ym'] . '-01')), 'revenue' => $r['rev'], 'profit' => $r['profit']); }

/* ---- Profit by client ---- */
$byClient = array();
foreach (db_all("SELECT c.name, SUM(t.net_profit) profit, COUNT(*) trips FROM trips t JOIN clients c ON c.id=t.billing_client_id
                 WHERE $wT GROUP BY t.billing_client_id ORDER BY profit DESC", $params) as $r) {
    $byClient[] = array('label' => $r['name'], 'value' => (float) $r['profit'], 'meta' => $r['trips'] . ' trips', 'tone' => $r['profit'] < 0 ? 'bad' : 'gold');
}

/* ---- Top destinations by profit ---- */
$byDest = array();
foreach (db_all("SELECT COALESCE(d.name, t.route, 'Unspecified') name, SUM(t.net_profit) profit, COUNT(*) trips FROM trips t
                 LEFT JOIN destinations d ON d.id=t.destination_id WHERE $wT
                 GROUP BY name ORDER BY profit DESC LIMIT 8", $params) as $r) {
    $byDest[] = array('label' => $r['name'], 'value' => (float) $r['profit'], 'meta' => $r['trips'] . '×');
}

/* ---- Profit by truck ---- */
$byTruck = array();
foreach (db_all("SELECT tr.registration, SUM(t.net_profit) profit, COUNT(*) trips FROM trips t JOIN trucks tr ON tr.id=t.truck_id
                 WHERE $wT GROUP BY t.truck_id ORDER BY profit DESC LIMIT 8", $params) as $r) {
    $byTruck[] = array('label' => $r['registration'], 'value' => (float) $r['profit'], 'meta' => $r['trips'] . '×');
}

/* ---- Profit by job type ---- */
$byJob = array();
foreach (db_all("SELECT COALESCE(NULLIF(job_type,''),'Unspecified') jt, SUM(net_profit) profit, COUNT(*) trips
                 FROM trips WHERE $wBare GROUP BY jt ORDER BY profit DESC", $params) as $r) {
    $byJob[] = array('label' => ucfirst($r['jt']), 'value' => (float) $r['profit'], 'meta' => $r['trips'] . '×');
}

/* ---- Profit & pay by driver ---- */
$byDriver = db_all("SELECT dr.name, dr.subcontractor_id, SUM(t.net_profit) profit, SUM(t.driver_pay) driver_pay, COUNT(*) trips
                    FROM trips t JOIN drivers dr ON dr.id=t.driver_id WHERE $wT
                    GROUP BY t.driver_id ORDER BY profit DESC LIMIT 8", $params);

/* ---- Status pipeline ---- */
$tones = array('Draft'=>'muted','Scheduled'=>'info','In Transit'=>'gold','Delivered'=>'good','Invoiced'=>'info','Paid'=>'good','On Hold'=>'warn','Cancelled'=>'bad');
$pipeline = array();
foreach (db_all("SELECT status, COUNT(*) c FROM trips WHERE $wBare GROUP BY status ORDER BY c DESC", $params) as $r) {
    $pipeline[] = array('label' => $r['status'], 'count' => (int) $r['c'], 'tone' => isset($tones[$r['status']]) ? $tones[$r['status']] : 'muted');
}

/* ---- Filter options ---- */
$clientsOpt = db_all("SELECT id, name FROM clients WHERE client_type='billing' ORDER BY name");
$trucksOpt  = db_all("SELECT id, registration FROM trucks ORDER BY registration");
$driversOpt = db_all("SELECT id, name FROM drivers ORDER BY name");
$jobOpts    = array('empty','crosshaul','packing','unpacking','combi-dual','standing');

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div><h1>Analytics</h1><p>Everything below reflects the selected filters.</p></div>
</div>

<div class="card">
  <form method="get" action="<?= BASE_URL ?>/analytics.php" class="flex" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-row" style="margin:0"><label class="lbl">From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="form-row" style="margin:0"><label class="lbl">To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="form-row" style="margin:0"><label class="lbl">Client</label>
      <select name="client"><option value="0">All clients</option>
        <?php foreach ($clientsOpt as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $clientId===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-row" style="margin:0"><label class="lbl">Truck</label>
      <select name="truck"><option value="0">All trucks</option>
        <?php foreach ($trucksOpt as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $truckId===(int)$t['id']?'selected':'' ?>><?= e($t['registration']) ?></option><?php endforeach; ?></select></div>
    <div class="form-row" style="margin:0"><label class="lbl">Driver</label>
      <select name="driver"><option value="0">All drivers</option>
        <?php foreach ($driversOpt as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $driverId===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-row" style="margin:0"><label class="lbl">Job type</label>
      <select name="job"><option value="">All jobs</option>
        <?php foreach ($jobOpts as $j): ?><option value="<?= $j ?>" <?= $jobType===$j?'selected':'' ?>><?= ucfirst($j) ?></option><?php endforeach; ?></select></div>
    <button class="btn dark">Apply</button>
    <a href="<?= BASE_URL ?>/analytics.php?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') . $qs ?>" class="btn ghost">This year</a>
    <?php if ($anyFilter || get('from') || get('to')): ?><a href="<?= BASE_URL ?>/analytics.php" class="btn ghost">Reset</a><?php endif; ?>
  </form>
  <?php if ($anyFilter): ?><p class="hint" style="margin-top:10px">Filtered view — charts below show only matching trips.</p><?php endif; ?>
</div>

<div class="kpis">
  <div class="kpi good"><div class="label">Revenue</div><div class="value" style="font-size:19px"><?= money($k['rev']) ?></div></div>
  <div class="kpi"><div class="label">Net profit</div><div class="value" style="font-size:19px"><?= money($k['profit']) ?></div><div class="meta"><?= num($margin,1) ?>% margin</div></div>
  <div class="kpi bad"><div class="label">Operating cost</div><div class="value" style="font-size:19px"><?= money($k['cost']) ?></div><div class="meta"><?= money($costPerKm) ?>/km</div></div>
  <div class="kpi"><div class="label">Commission</div><div class="value" style="font-size:19px"><?= money($k['comm']) ?></div></div>
</div>
<div class="kpis">
  <div class="kpi"><div class="label">Loads</div><div class="value"><?= num($k['trips']) ?></div></div>
  <div class="kpi"><div class="label">Distance</div><div class="value" style="font-size:19px"><?= num($k['km']) ?> km</div></div>
  <div class="kpi"><div class="label">Subbie payouts</div><div class="value" style="font-size:19px"><?= money($k['payout']) ?></div></div>
  <div class="kpi bad"><div class="label">Receivables (now)</div><div class="value" style="font-size:19px"><?= money($receivables) ?></div><div class="meta"><a href="<?= BASE_URL ?>/finance.php">ageing →</a></div></div>
</div>

<div class="card">
  <h3>Revenue &amp; profit trend</h3>
  <?= chart_trend($trend) ?>
</div>

<div class="grid-2">
  <div class="card"><h3>Profit by client</h3><?= chart_hbars($byClient, array('money'=>true)) ?></div>
  <div class="card"><h3>Trip status pipeline</h3><?= chart_pipeline($pipeline) ?></div>
</div>

<div class="grid-2">
  <div class="card"><h3>Most profitable destinations</h3><?= chart_hbars($byDest, array('money'=>true)) ?></div>
  <div class="card"><h3>Profit by truck</h3><?= chart_hbars($byTruck, array('money'=>true)) ?></div>
</div>

<div class="card"><h3>Profit by job type</h3><?= chart_hbars($byJob, array('money'=>true)) ?></div>

<div class="card">
  <h3>Profit &amp; pay by driver</h3>
  <?php if (!$byDriver): ?>
    <div class="empty"><div class="big">◔</div>No driver-assigned trips for these filters.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Driver</th><th>Type</th><th class="num">Trips</th><th class="num">Profit generated</th><th class="num">Driver pay</th></tr></thead>
      <tbody>
        <?php foreach ($byDriver as $d): $own = $d['subcontractor_id'] === null; ?>
          <tr>
            <td><b><?= e($d['name']) ?></b></td>
            <td><?= $own ? '<span class="badge gold">Own</span>' : '<span class="badge muted">Subbie</span>' ?></td>
            <td class="num"><?= num($d['trips']) ?></td>
            <td class="num"><b><?= money($d['profit']) ?></b></td>
            <td class="num"><?= money($d['driver_pay']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
