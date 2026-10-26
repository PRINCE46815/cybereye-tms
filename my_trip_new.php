<?php
/**
 * TMS — my_trip_new.php  (DRIVER PORTAL)
 * A driver logs a completed trip. Capture-only: the office sees the money.
 * A POD photo/scan is required.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rate_engine.php';
require_once __DIR__ . '/includes/driver_pay.php';
require_role('driver');

$driverId = $me['driver_id'];
$pageTitle    = 'Log a trip';
$pageSubtitle = 'Driver';

if (!$driverId) { require_once __DIR__ . '/includes/header.php'; echo '<div class="card"><div class="empty"><div class="big">⚠</div>Your login isn\'t linked to a driver record yet.</div></div>'; require_once __DIR__ . '/includes/footer.php'; return; }

$driver = db_row("SELECT * FROM drivers WHERE id=?", array($driverId));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // --- Container number: strip special characters, force uppercase ---
    $containerNo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) post('container_number')));

    // --- Required-field validation (before we save anything) ---
    $vBilling = (int) post('billing_client_id', 0);
    $vCode = $vBilling ? db_val("SELECT code FROM clients WHERE id=?", array($vBilling)) : null;
    $miss = array();
    if (!post('collection_date'))                                    $miss[] = 'Date';
    if (!$vBilling)                                                  $miss[] = 'Client';
    if (!((int) post('shipping_line_id', 0)))                        $miss[] = 'Shipping line';
    if ($containerNo === '')                                         $miss[] = 'Container number';
    if (!in_array(post('container_size'), array('6m','12m'), true))  $miss[] = 'Container size';
    if (!post('job_type'))                                           $miss[] = 'Job type';
    $cfgAll = billing_client_config();
    if ($vCode !== '' && !empty($cfgAll[$vCode]['to_required']) && trim((string) post('to_number')) === '')
                                                                     $miss[] = 'T.O number (required for ' . $cfgAll[$vCode]['name'] . ')';
    if ($miss) { flash('error', 'Please complete: ' . implode(', ', $miss) . '.'); redirect('my_trip_new.php'); }

    // --- Container number must be unique (not already on another trip) ---
    $dup = db_row("SELECT trip_ref FROM trips WHERE container_number = ? LIMIT 1", array($containerNo));
    if ($dup) { flash('error', 'Container ' . $containerNo . ' is already recorded on trip ' . $dup['trip_ref'] . '. Container numbers must be unique.'); redirect('my_trip_new.php'); }

    // --- POD upload (required) ---
    $podPath = null;
    if (!empty($_FILES['pod']['name']) && is_uploaded_file($_FILES['pod']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['pod']['name'], PATHINFO_EXTENSION));
        $allowed = array('jpg','jpeg','png','webp','pdf');
        if (!in_array($ext, $allowed, true)) { flash('error', 'POD must be a photo (jpg/png/webp) or PDF.'); redirect('my_trip_new.php'); }
        if ($_FILES['pod']['size'] > 12 * 1024 * 1024) { flash('error', 'POD file is too large (max 12MB).'); redirect('my_trip_new.php'); }
        // Validate actual file content (not just the extension)
        $okMimes = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf');
        $mime = function_exists('finfo_open') ? (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['pod']['tmp_name']) : null;
        if ($mime !== null && !isset($okMimes[$mime])) { flash('error', 'That file doesn\'t look like a photo or PDF.'); redirect('my_trip_new.php'); }
        $dir = __DIR__ . '/uploads/pod';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $fname = 'POD_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (!move_uploaded_file($_FILES['pod']['tmp_name'], $dir . '/' . $fname)) { flash('error', 'Could not save the POD. Try again.'); redirect('my_trip_new.php'); }
        $podPath = 'uploads/pod/' . $fname;
    } else {
        flash('error', 'A POD photo or scan is required to log a trip.'); redirect('my_trip_new.php');
    }

    $loadState = post('load_state') === 'EMPTY' ? 'EMPTY' : 'FULL';
    $size      = post('container_size') === '6m' ? '6m' : '12m';
    $billingId = (int) post('billing_client_id', 0) ?: null;
    $destId    = (int) post('destination_id', 0) ?: null;
    $truckId   = (int) post('truck_id', 0) ?: null;
    $km        = (int) post('km', 0);

    // resolve route + km from destination if chosen
    $route = trim(post('route'));
    if ($destId) {
        $d = db_row("SELECT name, km FROM destinations WHERE id=?", array($destId));
        if ($d) { if ($route === '') $route = strtoupper($d['name']); if (!$km) $km = (int) $d['km']; }
    }

    // client code + truck type + subbie
    $clientCode = $billingId ? db_val("SELECT code FROM clients WHERE id=?", array($billingId)) : null;
    $truckType  = $truckId ? db_val("SELECT truck_type FROM trucks WHERE id=?", array($truckId)) : '6x4';
    $subId      = $driver['subcontractor_id'] !== null ? (int) $driver['subcontractor_id'] : null;
    $hasGenset  = post('has_genset') === '1';

    // engine: rate (full + known destination), genset pay, driver pay
    $E = rate_engine();
    $rate = 0;
    if ($loadState === 'FULL' && $destId && $clientCode) {
        $br = db_val("SELECT r.base_rate FROM client_rates r JOIN clients c ON c.id = r.client_id
                      WHERE r.destination_id = ? AND c.code = ?", array($destId, $clientCode));
        $r = $E->clientRate(array('rates' => array($clientCode => $br !== null ? (float) $br : null)), $clientCode);
        $rate = $r !== null ? $r : 0;
    }
    $genset = ($hasGenset && $clientCode) ? $E->gensetPay($clientCode) : 0;
    $dpAuto = driver_pay_for($route, $size); $driverPay = $dpAuto !== null ? $dpAuto : 0;

    $fin = $E->tripFinancials(array('rate'=>$rate,'genset_amount'=>$genset,'standing_amount'=>0,'surcharge_total'=>0,
        'km'=>$km,'genset'=>$hasGenset,'is_empty'=>($loadState==='EMPTY'),'truck_type'=>$truckType,
        'is_subbie'=>($subId!==null),'driver_pay'=>$driverPay));

    $newId = db_insert(
        "INSERT INTO trips (trip_ref, collection_date, status, billing_client_id, shipping_line_id, to_number,
            subcontractor_id, truck_id, driver_id, container_number, container_size, load_state, job_type, destination_id, route, km,
            has_genset, rate, genset_amount, standing_amount, surcharge_total, gross_revenue, commission, subbie_payout,
            est_cost, net_profit, margin_pct, driver_pay, outstanding, pod_path, notes, created_by)
         VALUES ('TMP',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        array((post('collection_date') ?: date('Y-m-d')), 'Delivered', $billingId, (int) post('shipping_line_id',0) ?: null, post('to_number'),
              $subId, $truckId, $driverId, $containerNo, $size, $loadState, (post('job_type') ?: null), $destId, $route, $km,
              $hasGenset?1:0, $fin['rate'], $fin['genset_amount'], 0, 0, $fin['gross_revenue'], $fin['commission'], $fin['subbie_payout'],
              $fin['est_cost'], $fin['net_profit'], $fin['margin_pct'], $fin['driver_pay'] ?? $driverPay, $fin['gross_revenue'], $podPath, post('notes'), $me['id']));
    $ref = setting('ref_prefix', 'RT') . str_pad($newId, 5, '0', STR_PAD_LEFT);
    dbq("UPDATE trips SET trip_ref=? WHERE id=?", array($ref, $newId));
    audit('driver_log', 'trip', $newId, $ref);
    flash('success', 'Trip ' . $ref . ' logged. Thanks!');
    redirect('my_trip.php?id=' . $newId);
}

$clients = db_all("SELECT id, name, code, to_required FROM clients WHERE client_type='billing' AND active=1 ORDER BY name");
$lines   = db_all("SELECT id, name FROM clients WHERE client_type='shipping_line' AND active=1 ORDER BY name");
$dests   = db_all("SELECT id, name, km FROM destinations WHERE active=1 ORDER BY name");
$trucks  = db_all("SELECT id, registration, truck_type FROM trucks WHERE status='active' ORDER BY registration");

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div><h1>Log a trip</h1><p>Fill in what you delivered and attach the POD. The office handles the rest.</p></div>
  <div class="actions"><a href="<?= BASE_URL ?>/my_dashboard.php" class="btn ghost">← Back</a></div>
</div>

<form method="post" action="<?= BASE_URL ?>/my_trip_new.php" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="card">
    <h3>Trip details</h3>
    <div class="form-grid">
      <div class="form-row"><label class="lbl">Date <span class="req">*</span></label><input type="date" name="collection_date" value="<?= date('Y-m-d') ?>" required></div>
      <div class="form-row"><label class="lbl">Load <span class="req">*</span></label>
        <select name="load_state" required><option value="FULL">Full box</option><option value="EMPTY">Empty</option></select></div>
      <div class="form-row"><label class="lbl">Client <span class="req">*</span></label>
        <select name="billing_client_id" id="billing_client_id" required><option value="">— choose —</option>
          <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" data-code="<?= e($c['code']) ?>" data-to="<?= (int)$c['to_required'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-row"><label class="lbl">Shipping line <span class="req">*</span></label>
        <select name="shipping_line_id" required><option value="">— choose —</option>
          <?php foreach ($lines as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-row"><label class="lbl">Container no. <span class="req">*</span></label>
        <input type="text" name="container_number" id="container_number" placeholder="e.g. TLLU1234567" required
               pattern="[A-Za-z0-9]+" title="Letters and numbers only — no spaces or symbols"
               oninput="this.value=this.value.replace(/[^A-Za-z0-9]/g,'').toUpperCase()"></div>
      <div class="form-row"><label class="lbl">Size <span class="req">*</span></label>
        <select name="container_size" required><option value="12m">12m (40ft)</option><option value="6m">6m (20ft)</option></select></div>
      <div class="form-row"><label class="lbl">Job type <span class="req">*</span></label>
        <select name="job_type" required><option value="">—</option>
          <?php foreach (array('crosshaul','packing','unpacking','empty','combi-dual','standing') as $jt): ?><option value="<?= $jt ?>"><?= ucfirst($jt) ?></option><?php endforeach; ?></select></div>
      <div class="form-row"><label class="lbl">Destination (if listed)</label>
        <select name="destination_id" id="destination_id" onchange="fillRoute()"><option value="0">— pick or type below —</option>
          <?php foreach ($dests as $d): ?><option value="<?= (int)$d['id'] ?>" data-name="<?= e($d['name']) ?>" data-km="<?= (int)$d['km'] ?>"><?= e($d['name']) ?> (<?= (int)$d['km'] ?> km)</option><?php endforeach; ?></select></div>
      <div class="form-row"><label class="lbl">Route</label><input type="text" name="route" id="route" placeholder="e.g. PORT - WORCESTER"></div>
      <div class="form-row"><label class="lbl">Distance (km)</label><input type="number" name="km" id="km" min="0" value="0"></div>
      <div class="form-row"><label class="lbl">T.O number <span id="to-req" style="color:#c0392b;display:none">*</span></label><input type="text" name="to_number" id="to_number"></div>
      <div class="form-row"><label class="lbl">Truck</label>
        <select name="truck_id"><option value="0">— unassigned —</option>
          <?php foreach ($trucks as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['registration']) ?></option><?php endforeach; ?></select></div>
      <div class="form-row"><label class="lbl">Genset?</label>
        <select name="has_genset"><option value="0">No</option><option value="1">Yes — reefer</option></select></div>
    </div>
  </div>

  <div class="card">
    <h3>📄 Proof of delivery <span class="req">*</span></h3>
    <p class="sub">Attach a clear photo or scan of the signed POD. This is required.</p>
    <input type="file" name="pod" accept="image/*,.pdf" required
           style="display:block;padding:12px;border:1.5px dashed var(--line);border-radius:10px;width:100%;background:var(--card-2)">
    <div class="form-row" style="margin-top:14px"><label class="lbl">Notes (optional)</label><textarea name="notes" rows="2"></textarea></div>
  </div>

  <div class="card"><button type="submit" class="btn primary block">Submit trip</button></div>
</form>

<script>
function fillRoute(){
  var s=document.getElementById('destination_id'); var o=s.options[s.selectedIndex];
  if(o && o.value!=='0'){ if(!document.getElementById('route').value) document.getElementById('route').value=(o.dataset.name||'').toUpperCase();
    if(o.dataset.km) document.getElementById('km').value=o.dataset.km; }
}
// T.O number is mandatory when the billing client is set to require it (Admin → Clients)
function syncToReq(){
  var s=document.getElementById('billing_client_id'); var o=s.options[s.selectedIndex];
  var isDcp = o && (o.dataset.to==='1');
  var to=document.getElementById('to_number'), mark=document.getElementById('to-req');
  if(to){ to.required = !!isDcp; }
  if(mark){ mark.style.display = isDcp ? '' : 'none'; }
}
document.getElementById('billing_client_id').addEventListener('change', syncToReq);
syncToReq();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
