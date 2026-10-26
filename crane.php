<?php
/**
 * TMS — crane.php   (Fleet & People → Crane Truck)
 * Enter the destination and the ROUND-TRIP kilometres. The system prices the call-out from
 * diesel + running cost (like the other trucks), adds the 3-man crew hire and VAT, and you
 * can save it as a quote or generate the tax invoice straight from this tab.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/crane_lib.php';
require_role('master', 'admin', 'finance');

$pageTitle    = 'Crane Truck';
$pageSubtitle = 'Quote & invoice';
$me           = current_user();
$cfg          = crane_config();
$isMaster     = is_master();

/* ---------------- Actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    /* ---- Save a job as a quote, or save + invoice it ---- */
    if ($action === 'save_job') {
        $id   = (int) post('id', 0);
        $mode = post('mode') === 'invoice' ? 'invoice' : 'quote';
        $back = 'crane.php' . ($id ? '?edit=' . $id : '');

        $name  = trim(post('customer_name'));
        $dest  = trim(post('destination'));
        $date  = crane_valid_date(post('job_date'));
        $kmRaw = clean_number(post('round_trip_km'));
        $hrRaw = clean_number(post('crew_hours'));
        if ($name === '' || $dest === '' || !$date || $kmRaw === null || $hrRaw === null) {
            flash('error', 'Please fill in the customer, job date, destination, round-trip km and crew hours.');
            redirect($back);
        }
        $ovRaw = trim(post('callout_override'));
        $override = ($ovRaw === '') ? null : clean_number($ovRaw);
        if ($ovRaw !== '' && $override === null) { flash('error', 'The call-out amount must be a number.'); redirect($back); }
        $xDesc = trim(post('extra_desc'));
        $xAmt  = clean_number(post('extra_amount'));
        $xAmt  = ($xAmt === null || $xDesc === '') ? 0.0 : (float) $xAmt;
        if ($xAmt == 0.0) { $xDesc = ''; }

        $calc = crane_calc($kmRaw, $hrRaw, $override, $xAmt, $cfg);

        $existing = $id ? db_row("SELECT * FROM crane_jobs WHERE id = ?", array($id)) : null;
        if ($id && (!$existing || $existing['status'] !== 'quote')) {
            flash('error', 'Only quotes can be edited. An issued invoice is locked.');
            redirect('crane.php');
        }

        $fields = array(
            'job_date' => $date, 'customer_name' => $name,
            'customer_vat' => trim(post('customer_vat')), 'customer_address' => trim(post('customer_address')),
            'customer_ref' => trim(post('customer_ref')), 'destination' => $dest,
            'round_trip_km' => $calc['km'], 'crew_hours' => $calc['hours'],
            'diesel_price' => $cfg['diesel_price'], 'consumption' => $cfg['consumption'], 'running_cost' => $cfg['running_cost'],
            'diesel_cost' => $calc['diesel_cost'], 'running_total' => $calc['running_total'], 'op_cost' => $calc['op_cost'],
            'callout_markup' => $cfg['markup'], 'callout_override' => $calc['callout_override'], 'callout_charge' => $calc['callout_charge'],
            'crew_rate' => $cfg['crew_rate'], 'crew_charge' => $calc['crew_charge'],
            'extra_desc' => $xDesc, 'extra_amount' => $calc['extra_amount'],
            'subtotal' => $calc['subtotal'], 'vat_pct' => $cfg['vat_pct'], 'vat_amount' => $calc['vat_amount'], 'total' => $calc['total'],
            'notes' => trim(post('notes')),
        );
        foreach (array('customer_vat', 'customer_address', 'customer_ref', 'extra_desc', 'notes') as $nullable) {
            if ($fields[$nullable] === '') { $fields[$nullable] = null; }
        }

        if ($existing) {
            $set = implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($fields)));
            dbq("UPDATE crane_jobs SET $set WHERE id = ?", array_merge(array_values($fields), array($id)));
            $jobId = $id;
            audit('update', 'crane_job', $jobId, $existing['quote_no'] . ' · ' . $name);
        } else {
            $fields['status']     = 'quote';
            $fields['quote_no']   = ($mode === 'quote') ? crane_next_number('quote') : null;   // direct invoices don't use up a quote number
            $fields['created_by'] = $me['id'];
            $cols = implode(', ', array_keys($fields));
            $qs   = implode(', ', array_fill(0, count($fields), '?'));
            $jobId = db_insert("INSERT INTO crane_jobs ($cols) VALUES ($qs)", array_values($fields));
            audit('create', 'crane_job', $jobId, ($fields['quote_no'] ?: 'direct invoice') . ' · ' . $name);
        }

        if ($mode === 'invoice') {
            crane_issue_invoice($jobId, $cfg);
            flash('success', 'Invoice created.');
            redirect('crane_invoice.php?id=' . $jobId);
        }
        flash('success', 'Quote saved.');
        redirect('crane.php#jobs');
    }

    /* ---- Turn a saved quote into an invoice ---- */
    if ($action === 'invoice_job') {
        $id  = (int) post('id', 0);
        $job = db_row("SELECT * FROM crane_jobs WHERE id = ?", array($id));
        if ($job && $job['status'] === 'quote') {
            crane_issue_invoice($id, $cfg);
            flash('success', 'Invoice created.');
            redirect('crane_invoice.php?id=' . $id);
        }
        flash('error', 'That job is not an open quote.');
        redirect('crane.php#jobs');
    }

    if ($action === 'mark_paid') {
        $id  = (int) post('id', 0);
        $job = db_row("SELECT invoice_no, status FROM crane_jobs WHERE id = ?", array($id));
        if ($job && $job['status'] === 'invoiced') {
            dbq("UPDATE crane_jobs SET status='paid', paid_at = CURDATE() WHERE id = ?", array($id));
            audit('update', 'crane_job', $id, $job['invoice_no'] . ' marked paid');
            flash('success', $job['invoice_no'] . ' marked as paid.');
        }
        redirect('crane.php#jobs');
    }

    if ($action === 'cancel_job') {
        $id  = (int) post('id', 0);
        $job = db_row("SELECT quote_no, invoice_no, status FROM crane_jobs WHERE id = ?", array($id));
        if ($job && in_array($job['status'], array('quote', 'invoiced'), true)) {
            dbq("UPDATE crane_jobs SET status='cancelled' WHERE id = ?", array($id));
            audit('update', 'crane_job', $id, ($job['invoice_no'] ?: $job['quote_no']) . ' cancelled');
            flash('success', 'Cancelled. The document number is kept for your records.');
        }
        redirect('crane.php#jobs');
    }

    if ($action === 'delete_job' && $isMaster) {
        $id  = (int) post('id', 0);
        $job = db_row("SELECT quote_no, status FROM crane_jobs WHERE id = ?", array($id));
        if ($job && in_array($job['status'], array('quote', 'cancelled'), true)) {
            dbq("DELETE FROM crane_jobs WHERE id = ?", array($id));
            audit('delete', 'crane_job', $id, $job['quote_no']);
            flash('success', 'Deleted.');
        } else {
            flash('error', 'Issued invoices cannot be deleted. Cancel them instead.');
        }
        redirect('crane.php#jobs');
    }
}

/** Assign the invoice number + dates to a job (quote -> invoiced). */
function crane_issue_invoice($jobId, $cfg)
{
    $no  = crane_next_number('invoice');
    $inv = date('Y-m-d');
    $due = date('Y-m-d', strtotime($inv . ' +' . max(0, (int) $cfg['due_days']) . ' days'));
    dbq("UPDATE crane_jobs SET status='invoiced', invoice_no=?, invoice_date=?, due_date=? WHERE id=? AND status='quote'",
        array($no, $inv, $due, $jobId));
    audit('create', 'crane_invoice', $jobId, $no);
}

/* ---------------- Load ---------------- */
$editId = (int) get('edit', 0);
$edit   = $editId ? db_row("SELECT * FROM crane_jobs WHERE id = ? AND status = 'quote'", array($editId)) : null;

$jobs = db_all("SELECT * FROM crane_jobs ORDER BY id DESC LIMIT 200");
$prevCustomers = db_all("SELECT customer_name, customer_vat, customer_address FROM crane_jobs
                         WHERE id IN (SELECT MAX(id) FROM crane_jobs GROUP BY customer_name) ORDER BY customer_name");

$f = function ($k, $d = '') use ($edit) { return $edit && $edit[$k] !== null ? $edit[$k] : $d; };
$minHours = $cfg['min_hours'];

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/trucks.php">Trucks</a>
  <a href="<?= BASE_URL ?>/crane.php" class="active">Crane Truck</a>
</div>

<div class="page-head">
  <div>
    <h1>Crane Truck</h1>
    <p>Enter the destination and the round-trip kilometres. Diesel and running cost are worked out like the other trucks;
       then the 3-man crew hire and VAT are added. Save it as a quote or create the invoice from here.</p>
  </div>
  <div class="actions"><a class="btn ghost" href="<?= BASE_URL ?>/rates.php">⚙ Crane rates &amp; settings</a></div>
</div>

<form method="post" action="<?= BASE_URL ?>/crane.php" id="craneForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_job">
  <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
  <input type="hidden" name="mode" id="mode" value="quote">

  <div class="grid-2" style="display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:18px;align-items:start">

  <div>
    <div class="card">
      <h3><?= $edit ? '✎ Edit quote ' . e($edit['quote_no']) : '＋ New crane job' ?></h3>
      <div class="form-grid">
        <div class="form-row"><label class="lbl">Customer <span class="req">*</span></label>
          <input type="text" name="customer_name" id="customer_name" list="custlist" required autocomplete="off" value="<?= e($f('customer_name')) ?>" placeholder="e.g. Hermetic Mobility Africa P/L">
          <datalist id="custlist"><?php foreach ($prevCustomers as $pc): ?><option value="<?= e($pc['customer_name']) ?>"><?php endforeach; ?></datalist></div>
        <div class="form-row"><label class="lbl">Customer VAT number</label>
          <input type="text" name="customer_vat" id="customer_vat" value="<?= e($f('customer_vat')) ?>"></div>
        <div class="form-row" style="grid-column:1/-1"><label class="lbl">Customer address (optional)</label>
          <input type="text" name="customer_address" id="customer_address" value="<?= e($f('customer_address')) ?>" placeholder="Street, suburb, city"></div>
        <div class="form-row"><label class="lbl">Job date <span class="req">*</span></label>
          <input type="date" name="job_date" required value="<?= e($f('job_date', date('Y-m-d'))) ?>"></div>
        <div class="form-row"><label class="lbl">Customer reference / PO (optional)</label>
          <input type="text" name="customer_ref" value="<?= e($f('customer_ref')) ?>"></div>
        <div class="form-row"><label class="lbl">Destination <span class="req">*</span></label>
          <input type="text" name="destination" id="destination" required value="<?= e($f('destination')) ?>" placeholder="e.g. Blackheath"></div>
        <div class="form-row"><label class="lbl">Round-trip distance (km) <span class="req">*</span></label>
          <input type="number" name="round_trip_km" id="km" required min="0" step="0.1" value="<?= e($f('round_trip_km', '')) ?>" placeholder="there and back"></div>
        <div class="form-row"><label class="lbl">Crew hire (hours) <span class="req">*</span></label>
          <input type="number" name="crew_hours" id="hours" required min="0" step="0.25" value="<?= e($f('crew_hours', rtrim(rtrim(number_format($minHours, 2, '.', ''), '0'), '.'))) ?>">
          <div class="hint">Minimum <?= e(rtrim(rtrim(number_format($minHours, 2, '.', ''), '0'), '.')) ?> hours is always charged.</div></div>
        <div class="form-row"><label class="lbl">Call-out amount override (R, optional)</label>
          <div class="field-money"><input type="number" name="callout_override" id="override" min="0" step="0.01" value="<?= ($edit && $edit['callout_override']) ? e($edit['callout_charge']) : '' ?>" placeholder="leave blank = calculated"></div>
          <div class="hint">Use this to quote a fixed call-out instead of the calculated one.</div></div>
        <div class="form-row"><label class="lbl">Extra line (optional)</label>
          <input type="text" name="extra_desc" id="extra_desc" value="<?= e($f('extra_desc')) ?>" placeholder="e.g. Rigging equipment"></div>
        <div class="form-row"><label class="lbl">Extra line amount (excl. VAT)</label>
          <div class="field-money"><input type="number" name="extra_amount" id="extra_amount" min="0" step="0.01" value="<?= ($edit && (float) $edit['extra_amount'] > 0) ? e($edit['extra_amount']) : '' ?>"></div></div>
        <div class="form-row" style="grid-column:1/-1"><label class="lbl">Internal notes (not printed)</label>
          <input type="text" name="notes" value="<?= e($f('notes')) ?>"></div>
      </div>
    </div>
  </div>

  <!-- Live calculation -->
  <div>
    <div class="card" style="position:sticky;top:76px">
      <h3>Price</h3>
      <table class="data" style="margin:0">
        <tbody id="calc">
          <tr><td colspan="2" class="muted">Enter the round-trip km to see the price.</td></tr>
        </tbody>
      </table>
      <div class="form-actions" style="flex-wrap:wrap;gap:8px">
        <button type="submit" class="btn primary" onclick="document.getElementById('mode').value='invoice'">Save &amp; create invoice</button>
        <button type="submit" class="btn ghost" onclick="document.getElementById('mode').value='quote'">Save as quote</button>
        <?php if ($edit): ?><a class="btn ghost" href="<?= BASE_URL ?>/crane.php">Cancel</a><?php endif; ?>
      </div>
      <p class="note" style="margin:10px 0 0">Rates used: diesel R<?= num($cfg['diesel_price'], 2) ?>/L · <?= num($cfg['consumption'], 1) ?> L/100km · running R<?= num($cfg['running_cost'], 2) ?>/km ·
        crew R<?= num($cfg['crew_rate'], 2) ?>/h · VAT <?= num($cfg['vat_pct'], 0) ?>%. Change them under Admin → Rates &amp; Costs → Crane Truck.</p>
    </div>
  </div>

  </div>
</form>

<!-- Jobs -->
<div class="card" id="jobs">
  <h3>Crane jobs</h3>
  <p class="sub">Newest first. Quotes can be edited and turned into invoices. Issued invoices are locked.</p>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Date</th><th>Quote</th><th>Invoice</th><th>Customer</th><th>Destination</th><th class="num">km</th>
        <th class="num">Total (incl. VAT)</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php if (!$jobs): ?><tr><td colspan="9" class="muted">No crane jobs yet.</td></tr><?php endif; ?>
      <?php
        $badge = array('quote' => 'muted', 'invoiced' => 'gold', 'paid' => 'good', 'cancelled' => 'muted');
        foreach ($jobs as $j): $jid = (int) $j['id']; ?>
        <tr>
          <td><?= e($j['job_date']) ?></td>
          <td><?= e($j['quote_no']) ?></td>
          <td><b><?= e($j['invoice_no']) ?></b></td>
          <td><?= e($j['customer_name']) ?></td>
          <td><?= e($j['destination']) ?></td>
          <td class="num"><?= num($j['round_trip_km'], 1) ?></td>
          <td class="num"><b><?= num($j['total'], 2) ?></b></td>
          <td><span class="badge <?= $badge[$j['status']] ?>"><?= e(ucfirst($j['status'])) ?></span></td>
          <td><div class="row-actions">
            <?php if ($j['status'] === 'quote'): ?>
              <a class="btn ghost sm" href="<?= BASE_URL ?>/crane.php?edit=<?= $jid ?>#craneForm">Edit</a>
              <a class="btn ghost sm" href="<?= BASE_URL ?>/crane_invoice.php?id=<?= $jid ?>">View quote</a>
              <form method="post" action="<?= BASE_URL ?>/crane.php" style="display:inline" onsubmit="return confirm('Create the invoice from this quote?');">
                <?= csrf_field() ?><input type="hidden" name="action" value="invoice_job"><input type="hidden" name="id" value="<?= $jid ?>">
                <button class="btn primary sm">Create invoice</button></form>
            <?php elseif ($j['status'] === 'invoiced' || $j['status'] === 'paid'): ?>
              <a class="btn ghost sm" href="<?= BASE_URL ?>/crane_invoice.php?id=<?= $jid ?>">View invoice</a>
              <?php if ($j['status'] === 'invoiced'): ?>
              <form method="post" action="<?= BASE_URL ?>/crane.php" style="display:inline" onsubmit="return confirm('Mark this invoice as paid?');">
                <?= csrf_field() ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?= $jid ?>">
                <button class="btn ghost sm">Mark paid</button></form>
              <?php endif; ?>
            <?php endif; ?>
            <?php if (in_array($j['status'], array('quote', 'invoiced'), true)): ?>
              <form method="post" action="<?= BASE_URL ?>/crane.php" style="display:inline" onsubmit="return confirm('Cancel this document? The number is kept.');">
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel_job"><input type="hidden" name="id" value="<?= $jid ?>">
                <button class="btn ghost sm">Cancel</button></form>
            <?php endif; ?>
            <?php if ($isMaster && in_array($j['status'], array('quote', 'cancelled'), true)): ?>
              <form method="post" action="<?= BASE_URL ?>/crane.php" style="display:inline" onsubmit="return confirm('Delete permanently?');">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete_job"><input type="hidden" name="id" value="<?= $jid ?>">
                <button class="btn ghost sm">Delete</button></form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
var CFG = <?= json_encode($cfg) ?>;
var CUST = <?= json_encode(array_column($prevCustomers, null, 'customer_name') ?: new stdClass()) ?>;
function $(id){ return document.getElementById(id); }
function R(n){ return 'R ' + n.toLocaleString('en-ZA',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function r2(n){ return Math.round(n*100)/100; }
function preview(){
  var km = parseFloat($('km').value)||0, hrs = parseFloat($('hours').value)||0;
  var ovRaw = $('override').value.trim(), ov = ovRaw === '' ? null : parseFloat(ovRaw);
  var extra = parseFloat($('extra_amount').value)||0, xd = $('extra_desc').value.trim();
  if (!xd) extra = 0;
  var diesel = r2(km*(CFG.consumption/100)*CFG.diesel_price);
  var running = r2(km*CFG.running_cost);
  var op = r2(diesel+running);
  var callout = (ov !== null && !isNaN(ov)) ? r2(ov) : r2(op*(1+CFG.markup/100));
  var bill = Math.max(hrs, CFG.min_hours);
  var crew = r2(bill*CFG.crew_rate);
  var sub = r2(callout+crew+extra), vat = r2(sub*CFG.vat_pct/100), tot = r2(sub+vat);
  function row(l,v,cls){ return '<tr class="'+(cls||'')+'"><td>'+l+'</td><td class="num">'+v+'</td></tr>'; }
  var h = '';
  h += row('Diesel ('+km+' km × '+CFG.consumption+' L/100km × R'+CFG.diesel_price.toFixed(2)+')', R(diesel), 'muted');
  h += row('Running cost ('+km+' km × R'+CFG.running_cost.toFixed(2)+')', R(running), 'muted');
  h += row('<b>Your operating cost</b>', '<b>'+R(op)+'</b>', 'muted');
  h += row('Call-out' + ((ov!==null&&!isNaN(ov)) ? ' (manual)' : (CFG.markup ? ' (cost + '+CFG.markup+'%)' : ' (at cost)')), R(callout));
  h += row('Crane hire, 3-man crew ('+bill+' h × R'+CFG.crew_rate.toFixed(2)+')', R(crew));
  if (extra > 0) h += row(xd.replace(/</g,'&lt;'), R(extra));
  h += row('<b>Subtotal</b>', '<b>'+R(sub)+'</b>');
  h += row('VAT '+CFG.vat_pct+'%', R(vat));
  h += row('<b style="font-size:15px">TOTAL</b>', '<b style="font-size:15px">'+R(tot)+'</b>');
  var mg = r2(callout-op);
  h += row('Call-out margin over cost', R(mg), mg < 0 ? 'neg' : 'muted');
  $('calc').innerHTML = h;
}
['km','hours','override','extra_amount','extra_desc'].forEach(function(id){ $(id).addEventListener('input', preview); });
$('customer_name').addEventListener('change', function(){
  var c = CUST[this.value]; if (!c) return;
  if (!$('customer_vat').value) $('customer_vat').value = c.customer_vat || '';
  if (!$('customer_address').value) $('customer_address').value = c.customer_address || '';
});
preview();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
