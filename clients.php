<?php
/**
 * TMS — clients.php
 * Add and edit the companies you bill (billing clients) and the shipping lines.
 * Each billing client carries its own pricing rules:
 *   - mechanism: levy (fuel-levy %), markup (increase %) or flat (no % on top)
 *   - the % itself, genset charge, standing rate, free hours
 *   - optional surcharges offered when quoting
 *   - whether a T.O number is mandatory, and which invoice layout to use
 * Route base rates for each client are set in Destinations.
 */
require_once __DIR__ . '/includes/auth.php';
require_role('master', 'admin');

$pageTitle    = 'Clients';
$pageSubtitle = 'Companies you bill';

/* ---------------- Handle actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    /* ---- Save a client (create or update) ---- */
    if ($action === 'save_client') {
        $id   = (int) post('id', 0);
        $name = trim(post('name'));
        $back = 'clients.php' . ($id ? '?edit=' . $id : '');

        if ($name === '') { flash('error', 'A name is required.'); redirect($back); }

        $existing = $id ? db_row("SELECT * FROM clients WHERE id = ?", array($id)) : null;
        if ($id && !$existing) { flash('error', 'That client was not found.'); redirect('clients.php'); }

        $type = $existing ? $existing['client_type'] : (post('client_type') === 'shipping_line' ? 'shipping_line' : 'billing');
        $code = $existing ? $existing['code'] : strtolower(preg_replace('/[^a-z0-9]/i', '', post('code')));
        if ($code === '') { $code = strtolower(substr(preg_replace('/[^a-z0-9]/i', '', $name), 0, 12)); }
        if ($code === '') { flash('error', 'A short code is required (letters/numbers).'); redirect($back); }

        $clash = (int) db_val("SELECT COUNT(*) FROM clients WHERE code = ? AND id <> ?", array($code, $id));
        if ($clash > 0) { flash('error', 'The code “' . $code . '” is already used by another client.'); redirect($back); }

        if ($type === 'billing') {
            $mech = in_array(post('mechanism'), array('levy', 'flat', 'markup'), true) ? post('mechanism') : 'flat';
            $num = function ($k) { $v = clean_number(post($k)); return $v === null ? 0.0 : (float) $v; };
            $rawAdj   = str_replace(array(' ', ','), '', trim(post('adjust_pct')));
            $adjust   = ($mech === 'flat' || !is_numeric($rawAdj)) ? 0.0 : max(-100.0, min(500.0, (float) $rawAdj));
            $genset   = $num('genset_base');
            $standing = $num('standing_rate');
            $free     = $num('free_hours');
            $dual     = $num('dual_flat');
            $toReq    = post('to_required') === '1' ? 1 : 0;
            $layout   = post('invoice_layout') === 'A' ? 'A' : 'B';
        } else {
            $mech = 'none'; $adjust = $genset = $standing = $free = $dual = 0.0; $toReq = 0; $layout = 'B';
        }

        if ($id > 0) {
            dbq("UPDATE clients SET name=?, mechanism=?, adjust_pct=?, genset_base=?, standing_rate=?, free_hours=?,
                        dual_flat=?, to_required=?, invoice_layout=? WHERE id=?",
                array($name, $mech, $adjust, $genset, $standing, $free, $dual, $toReq, $layout, $id));
            $detail = $name;
            if ($existing && $type === 'billing') {
                if ((float) $existing['adjust_pct'] !== (float) $adjust) { $detail .= ' (adjust % ' . $existing['adjust_pct'] . ' → ' . $adjust . ')'; }
                if ($existing['mechanism'] !== $mech)                    { $detail .= ' (mechanism ' . $existing['mechanism'] . ' → ' . $mech . ')'; }
            }
            audit('update', 'client', $id, $detail);
            flash('success', 'Updated “' . $name . '”.');
            redirect('clients.php' . ($type === 'billing' ? '?edit=' . $id : ''));
        } else {
            $newId = db_insert("INSERT INTO clients (name, code, client_type, mechanism, adjust_pct, genset_base, standing_rate,
                                    free_hours, dual_flat, to_required, invoice_layout) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                array($name, $code, $type, $mech, $adjust, $genset, $standing, $free, $dual, $toReq, $layout));
            audit('create', 'client', $newId, $name);
            if ($type === 'billing') {
                flash('success', 'Added “' . $name . '”. Now set its route rates in Destinations' . ' and add any surcharges below.');
                redirect('clients.php?edit=' . $newId);
            }
            flash('success', 'Added shipping line “' . $name . '”.');
            redirect('clients.php');
        }
    }

    /* ---- Activate / deactivate ---- */
    if ($action === 'toggle_client') {
        $id  = (int) post('id', 0);
        $row = db_row("SELECT name, active FROM clients WHERE id = ?", array($id));
        if ($row) {
            $new = ((int) $row['active'] === 1) ? 0 : 1;
            dbq("UPDATE clients SET active = ? WHERE id = ?", array($new, $id));
            audit($new ? 'activate' : 'deactivate', 'client', $id, $row['name']);
            flash('success', '“' . $row['name'] . '” is now ' . ($new ? 'active' : 'inactive') . '.');
        }
        redirect('clients.php');
    }

    /* ---- Surcharges ---- */
    if ($action === 'save_surcharge') {
        $cid   = (int) post('client_id', 0);
        $sid   = (int) post('surcharge_id', 0);
        $label = trim(post('label'));
        $amt   = clean_number(post('amount'));
        if (!db_val("SELECT COUNT(*) FROM clients WHERE id=? AND client_type='billing'", array($cid))) { redirect('clients.php'); }
        if ($label === '' || $amt === null) {
            flash('error', 'A surcharge needs a label and an amount.');
            redirect('clients.php?edit=' . $cid . '#surcharges');
        }
        $adj = post('adjust') === '1' ? 1 : 0;
        if ($sid > 0) {
            dbq("UPDATE client_surcharges SET label=?, amount=?, adjust=? WHERE id=? AND client_id=?", array($label, (float) $amt, $adj, $sid, $cid));
            audit('update', 'client_surcharge', $sid, $label . ' = ' . $amt);
        } else {
            $nid = db_insert("INSERT INTO client_surcharges (client_id, label, amount, adjust) VALUES (?,?,?,?)", array($cid, $label, (float) $amt, $adj));
            audit('create', 'client_surcharge', $nid, $label . ' = ' . $amt);
        }
        flash('success', 'Surcharge saved.');
        redirect('clients.php?edit=' . $cid . '#surcharges');
    }

    if ($action === 'delete_surcharge') {
        $cid = (int) post('client_id', 0);
        $sid = (int) post('surcharge_id', 0);
        dbq("DELETE FROM client_surcharges WHERE id=? AND client_id=?", array($sid, $cid));
        audit('delete', 'client_surcharge', $sid, '');
        flash('success', 'Surcharge removed.');
        redirect('clients.php?edit=' . $cid . '#surcharges');
    }
}

/* ---------------- Load ---------------- */
$editId = (int) get('edit', 0);
$edit   = $editId ? db_row("SELECT * FROM clients WHERE id = ?", array($editId)) : null;
$newType = get('new') === 'line' ? 'shipping_line' : 'billing';

$billing = db_all("SELECT c.*, (SELECT COUNT(*) FROM client_rates r WHERE r.client_id = c.id) AS routes
                   FROM clients c WHERE client_type='billing' ORDER BY active DESC, name");
$lines   = db_all("SELECT * FROM clients WHERE client_type='shipping_line' ORDER BY active DESC, name");
$mechLabel = array('levy' => 'Fuel levy %', 'markup' => 'Markup %', 'flat' => 'Flat rates', 'none' => '—');

$surcharges = ($edit && $edit['client_type'] === 'billing')
    ? db_all("SELECT * FROM client_surcharges WHERE client_id = ? ORDER BY id", array($edit['id'])) : array();
$editSur = null;
if ($edit && get('sur')) {
    foreach ($surcharges as $s) { if ((int) $s['id'] === (int) get('sur')) { $editSur = $s; } }
}

$formType = $edit ? $edit['client_type'] : $newType;
$f = function ($k, $d = '') use ($edit) { return $edit && $edit[$k] !== null ? $edit[$k] : $d; };

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/rates.php">Rates &amp; Costs</a>
  <a href="<?= BASE_URL ?>/clients.php" class="active">Clients</a>
  <a href="<?= BASE_URL ?>/destinations.php">Destinations</a>
  <a href="<?= BASE_URL ?>/empty_rates.php">Empty Rates</a>
  <a href="<?= BASE_URL ?>/driver_rates.php">Driver Pay</a>
</div>

<div class="page-head">
  <div>
    <h1>Clients</h1>
    <p>The companies you bill and the shipping lines. Each billing client has its own pricing rules —
       set its route rates in <a href="<?= BASE_URL ?>/destinations.php">Destinations</a>.</p>
  </div>
  <div class="actions">
    <a href="<?= BASE_URL ?>/clients.php#client-form" class="btn primary">＋ Add billing client</a>
    <a href="<?= BASE_URL ?>/clients.php?new=line#client-form" class="btn ghost">＋ Add shipping line</a>
  </div>
</div>

<!-- Add / edit form -->
<div class="card" id="client-form">
  <h3><?= $edit ? '✎ Edit “' . e($edit['name']) . '”' : ($formType === 'billing' ? '＋ Add a billing client' : '＋ Add a shipping line') ?></h3>
  <form method="post" action="<?= BASE_URL ?>/clients.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_client">
    <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
    <input type="hidden" name="client_type" value="<?= e($formType) ?>">
    <div class="form-grid">
      <div class="form-row">
        <label class="lbl">Name <span class="req">*</span></label>
        <input type="text" name="name" required value="<?= e($f('name')) ?>" placeholder="e.g. Acme Logistics">
      </div>
      <div class="form-row">
        <label class="lbl">Short code</label>
        <input type="text" name="code" value="<?= e($f('code')) ?>" <?= $edit ? 'readonly' : '' ?> maxlength="30" placeholder="e.g. acme">
        <div class="hint"><?= $edit ? 'Fixed once created (used for invoice numbers).' : 'Letters/numbers only. Prefixes invoice numbers. Leave blank to auto-create.' ?></div>
      </div>

      <?php if ($formType === 'billing'): ?>
      <div class="form-row">
        <label class="lbl">Pricing mechanism</label>
        <select name="mechanism" id="mechanism">
          <?php foreach (array('levy' => 'Fuel levy % on top of base rate', 'markup' => 'Markup % on top of base rate', 'flat' => 'Flat — base rate as is') as $k => $lab): ?>
            <option value="<?= $k ?>" <?= $f('mechanism', 'flat') === $k ? 'selected' : '' ?>><?= e($lab) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint">Levy and markup work the same: the % multiplies the rate, genset and any surcharge flagged “apply %”.</div>
      </div>
      <div class="form-row" id="adjust-row">
        <label class="lbl">Levy / markup %</label>
        <div class="field-pct"><input type="number" step="0.01" min="-100" name="adjust_pct" value="<?= e($f('adjust_pct', '0')) ?>"></div>
        <div class="hint">Change this monthly (fuel levy) or annually (rate increase). A negative % lowers the rates (for example a fuel decrease of −3.51). Everything recalculates.</div>
      </div>
      <div class="form-row">
        <label class="lbl">Genset charge (before %)</label>
        <div class="field-money"><input type="number" step="0.01" min="0" name="genset_base" value="<?= e($f('genset_base', '0')) ?>"></div>
      </div>
      <div class="form-row">
        <label class="lbl">Standing rate (R / hour)</label>
        <div class="field-money"><input type="number" step="0.01" min="0" name="standing_rate" value="<?= e($f('standing_rate', '0')) ?>"></div>
      </div>
      <div class="form-row">
        <label class="lbl">Free standing hours</label>
        <input type="number" step="0.5" min="0" name="free_hours" value="<?= e($f('free_hours', '0')) ?>">
      </div>
      <div class="form-row">
        <label class="lbl">Dual on-route flat (R)</label>
        <div class="field-money"><input type="number" step="0.01" min="0" name="dual_flat" value="<?= e($f('dual_flat', '0')) ?>"></div>
      </div>
      <div class="form-row">
        <label class="lbl">Invoice layout</label>
        <select name="invoice_layout">
          <option value="B" <?= $f('invoice_layout', 'B') === 'B' ? 'selected' : '' ?>>Haulier-led (date, haulier, T.O, route, vehicle…)</option>
          <option value="A" <?= $f('invoice_layout', 'B') === 'A' ? 'selected' : '' ?>>Container-led (container no, ISO type, genset…)</option>
        </select>
      </div>
      <div class="form-row">
        <label class="lbl">T.O / order number</label>
        <label class="sur-toggle"><input type="checkbox" name="to_required" value="1" <?= (int) $f('to_required', 0) ? 'checked' : '' ?>> Mandatory when capturing a trip for this client</label>
      </div>
      <?php endif; ?>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn primary"><?= $edit ? 'Save changes' : 'Add' ?></button>
      <?php if ($edit): ?><a href="<?= BASE_URL ?>/clients.php" class="btn ghost">Done</a><?php endif; ?>
      <?php if ($formType === 'billing'): ?><span class="note">New trips pick up changes immediately. Existing trips keep the amounts they were captured with.</span><?php endif; ?>
    </div>
  </form>
</div>

<?php if ($edit && $edit['client_type'] === 'billing'): ?>
<!-- Surcharges -->
<div class="card" id="surcharges">
  <h3>＋ Surcharges for <?= e($edit['name']) ?></h3>
  <p class="sub">Optional add-ons offered when quoting this client (depot, overstay, diversion…). “Apply %” multiplies the amount by the client's levy / markup.</p>
  <?php if ($surcharges): ?>
  <div class="table-wrap" style="border:none;margin-bottom:14px">
    <table class="data">
      <thead><tr><th>Label</th><th class="num">Amount</th><th>Apply %</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($surcharges as $s): ?>
        <tr class="<?= ($editSur && (int) $editSur['id'] === (int) $s['id']) ? 'editing' : '' ?>">
          <td><b><?= e($s['label']) ?></b></td>
          <td class="num"><?= num($s['amount'], 2) ?></td>
          <td><?= (int) $s['adjust'] ? '<span class="badge gold">Yes</span>' : '<span class="muted">No</span>' ?></td>
          <td><div class="row-actions">
            <a class="btn ghost sm" href="<?= BASE_URL ?>/clients.php?edit=<?= (int) $edit['id'] ?>&amp;sur=<?= (int) $s['id'] ?>#surcharges">Edit</a>
            <form method="post" action="<?= BASE_URL ?>/clients.php" style="display:inline" onsubmit="return confirm('Remove this surcharge?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_surcharge">
              <input type="hidden" name="client_id" value="<?= (int) $edit['id'] ?>">
              <input type="hidden" name="surcharge_id" value="<?= (int) $s['id'] ?>">
              <button type="submit" class="btn ghost sm">Remove</button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <form method="post" action="<?= BASE_URL ?>/clients.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_surcharge">
    <input type="hidden" name="client_id" value="<?= (int) $edit['id'] ?>">
    <input type="hidden" name="surcharge_id" value="<?= $editSur ? (int) $editSur['id'] : 0 ?>">
    <div class="form-grid">
      <div class="form-row"><label class="lbl"><?= $editSur ? 'Edit surcharge' : 'New surcharge' ?> — label</label>
        <input type="text" name="label" required value="<?= $editSur ? e($editSur['label']) : '' ?>" placeholder="e.g. Depot surcharge"></div>
      <div class="form-row"><label class="lbl">Amount</label>
        <div class="field-money"><input type="number" step="0.01" min="0" name="amount" required value="<?= $editSur ? e($editSur['amount']) : '' ?>"></div></div>
      <div class="form-row"><label class="lbl">Levy / markup</label>
        <label class="sur-toggle"><input type="checkbox" name="adjust" value="1" <?= ($editSur && (int) $editSur['adjust']) ? 'checked' : '' ?>> Apply the client's %</label></div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn primary"><?= $editSur ? 'Save surcharge' : 'Add surcharge' ?></button>
      <?php if ($editSur): ?><a class="btn ghost" href="<?= BASE_URL ?>/clients.php?edit=<?= (int) $edit['id'] ?>#surcharges">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Billing clients -->
<div class="card">
  <h3>⚑ Billing clients</h3>
  <p class="sub">The companies you invoice. Routes = how many destinations have a rate for them.</p>
  <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Client</th><th>Mechanism</th><th class="num">%</th><th class="num">Genset</th><th class="num">Standing /h</th>
        <th class="num">Free h</th><th class="num">Routes</th><th>Status</th><th style="text-align:right">Actions</th>
      </tr></thead>
      <tbody>
      <?php if (!$billing): ?>
        <tr><td colspan="9" class="muted">No billing clients yet — add one above.</td></tr>
      <?php endif; ?>
      <?php foreach ($billing as $c): ?>
        <tr class="<?= ($editId === (int) $c['id']) ? 'editing' : '' ?>">
          <td><b><?= e($c['name']) ?></b> <span class="muted"><?= e($c['code']) ?></span></td>
          <td><?= e($mechLabel[$c['mechanism']]) ?></td>
          <td class="num"><?= $c['mechanism'] === 'flat' ? '<span class="muted">—</span>' : num($c['adjust_pct'], 2) . '%' ?></td>
          <td class="num"><?= num($c['genset_base'], 2) ?></td>
          <td class="num"><?= num($c['standing_rate'], 2) ?></td>
          <td class="num"><?= num($c['free_hours'], 1) ?></td>
          <td class="num"><?= (int) $c['routes'] ?></td>
          <td><?= (int) $c['active'] ? '<span class="badge good">Active</span>' : '<span class="badge muted">Inactive</span>' ?></td>
          <td><div class="row-actions">
            <a href="<?= BASE_URL ?>/clients.php?edit=<?= (int) $c['id'] ?>#client-form" class="btn ghost sm">Edit</a>
            <form method="post" action="<?= BASE_URL ?>/clients.php" style="display:inline"
                  onsubmit="return confirm('<?= (int) $c['active'] ? 'Deactivate' : 'Reactivate' ?> “<?= e($c['name']) ?>”?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_client">
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button type="submit" class="btn ghost sm"><?= (int) $c['active'] ? 'Deactivate' : 'Reactivate' ?></button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Shipping lines -->
<div class="card">
  <h3>⚓ Shipping lines</h3>
  <p class="sub">Selected on each trip. Deactivating one hides it from new trips; old trips keep it.</p>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Shipping line</th><th>Code</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php if (!$lines): ?><tr><td colspan="4" class="muted">None yet.</td></tr><?php endif; ?>
      <?php foreach ($lines as $c): ?>
        <tr class="<?= ($editId === (int) $c['id']) ? 'editing' : '' ?>">
          <td><b><?= e($c['name']) ?></b></td>
          <td class="muted"><?= e($c['code']) ?></td>
          <td><?= (int) $c['active'] ? '<span class="badge good">Active</span>' : '<span class="badge muted">Inactive</span>' ?></td>
          <td><div class="row-actions">
            <a href="<?= BASE_URL ?>/clients.php?edit=<?= (int) $c['id'] ?>#client-form" class="btn ghost sm">Rename</a>
            <form method="post" action="<?= BASE_URL ?>/clients.php" style="display:inline"
                  onsubmit="return confirm('<?= (int) $c['active'] ? 'Deactivate' : 'Reactivate' ?> “<?= e($c['name']) ?>”?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_client">
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button type="submit" class="btn ghost sm"><?= (int) $c['active'] ? 'Deactivate' : 'Reactivate' ?></button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  var m = document.getElementById('mechanism'), row = document.getElementById('adjust-row');
  if (!m || !row) return;
  function sync(){ row.style.display = (m.value === 'flat') ? 'none' : ''; }
  m.addEventListener('change', sync); sync();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
