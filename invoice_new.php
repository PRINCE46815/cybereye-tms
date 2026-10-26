<?php
/**
 * TMS — invoice_new.php
 * Two steps: choose client + period, then pick unbilled trips and generate.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoice_lib.php';
require_role('master', 'admin');

$pageTitle    = 'New invoice';
$pageSubtitle = 'Finance';

/* ---- Generate (POST) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $clientId = (int) post('client_id', 0);
    $from     = post('from'); $to = post('to');
    $label    = trim(post('period_label'));
    $tripIds  = isset($_POST['trip_ids']) && is_array($_POST['trip_ids']) ? array_map('intval', $_POST['trip_ids']) : array();

    $client = db_row("SELECT id, name, code FROM clients WHERE id=? AND client_type='billing'", array($clientId));
    if (!$client) { flash('error', 'Choose a billing client.'); redirect('invoice_new.php'); }
    if (!$tripIds) { flash('error', 'Select at least one trip to invoice.'); redirect('invoice_new.php?client_id=' . $clientId . '&from=' . urlencode($from) . '&to=' . urlencode($to)); }

    // Re-fetch the selected trips server-side (authoritative amounts; must be unbilled & this client)
    $place = implode(',', array_fill(0, count($tripIds), '?'));
    $params = array_merge(array($clientId), $tripIds);
    $trips = db_all("SELECT id, gross_revenue FROM trips WHERE billing_client_id=? AND invoice_id IS NULL AND id IN ($place)", $params);
    if (!$trips) { flash('error', 'Those trips are already invoiced or not billable.'); redirect('invoice_new.php?client_id=' . $clientId); }

    $subtotal = 0; foreach ($trips as $t) { $subtotal += (float) $t['gross_revenue']; }
    $vat   = $subtotal * setting_num('vat_pct') / 100;
    $total = $subtotal + $vat;
    $num   = next_invoice_number($clientId, $client['code']);

    $invId = db_insert(
        "INSERT INTO invoices (invoice_number, client_id, period_start, period_end, period_label, subtotal, vat_amount, total, status, created_by)
         VALUES (?,?,?,?,?,?,?,?, 'draft', ?)",
        array($num, $clientId, ($from ?: null), ($to ?: null), ($label !== '' ? $label : null),
              round($subtotal, 2), round($vat, 2), round($total, 2), $me['id']));

    // Link trips
    foreach ($trips as $t) {
        $gross = (float) $t['gross_revenue'];
        $vatT  = $gross * setting_num('vat_pct') / 100;
        dbq("UPDATE trips SET invoice_id=?, status=IF(status='Cancelled',status,'Invoiced'), payment_status='unpaid', outstanding=? WHERE id=?",
            array($invId, round($gross + $vatT, 2), $t['id']));
    }
    audit('create', 'invoice', $invId, $num);
    flash('success', 'Invoice ' . $num . ' created with ' . count($trips) . ' trip(s).');
    redirect('invoice_view.php?id=' . $invId);
}

/* ---- Form data ---- */
$clients  = db_all("SELECT id, name, code FROM clients WHERE client_type='billing' ORDER BY name");
$clientId = (int) get('client_id', 0);
$from     = get('from', date('Y-m-01'));
$to       = get('to', date('Y-m-d'));
$client   = $clientId ? db_row("SELECT id, name, code FROM clients WHERE id=? AND client_type='billing'", array($clientId)) : null;

$unbilled = array();
if ($client) {
    $unbilled = db_all(
        "SELECT t.id, t.trip_ref, t.collection_date, t.route, t.container_number, t.container_size, t.load_state,
                t.gross_revenue, t.status
         FROM trips t
         WHERE t.billing_client_id=? AND t.invoice_id IS NULL AND t.status<>'Cancelled'
           AND t.collection_date BETWEEN ? AND ?
         ORDER BY t.collection_date ASC, t.id ASC",
        array($clientId, $from, $to));
}
$vatPct = setting_num('vat_pct');

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/invoices.php">Invoices</a>
  <a href="<?= BASE_URL ?>/statements.php">Statements</a>
</div>

<div class="page-head">
  <div><h1>New invoice</h1><p>Pick a client and period, then choose the trips to bill.</p></div>
  <div class="actions"><a href="<?= BASE_URL ?>/invoices.php" class="btn ghost">← All invoices</a></div>
</div>

<!-- Step 1: client + period -->
<div class="card">
  <form method="get" action="<?= BASE_URL ?>/invoice_new.php" class="form-grid" style="margin-bottom:0">
    <div class="form-row"><label class="lbl">Billing client</label>
      <select name="client_id" required>
        <option value="0">— choose —</option>
        <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $clientId===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="form-row"><label class="lbl">From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="form-row"><label class="lbl">To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="form-row" style="align-self:end"><button type="submit" class="btn dark">Find unbilled trips</button></div>
  </form>
</div>

<?php if ($client): ?>
<form method="post" action="<?= BASE_URL ?>/invoice_new.php">
  <?= csrf_field() ?>
  <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
  <input type="hidden" name="from" value="<?= e($from) ?>">
  <input type="hidden" name="to" value="<?= e($to) ?>">

  <div class="card">
    <div class="flex" style="justify-content:space-between;align-items:center">
      <h3 style="margin:0">Unbilled trips — <?= e($client['name']) ?>
        <span class="badge muted"><?= invoice_template_for($client['code'])==='A' ? 'Container-led' : 'Haulier-led' ?> layout</span></h3>
      <label class="sur-toggle" style="margin:0"><input type="checkbox" id="checkall" checked onclick="toggleAll(this)"> Select all</label>
    </div>
    <div class="form-row" style="max-width:340px;margin-top:12px"><label class="lbl">Period label on invoice (optional)</label>
      <input type="text" name="period_label" placeholder="e.g. November 2026"></div>

    <?php if (!$unbilled): ?>
      <div class="empty"><div class="big">✓</div>No unbilled trips for <?= e($client['name']) ?> in this period.</div>
    <?php else: ?>
    <div class="table-wrap" style="margin-top:8px">
      <table class="data">
        <thead><tr><th style="width:34px"></th><th>Ref</th><th>Date</th><th>Route</th><th>Container</th><th>Status</th><th class="num">Amount (excl VAT)</th></tr></thead>
        <tbody>
          <?php foreach ($unbilled as $t): ?>
            <tr>
              <td><input type="checkbox" class="tripchk" name="trip_ids[]" value="<?= (int)$t['id'] ?>" data-amt="<?= (float)$t['gross_revenue'] ?>" checked onchange="recalc()"></td>
              <td><b><?= e($t['trip_ref']) ?></b></td>
              <td class="muted"><?= fdate($t['collection_date']) ?></td>
              <td><?= e($t['route'] ? $t['route'] : '—') ?></td>
              <td><span class="badge muted"><?= e($t['container_size']) ?> <?= e($t['load_state']) ?></span> <?= e($t['container_number']) ?></td>
              <td><span class="badge muted"><?= e($t['status']) ?></span></td>
              <td class="num"><?= money($t['gross_revenue']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="grid-2" style="margin-top:16px">
      <div></div>
      <div class="result-card" style="position:static">
        <div class="brk">
          <table class="data brk-table"><tbody>
            <tr><td>Subtotal (excl VAT)</td><td class="num" id="t_sub">R 0.00</td></tr>
            <tr class="muted"><td>VAT @ <?= num($vatPct,0) ?>%</td><td class="num muted" id="t_vat">R 0.00</td></tr>
            <tr class="brk-strong"><td><b>Total</b></td><td class="num" id="t_total"><b>R 0.00</b></td></tr>
          </tbody></table>
        </div>
        <div class="brk"><button type="submit" class="btn primary block">Generate invoice</button></div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</form>

<script>
var VAT = <?= json_encode($vatPct) ?>;
function R(n){ return 'R '+n.toLocaleString('en-ZA',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function recalc(){
  var sub=0; document.querySelectorAll('.tripchk:checked').forEach(function(c){ sub+=parseFloat(c.dataset.amt)||0; });
  var vat=sub*VAT/100;
  document.getElementById('t_sub').textContent=R(sub);
  document.getElementById('t_vat').textContent=R(vat);
  document.getElementById('t_total').innerHTML='<b>'+R(sub+vat)+'</b>';
}
function toggleAll(box){ document.querySelectorAll('.tripchk').forEach(function(c){ c.checked=box.checked; }); recalc(); }
recalc();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
