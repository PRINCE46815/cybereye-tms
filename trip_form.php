<?php
/**
 * TMS — trip_form.php
 * Capture a new trip or edit an existing one (?id=). The rate engine
 * fills in suggested rate/genset/standing (all editable); the money
 * summary updates live. On save the server recomputes authoritatively.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rate_engine.php';
require_once __DIR__ . '/includes/driver_pay.php';
require_role('master', 'admin');

$editId = (int) get('id', 0);
$trip   = $editId ? db_row("SELECT * FROM trips WHERE id = ?", array($editId)) : null;
if ($editId && !$trip) { flash('error', 'That trip was not found.'); redirect('trips.php'); }

$pageTitle    = $trip ? 'Edit trip ' . $trip['trip_ref'] : 'New trip';
$pageSubtitle = 'Loads / Trips';

/* ---------------- Save ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // --- Container number: strip special characters, force uppercase ---
    $containerNo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) post('container_number')));

    // --- Required-field validation (server-side backstop to the in-form checks) ---
    $vBilling = (int) post('billing_client_id', 0);
    $vCode = '';
    if ($vBilling) { $vr = db_row("SELECT code FROM clients WHERE id=?", array($vBilling)); $vCode = $vr ? $vr['code'] : ''; }
    $missing = array();
    if (!post('collection_date'))                                    $missing[] = 'Date';
    if (!$vBilling)                                                  $missing[] = 'Billing client';
    if (!((int) post('shipping_line_id', 0)))                        $missing[] = 'Shipping line';
    if ($containerNo === '')                                         $missing[] = 'Container number';
    if (!in_array(post('container_size'), array('6m', '12m'), true)) $missing[] = 'Container size';
    if (!post('job_type'))                                           $missing[] = 'Job type';
    $cfgAll = billing_client_config();
    if ($vCode !== '' && !empty($cfgAll[$vCode]['to_required']) && trim((string) post('to_number')) === '')
                                                                     $missing[] = 'T.O number (required for ' . $cfgAll[$vCode]['name'] . ')';
    if ($missing) {
        flash('error', 'Please complete: ' . implode(', ', $missing) . '.');
        redirect($editId ? 'trip_form.php?id=' . $editId : 'trip_form.php');
    }

    // --- Container number must be unique (not already on another trip) ---
    $dup = db_row("SELECT trip_ref FROM trips WHERE container_number = ? AND id <> ? LIMIT 1", array($containerNo, (int) $editId));
    if ($dup) {
        flash('error', 'Container ' . $containerNo . ' is already recorded on trip ' . $dup['trip_ref'] . '. Container numbers must be unique.');
        redirect($editId ? 'trip_form.php?id=' . $editId : 'trip_form.php');
    }

    $loadState = post('load_state') === 'EMPTY' ? 'EMPTY' : 'FULL';
    $billingId = (int) post('billing_client_id', 0) ?: null;
    $subId     = (int) post('subcontractor_id', 0) ?: null;
    $truckId   = (int) post('truck_id', 0) ?: null;
    $driverId  = (int) post('driver_id', 0) ?: null;
    $destId    = (int) post('destination_id', 0) ?: null;
    $truckType = post('truck_type') === '6x2' ? '6x2' : '6x4';

    $rate      = (float) clean_number(post('rate'));
    $genset    = clean_number(post('genset_amount')); $genset = $genset !== null ? (float) $genset : 0;
    $standing  = clean_number(post('standing_amount')); $standing = $standing !== null ? (float) $standing : 0;
    $surcharge = clean_number(post('surcharge_total')); $surcharge = $surcharge !== null ? (float) $surcharge : 0;
    $km        = (int) post('km', 0);
    $standHrs  = (float) post('standing_hrs', 0);

    // Driver pay: use the entered amount, else auto from the rate book by route + size
    $size      = post('container_size') === '6m' ? '6m' : '12m';
    $driverPay = clean_number(post('driver_pay'));
    if ($driverPay === null) {
        $auto = driver_pay_for(post('route'), $size);
        $driverPay = $auto !== null ? $auto : 0;
    }
    $driverPay = (float) $driverPay;

    // Server-side authoritative financials
    $fin = rate_engine()->tripFinancials(array(
        'rate' => $rate, 'genset_amount' => $genset, 'standing_amount' => $standing,
        'surcharge_total' => $surcharge, 'km' => $km, 'genset' => post('has_genset') === '1',
        'is_empty' => ($loadState === 'EMPTY'), 'truck_type' => $truckType,
        'is_subbie' => ($subId !== null),
    ));

    $params = array(
        'collection_date'   => post('collection_date') ?: null,
        'loading_date'      => post('loading_date') ?: null,
        'delivery_date'     => post('delivery_date') ?: null,
        'status'            => post('status'),
        'billing_client_id' => $billingId,
        'shipping_line_id'  => (int) post('shipping_line_id', 0) ?: null,
        'to_number'         => post('to_number'),
        'subcontractor_id'  => $subId,
        'truck_id'          => $truckId,
        'driver_id'         => $driverId,
        'container_number'  => $containerNo,
        'container_size'    => post('container_size') === '6m' ? '6m' : '12m',
        'load_state'        => $loadState,
        'job_type'          => post('job_type') ?: null,
        'origin'            => post('origin'),
        'destination_id'    => $destId,
        'route'             => post('route'),
        'km'                => $km,
        'has_genset'        => post('has_genset') === '1' ? 1 : 0,
        'standing_hrs'      => $standHrs,
        'driver_pay'        => $driverPay,
        'notes'             => post('notes'),
    );

    if ($editId) {
        $sql = "UPDATE trips SET collection_date=?, loading_date=?, delivery_date=?, status=?,
                billing_client_id=?, shipping_line_id=?, to_number=?, subcontractor_id=?, truck_id=?, driver_id=?,
                container_number=?, container_size=?, load_state=?, job_type=?, origin=?, destination_id=?, route=?, km=?,
                has_genset=?, standing_hrs=?, rate=?, genset_amount=?, standing_amount=?, surcharge_total=?,
                gross_revenue=?, commission=?, subbie_payout=?, est_cost=?, net_profit=?, margin_pct=?,
                driver_pay=?, notes=?, modified_by=?, modified_at=NOW() WHERE id=?";
        dbq($sql, array(
            $params['collection_date'],$params['loading_date'],$params['delivery_date'],$params['status'],
            $params['billing_client_id'],$params['shipping_line_id'],$params['to_number'],$params['subcontractor_id'],$params['truck_id'],$params['driver_id'],
            $params['container_number'],$params['container_size'],$params['load_state'],$params['job_type'],$params['origin'],$params['destination_id'],$params['route'],$params['km'],
            $params['has_genset'],$params['standing_hrs'],$fin['rate'],$fin['genset_amount'],$fin['standing_amount'],$fin['surcharge_total'],
            $fin['gross_revenue'],$fin['commission'],$fin['subbie_payout'],$fin['est_cost'],$fin['net_profit'],$fin['margin_pct'],
            $params['driver_pay'],$params['notes'], $me['id'], $editId
        ));
        audit('update', 'trip', $editId, $trip['trip_ref']);
        flash('success', 'Trip ' . $trip['trip_ref'] . ' updated.');
        redirect('trip_view.php?id=' . $editId);
    } else {
        $sql = "INSERT INTO trips (trip_ref, collection_date, loading_date, delivery_date, status,
                billing_client_id, shipping_line_id, to_number, subcontractor_id, truck_id, driver_id,
                container_number, container_size, load_state, job_type, origin, destination_id, route, km,
                has_genset, standing_hrs, rate, genset_amount, standing_amount, surcharge_total,
                gross_revenue, commission, subbie_payout, est_cost, net_profit, margin_pct, driver_pay, outstanding,
                notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $newId = db_insert($sql, array(
            'TMP', $params['collection_date'],$params['loading_date'],$params['delivery_date'],$params['status'],
            $params['billing_client_id'],$params['shipping_line_id'],$params['to_number'],$params['subcontractor_id'],$params['truck_id'],$params['driver_id'],
            $params['container_number'],$params['container_size'],$params['load_state'],$params['job_type'],$params['origin'],$params['destination_id'],$params['route'],$params['km'],
            $params['has_genset'],$params['standing_hrs'],$fin['rate'],$fin['genset_amount'],$fin['standing_amount'],$fin['surcharge_total'],
            $fin['gross_revenue'],$fin['commission'],$fin['subbie_payout'],$fin['est_cost'],$fin['net_profit'],$fin['margin_pct'],$params['driver_pay'],$fin['gross_revenue'],
            $params['notes'], $me['id']
        ));
        $ref = setting('ref_prefix', 'RT') . str_pad($newId, 5, '0', STR_PAD_LEFT);
        dbq("UPDATE trips SET trip_ref = ? WHERE id = ?", array($ref, $newId));
        audit('create', 'trip', $newId, $ref);
        flash('success', 'Trip ' . $ref . ' captured.');
        redirect('trip_view.php?id=' . $newId);
    }
}

/* ---------------- Data for the form ---------------- */
$billingClients = db_all("SELECT id, code, name FROM clients WHERE client_type='billing' AND active=1 ORDER BY name");
$lines          = db_all("SELECT id, name FROM clients WHERE client_type='shipping_line' AND active=1 ORDER BY name");
$dests          = destinations_with_rates(true);
$clientCfg      = billing_client_config();
try { $empties = db_all("SELECT id, carrier, from_location, to_location, rate_12m FROM empty_rates WHERE active=1 ORDER BY carrier, rate_12m DESC"); } catch (Exception $e) { $empties = array(); }
$trucks         = db_all("SELECT id, registration, truck_type, owner_type, subcontractor_id FROM trucks WHERE status='active' ORDER BY registration");
$driverPayRates = driver_pay_options();
$drivers        = db_all("SELECT id, name, subcontractor_id FROM drivers WHERE status='active' ORDER BY name");
$subs           = db_all("SELECT id, name FROM subcontractors WHERE status='active' ORDER BY name");

$settingsRows = db_all("SELECT setting_key, setting_value FROM settings");
$settings = array(); foreach ($settingsRows as $r) { $settings[$r['setting_key']] = $r['setting_value']; }

$destsJs = array();
foreach ($dests as $d) { $destsJs[(int)$d['id']] = array('name'=>$d['name'],'rates'=>(object)$d['rates'],'km'=>(int)$d['km']); }
$emptiesJs = array();
foreach ($empties as $em) { $emptiesJs[(int)$em['id']] = array(
    'carrier'=>$em['carrier'],'from'=>$em['from_location'],'to'=>$em['to_location'],'rate12m'=>(float)$em['rate_12m']); }
$trucksJs = array();
foreach ($trucks as $t) { $trucksJs[(int)$t['id']] = array(
    'type'=>$t['truck_type'],'owner'=>$t['owner_type'],'sub'=>$t['subcontractor_id']!==null?(int)$t['subcontractor_id']:0); }

// Map billing client id -> client code for the engine
$clientCode = array();
foreach ($billingClients as $c) { $clientCode[(int)$c['id']] = $c['code']; }

$statuses = array('Draft','Scheduled','In Transit','Delivered','Invoiced','Paid','On Hold','Cancelled');
$jobTypes = array('crosshaul','packing','unpacking','empty','combi-dual','standing');

/* prefill helper */
function tv($trip, $key, $default = '') { return $trip && $trip[$key] !== null ? $trip[$key] : $default; }

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div><h1><?= $trip ? 'Edit ' . e($trip['trip_ref']) : 'Capture a trip' ?></h1>
    <p>The engine suggests the rate — adjust anything, the totals follow.</p></div>
  <div class="actions"><a href="<?= BASE_URL ?>/trips.php" class="btn ghost">← All trips</a></div>
</div>

<form method="post" action="<?= BASE_URL ?>/trip_form.php<?= $editId ? '?id=' . $editId : '' ?>">
  <?= csrf_field() ?>
  <div class="quote-grid">

    <!-- LEFT: fields -->
    <div>
      <!-- Load type -->
      <div class="tabs" style="margin-bottom:18px">
        <button type="button" id="lt-full" class="<?= tv($trip,'load_state','FULL')==='EMPTY'?'':'active' ?>" onclick="setLoad('FULL')">📦 Full Box</button>
        <button type="button" id="lt-empty" class="<?= tv($trip,'load_state')==='EMPTY'?'active':'' ?>" onclick="setLoad('EMPTY')">⬚ Empty</button>
      </div>
      <input type="hidden" name="load_state" id="load_state" value="<?= tv($trip,'load_state','FULL') ?>">

      <div class="card">
        <h3>Client &amp; container</h3>
        <div class="form-grid">
          <div class="form-row"><label class="lbl">Billing client <span style="color:#c0392b">*</span></label>
            <select name="billing_client_id" id="billing_client_id" required>
              <option value="">— choose —</option>
              <?php foreach ($billingClients as $c): ?>
                <option value="<?= (int)$c['id'] ?>" data-code="<?= e($c['code']) ?>" <?= (int)tv($trip,'billing_client_id')===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-row"><label class="lbl">Shipping line <span style="color:#c0392b">*</span></label>
            <select name="shipping_line_id" required>
              <option value="">— choose —</option>
              <?php foreach ($lines as $l): ?>
                <option value="<?= (int)$l['id'] ?>" <?= (int)tv($trip,'shipping_line_id')===(int)$l['id']?'selected':'' ?>><?= e($l['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-row"><label class="lbl">T.O number <span id="to-req" style="color:#c0392b;display:none">*</span></label>
            <input type="text" name="to_number" id="to_number" value="<?= e(tv($trip,'to_number')) ?>"></div>
          <div class="form-row"><label class="lbl">Container number <span style="color:#c0392b">*</span></label>
            <input type="text" name="container_number" id="container_number" value="<?= e(tv($trip,'container_number')) ?>"
                   placeholder="e.g. TLLU1234567" required pattern="[A-Za-z0-9]+"
                   title="Letters and numbers only — no spaces or symbols"
                   oninput="this.value=this.value.replace(/[^A-Za-z0-9]/g,'').toUpperCase()"></div>
          <div class="form-row"><label class="lbl">Container size <span style="color:#c0392b">*</span></label>
            <select name="container_size" id="container_size" required>
              <option value="12m" <?= tv($trip,'container_size','12m')==='12m'?'selected':'' ?>>12m (40ft)</option>
              <option value="6m" <?= tv($trip,'container_size')==='6m'?'selected':'' ?>>6m (20ft)</option>
            </select></div>
          <div class="form-row"><label class="lbl">Job type <span style="color:#c0392b">*</span></label>
            <select name="job_type" required>
              <option value="">—</option>
              <?php foreach ($jobTypes as $jt): ?>
                <option value="<?= e($jt) ?>" <?= tv($trip,'job_type')===$jt?'selected':'' ?>><?= e(ucfirst($jt)) ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
      </div>

      <!-- FULL route -->
      <div class="card" id="full-route">
        <h3>Route — full box</h3>
        <div class="form-grid">
          <div class="form-row"><label class="lbl">Destination</label>
            <select name="destination_id" id="destination_id">
              <option value="0">— choose destination —</option>
              <?php foreach ($dests as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= (int)tv($trip,'destination_id')===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?> (<?= (int)$d['km'] ?> km)</option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-row"><label class="lbl">Origin</label>
            <input type="text" name="origin" id="origin" value="<?= e(tv($trip,'origin')) ?>" placeholder="e.g. Port / depot"></div>
        </div>
      </div>

      <!-- EMPTY route -->
      <div class="card" id="empty-route" style="display:none">
        <h3>Route — empty</h3>
        <div class="form-grid">
          <div class="form-row"><label class="lbl">Carrier</label>
            <select id="e_carrier"><option value="">— choose —</option></select></div>
          <div class="form-row"><label class="lbl">Route</label>
            <select id="e_route"><option value="">— carrier first —</option></select></div>
        </div>
        <div class="hint">Picks the rate from your empty book. You can still override the rate on the right.</div>
      </div>

      <div class="card">
        <h3>Charges &amp; distance</h3>
        <div class="form-grid">
          <div class="form-row"><label class="lbl">Route description</label>
            <input type="text" name="route" id="route" value="<?= e(tv($trip,'route')) ?>" placeholder="e.g. PORT - WORCESTER"></div>
          <div class="form-row"><label class="lbl">Distance (km)</label>
            <input type="number" name="km" id="km" min="0" value="<?= (int)tv($trip,'km',0) ?>"></div>
          <div class="form-row"><label class="lbl">Genset?</label>
            <select name="has_genset" id="has_genset">
              <option value="0" <?= tv($trip,'has_genset','0')=='0'?'selected':'' ?>>No</option>
              <option value="1" <?= tv($trip,'has_genset')=='1'?'selected':'' ?>>Yes — reefer</option>
            </select></div>
          <div class="form-row"><label class="lbl">Standing hours (total waited)</label>
            <input type="number" name="standing_hrs" id="standing_hrs" min="0" step="0.5" value="<?= e(tv($trip,'standing_hrs','0')) ?>"></div>
        </div>
      </div>

      <div class="card">
        <h3>Assignment</h3>
        <div class="form-grid">
          <div class="form-row"><label class="lbl">Truck</label>
            <select name="truck_id" id="truck_id">
              <option value="0">— unassigned —</option>
              <?php foreach ($trucks as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= (int)tv($trip,'truck_id')===(int)$t['id']?'selected':'' ?>><?= e($t['registration']) ?> (<?= e($t['truck_type']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <div class="hint"><a href="<?= BASE_URL ?>/trucks.php" target="_blank">＋ manage trucks</a></div></div>
          <div class="form-row"><label class="lbl">Driver</label>
            <select name="driver_id" id="driver_id">
              <option value="0">— unassigned —</option>
              <?php foreach ($drivers as $dr): ?>
                <option value="<?= (int)$dr['id'] ?>" <?= (int)tv($trip,'driver_id')===(int)$dr['id']?'selected':'' ?>><?= e($dr['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="hint"><a href="<?= BASE_URL ?>/drivers.php" target="_blank">＋ manage drivers</a></div></div>
          <div class="form-row"><label class="lbl">Subcontractor (blank = own truck)</label>
            <select name="subcontractor_id" id="subcontractor_id">
              <option value="0">— Own fleet —</option>
              <?php foreach ($subs as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= (int)tv($trip,'subcontractor_id')===(int)$s['id']?'selected':'' ?>><?= e($s['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-row"><label class="lbl">Truck type (for commission)</label>
            <select name="truck_type" id="truck_type">
              <option value="6x4">6×4 fullbox (10%)</option>
              <option value="6x2">6×2 empty-only (4%)</option>
            </select></div>
          <div class="form-row"><label class="lbl">Driver pay (R) — auto from route</label>
            <div class="field-money"><input type="number" step="0.01" min="0" name="driver_pay" id="driver_pay" value="<?= e(tv($trip,'driver_pay','0')) ?>"></div>
            <div class="hint">
              <select id="driver_pay_pick" onchange="pickDriverPay(this)" style="max-width:230px">
                <option value="">apply a rate…</option>
                <?php foreach ($driverPayRates as $dpr): ?>
                  <option value="<?= (float)$dpr['amount'] ?>"><?= e($dpr['route']) ?> (<?= e($dpr['container_size']) ?>) — R<?= number_format($dpr['amount'],0) ?></option>
                <?php endforeach; ?>
              </select>
              <a href="<?= BASE_URL ?>/driver_rates.php" target="_blank">manage</a>
            </div></div>
        </div>
      </div>

      <div class="card">
        <h3>Dates &amp; status</h3>
        <div class="form-grid">
          <div class="form-row"><label class="lbl">Status</label>
            <select name="status">
              <?php foreach ($statuses as $st): ?>
                <option value="<?= e($st) ?>" <?= tv($trip,'status','Scheduled')===$st?'selected':'' ?>><?= e($st) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-row"><label class="lbl">Collection date</label>
            <input type="date" name="collection_date" value="<?= e(tv($trip,'collection_date')) ?>" required></div>
          <div class="form-row"><label class="lbl">Loading date</label>
            <input type="date" name="loading_date" value="<?= e(tv($trip,'loading_date')) ?>"></div>
          <div class="form-row"><label class="lbl">Delivery date</label>
            <input type="date" name="delivery_date" value="<?= e(tv($trip,'delivery_date')) ?>"></div>
        </div>
        <div class="form-row"><label class="lbl">Notes</label>
          <textarea name="notes" rows="2"><?= e(tv($trip,'notes')) ?></textarea></div>
      </div>
    </div>

    <!-- RIGHT: money -->
    <div>
      <div class="result-card">
        <div class="result-head">
          <div class="result-dest">Quote preview</div>
          <div class="result-profit" id="pv_profit">R 0.00<span class="pl"> profit</span></div>
          <div class="result-sub"><span class="badge" id="pv_margin">0% margin</span></div>
        </div>
        <div class="brk">
          <div class="brk-title">Editable amounts</div>
          <div class="form-row"><label class="lbl">Rate (R)</label>
            <div class="field-money"><input type="number" step="0.01" min="0" name="rate" id="rate" value="<?= e(tv($trip,'rate','0')) ?>"></div></div>
          <div class="form-row"><label class="lbl">Genset (R)</label>
            <div class="field-money"><input type="number" step="0.01" min="0" name="genset_amount" id="genset_amount" value="<?= e(tv($trip,'genset_amount','0')) ?>"></div></div>
          <div class="form-row"><label class="lbl">Standing (R)</label>
            <div class="field-money"><input type="number" step="0.01" min="0" name="standing_amount" id="standing_amount" value="<?= e(tv($trip,'standing_amount','0')) ?>"></div></div>
          <div class="form-row"><label class="lbl">Surcharges (R)</label>
            <div class="field-money"><input type="number" step="0.01" min="0" name="surcharge_total" id="surcharge_total" value="<?= e(tv($trip,'surcharge_total','0')) ?>"></div></div>
        </div>
        <div class="brk">
          <table class="data brk-table"><tbody id="pv_breakdown"></tbody></table>
        </div>
        <div class="brk">
          <button type="submit" class="btn primary block"><?= $trip ? 'Save trip' : 'Capture trip' ?></button>
        </div>
      </div>
    </div>
  </div>
</form>

<script src="<?= BASE_URL ?>/assets/js/rate-engine.js"></script>
<script>
var CLIENTS=<?= json_encode((object)$clientCfg) ?>;
RateEngine.init(<?= json_encode($settings) ?>, CLIENTS);
var DESTS=<?= json_encode($destsJs) ?>, EMPTIES=<?= json_encode($emptiesJs) ?>,
    TRUCKS=<?= json_encode($trucksJs) ?>, CLIENTCODE=<?= json_encode($clientCode) ?>,
    DPRATES=<?= json_encode($driverPayRates) ?>;

function $(id){ return document.getElementById(id); }
function clientCode(){ var v=$('billing_client_id').value; return CLIENTCODE[v]||null; }
function loadType(){ return $('load_state').value; }

// T.O number is mandatory when the billing client is set to require it (Admin → Clients)
function syncToReq(){
  var c = clientCode();
  var need = !!(c && CLIENTS[c] && parseInt(CLIENTS[c].to_required,10));
  var to=$('to_number'), mark=$('to-req');
  if(to){ to.required = need; }
  if(mark){ mark.style.display = need ? '' : 'none'; }
}

function setLoad(t){
  $('load_state').value=t;
  $('lt-full').classList.toggle('active',t==='FULL');
  $('lt-empty').classList.toggle('active',t==='EMPTY');
  $('full-route').style.display = t==='FULL'?'':'none';
  $('empty-route').style.display= t==='EMPTY'?'':'none';
  autoRate(); preview();
}

/* ---- auto-fill the rate from the engine ---- */
function autoRate(){
  if (loadType()==='FULL'){
    var di=$('destination_id').value, c=clientCode();
    if (di!=='0' && DESTS[di] && c){
      var d=DESTS[di];
      var rate=RateEngine.clientRate({rates:d.rates}, c);
      if (rate!=null) $('rate').value = rate.toFixed(2);
      $('km').value = d.km;
      if (!$('route').value) $('route').value = (d.name||'').toUpperCase();
    }
  } else {
    var ri=$('e_route').value;
    if (ri!=='' && EMPTIES[ri]){
      var em=EMPTIES[ri], size=$('container_size').value;
      var r=RateEngine.emptyRate(em.rate12m,size);
      if (r!=null) $('rate').value = r.toFixed(2);
      if (!$('route').value) $('route').value = em.from+' - '+em.to;
    }
  }
}
function autoGenset(){
  var c=clientCode();
  if ($('has_genset').value==='1' && c && loadType()==='FULL'){
    $('genset_amount').value = RateEngine.gensetPay(c).toFixed(2);
  } else if ($('has_genset').value==='0'){
    $('genset_amount').value = '0.00';
  }
}
function autoStanding(){
  var c=clientCode(); var h=parseFloat($('standing_hrs').value)||0;
  if (c){ $('standing_amount').value = RateEngine.standingCharge(c,h).toFixed(2); }
}

/* ---- live money preview ---- */
function preview(){
  var rate=parseFloat($('rate').value)||0;
  var fin=RateEngine.quote({
    mode: loadType()==='EMPTY'?'empty':'full', client: clientCode(), truckType: $('truck_type').value,
    rate: rate, genset:false, standingHours:0, surcharges:0, km: parseFloat($('km').value)||0
  });
  // override extras with the editable fields (rate may be manual)
  var genset=parseFloat($('genset_amount').value)||0;
  var standing=parseFloat($('standing_amount').value)||0;
  var surcharge=parseFloat($('surcharge_total').value)||0;
  var dpay=parseFloat($('driver_pay').value)||0;
  var gross=rate+genset+standing+surcharge;
  var vat=RateEngine.vat(gross);
  var cost=RateEngine.tripCost(parseFloat($('km').value)||0, $('has_genset').value==='1', loadType()==='EMPTY', dpay);
  var profit=gross-cost;
  var margin=gross>0?profit/gross*100:0;
  var comm=RateEngine.commission(rate, $('truck_type').value);
  var isSubbie = $('subcontractor_id').value!=='0';
  var payout = isSubbie ? rate-comm : 0;

  $('pv_profit').innerHTML = R(profit)+'<span class="pl"> profit</span>';
  var mc = margin>=25?'good':(margin>=15?'warn':'bad');
  $('pv_margin').className='badge '+mc; $('pv_margin').textContent=margin.toFixed(1)+'% margin';

  var rows='';
  rows+=row('Rate',rate); if(genset>0)rows+=row('Genset',genset); if(standing>0)rows+=row('Standing',standing); if(surcharge>0)rows+=row('Surcharges',surcharge);
  rows+=row('<b>Revenue (excl VAT)</b>',gross,'sub');
  rows+=row('VAT',vat,'muted'); rows+=row('Revenue (incl VAT)',gross+vat,'muted');
  rows+=row('Operating cost',-cost,'cost'); rows+=row('<b>Net profit</b>',profit,'profit');
  rows+=row('Commission',comm,'muted');
  if(isSubbie) rows+=row('Subbie payout',payout,'muted');
  if(dpay>0) rows+=row('Driver pay',dpay,'muted');
  $('pv_breakdown').innerHTML=rows;
}
function row(l,v,c){ c=c||''; var col=c==='cost'?'color:var(--bad)':(c==='profit'?'color:var(--good);font-weight:700':(c==='sub'?'font-weight:700':''));
  var m=c==='muted'?'muted':''; return '<tr class="'+(c==='sub'||c==='profit'?'brk-strong':'')+'"><td class="'+m+'">'+l+'</td><td class="num '+m+'" style="'+col+'">'+R(v)+'</td></tr>'; }

/* ---- driver pay: pick from book or auto-match by route + size ---- */
function pickDriverPay(sel){ if(sel.value!==''){ $('driver_pay').value=parseFloat(sel.value).toFixed(2); preview(); } }
function autoDriverPay(){
  var route=($('route').value||'').trim().toUpperCase(); if(!route) return;
  var size=$('container_size').value, match=null, any=null;
  for(var i=0;i<DPRATES.length;i++){ var r=DPRATES[i]; if((r.route||'').toUpperCase()===route){ if(r.container_size===size){match=r;break;} if(!any)any=r; } }
  var hit=match||any; if(hit){ $('driver_pay').value=parseFloat(hit.amount).toFixed(2); }
}

/* ---- truck auto-fills type + subbie ---- */
$('truck_id').addEventListener('change',function(){
  var t=TRUCKS[this.value];
  if(t){ $('truck_type').value=t.type; if(t.owner==='subcontractor'&&t.sub){ $('subcontractor_id').value=t.sub; } }
  preview();
});

/* ---- empty carrier/route ---- */
(function(){
  var carriers=[]; for(var k in EMPTIES){ if(carriers.indexOf(EMPTIES[k].carrier)<0)carriers.push(EMPTIES[k].carrier); }
  var cs=$('e_carrier'); carriers.forEach(function(c){var o=document.createElement('option');o.value=c;o.textContent=c;cs.appendChild(o);});
  cs.addEventListener('change',function(){
    var rs=$('e_route'); rs.innerHTML='<option value="">— choose route —</option>';
    for(var k in EMPTIES){ if(EMPTIES[k].carrier===cs.value){ var o=document.createElement('option');o.value=k;o.textContent=EMPTIES[k].from+' → '+EMPTIES[k].to+' ('+R(EMPTIES[k].rate12m)+')';rs.appendChild(o);} }
    autoRate();preview();
  });
  $('e_route').addEventListener('change',function(){autoRate();preview();});
})();

/* ---- wiring ---- */
['destination_id','billing_client_id','container_size'].forEach(function(id){ $(id).addEventListener('change',function(){autoRate();autoGenset();autoStanding();autoDriverPay();syncToReq();preview();}); });
$('has_genset').addEventListener('change',function(){autoGenset();preview();});
$('standing_hrs').addEventListener('input',function(){autoStanding();preview();});
$('route').addEventListener('input',function(){autoDriverPay();preview();});
['rate','genset_amount','standing_amount','surcharge_total','km','truck_type','subcontractor_id','driver_pay'].forEach(function(id){
  $(id).addEventListener('input',preview); $(id).addEventListener('change',preview); });

setLoad(loadType());
syncToReq();
preview();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
