<?php
/**
 * TMS — destinations.php
 * Manage the route rate table: per-client base rates + km.
 * Add new routes, edit rates, or deactivate ones no longer used.
 */
require_once __DIR__ . '/includes/auth.php';
require_role('master', 'admin');

$pageTitle    = 'Destinations';
$pageSubtitle = 'Route rate table';

/* ---------------- Handle actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'save_dest') {
        $id   = (int) post('id', 0);
        $name = post('name');
        $km   = (int) post('km', 0);

        if ($name === '') {
            flash('error', 'A destination name is required.');
            redirect('destinations.php' . ($id ? '?edit=' . $id : ''));
        }

        // Unique name guard (ignore self when editing).
        $clash = db_val("SELECT COUNT(*) FROM destinations WHERE name = ? AND id <> ?", array($name, $id));
        if ((int) $clash > 0) {
            flash('error', 'A destination called “' . $name . '” already exists.');
            redirect('destinations.php' . ($id ? '?edit=' . $id : ''));
        }

        if ($id > 0) {
            dbq("UPDATE destinations SET name=?, km=? WHERE id=?", array($name, $km, $id));
            audit('update', 'destination', $id, $name);
            flash('success', 'Updated “' . $name . '”.');
        } else {
            $id = db_insert("INSERT INTO destinations (name, km) VALUES (?,?)", array($name, $km));
            audit('create', 'destination', $id, $name);
            flash('success', 'Added “' . $name . '” to the rate table.');
        }
        // Per-client base rates: blank = client does not serve this route.
        $posted = isset($_POST['rate']) && is_array($_POST['rate']) ? $_POST['rate'] : array();
        foreach (db_all("SELECT id FROM clients WHERE client_type='billing'") as $c) {
            $cid = (int) $c['id'];
            $val = isset($posted[$cid]) ? clean_number($posted[$cid]) : null;
            if ($val === null) {
                dbq("DELETE FROM client_rates WHERE client_id=? AND destination_id=?", array($cid, $id));
            } else {
                dbq("INSERT INTO client_rates (client_id, destination_id, base_rate) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE base_rate = VALUES(base_rate)", array($cid, $id, (float) $val));
            }
        }
        redirect('destinations.php');
    }

    /* ---- Quick edit: change many rates / km in the table at once ---- */
    if ($action === 'save_grid') {
        $kms   = isset($_POST['km'])   && is_array($_POST['km'])   ? $_POST['km']   : array();
        $rates = isset($_POST['grid']) && is_array($_POST['grid']) ? $_POST['grid'] : array();
        $billingIds = array();
        foreach (db_all("SELECT id FROM clients WHERE client_type='billing'") as $c) { $billingIds[] = (int) $c['id']; }
        $changed = 0;
        foreach ($kms as $did => $kmv) {
            $did = (int) $did;
            if (!db_val("SELECT COUNT(*) FROM destinations WHERE id=?", array($did))) continue;
            $newKm = max(0, (int) $kmv);
            if ((int) db_val("SELECT km FROM destinations WHERE id=?", array($did)) !== $newKm) {
                dbq("UPDATE destinations SET km=? WHERE id=?", array($newKm, $did)); $changed++;
            }
            foreach ($billingIds as $cid) {
                if (!isset($rates[$did]) || !array_key_exists($cid, $rates[$did])) continue;
                $val = clean_number($rates[$did][$cid]);
                $old = db_val("SELECT base_rate FROM client_rates WHERE client_id=? AND destination_id=?", array($cid, $did));
                if ($val === null) {
                    if ($old !== null) { dbq("DELETE FROM client_rates WHERE client_id=? AND destination_id=?", array($cid, $did)); $changed++; }
                } elseif ($old === null || (float) $old !== (float) $val) {
                    dbq("INSERT INTO client_rates (client_id, destination_id, base_rate) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE base_rate = VALUES(base_rate)", array($cid, $did, (float) $val));
                    $changed++;
                }
            }
        }
        if ($changed) { audit('update', 'destinations', null, $changed . ' value(s) changed in the rate grid'); }
        flash($changed ? 'success' : 'info', $changed ? $changed . ' value(s) updated.' : 'No changes were made.');
        redirect('destinations.php?grid=1');
    }

    /* ---- Annual rate letter: raise or cut one client's base rates by a % ---- */
    if ($action === 'bulk_adjust') {
        $cid = (int) post('client_id', 0);
        $pct = clean_number(str_replace('-', '', post('pct')));
        $neg = strpos(post('pct'), '-') !== false;
        $cl  = db_row("SELECT name FROM clients WHERE id=? AND client_type='billing'", array($cid));
        if (!$cl || $pct === null || (float) $pct <= 0 || (float) $pct > 100) {
            flash('error', 'Pick a client and enter a percentage between 0 and 100.');
            redirect('destinations.php');
        }
        $factor = 1 + ($neg ? -1 : 1) * ((float) $pct) / 100;
        $n = dbq("UPDATE client_rates SET base_rate = ROUND(base_rate * ?, 2) WHERE client_id = ?", array($factor, $cid))->rowCount();
        audit('update', 'client_rates', $cid, $cl['name'] . ' base rates ' . ($neg ? '-' : '+') . $pct . '% (' . $n . ' routes)');
        flash('success', $cl['name'] . ': ' . $n . ' base rate' . ($n === 1 ? '' : 's') . ' ' . ($neg ? 'reduced' : 'increased') . ' by ' . $pct . '%.');
        redirect('destinations.php');
    }

    if ($action === 'toggle_dest') {
        $id = (int) post('id', 0);
        $row = db_row("SELECT name, active FROM destinations WHERE id = ?", array($id));
        if ($row) {
            $newState = ((int) $row['active'] === 1) ? 0 : 1;
            dbq("UPDATE destinations SET active = ? WHERE id = ?", array($newState, $id));
            audit($newState ? 'activate' : 'deactivate', 'destination', $id, $row['name']);
            flash('success', '“' . $row['name'] . '” is now ' . ($newState ? 'active' : 'inactive') . '.');
        }
        redirect('destinations.php');
    }
}

/* ---------------- Edit mode ---------------- */
$editId  = (int) get('edit', 0);
$edit    = $editId ? db_row("SELECT * FROM destinations WHERE id = ?", array($editId)) : null;
$gridMode = get('grid') === '1';

/* ---------------- Load list ---------------- */
$clients = db_all("SELECT id, name, code, active FROM clients WHERE client_type='billing' ORDER BY active DESC, name");
$rates = array();   // [destination_id][client_id] => base_rate
foreach (db_all("SELECT client_id, destination_id, base_rate FROM client_rates") as $r) {
    $rates[(int) $r['destination_id']][(int) $r['client_id']] = $r['base_rate'];
}
$rows     = db_all("SELECT * FROM destinations ORDER BY km ASC, name ASC");
$total    = count($rows);
$active   = 0;
foreach ($rows as $r) { if ((int) $r['active'] === 1) { $active++; } }

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/rates.php">Rates &amp; Costs</a>
  <a href="<?= BASE_URL ?>/clients.php">Clients</a>
  <a href="<?= BASE_URL ?>/destinations.php" class="active">Destinations</a>
  <a href="<?= BASE_URL ?>/empty_rates.php">Empty Rates</a>
  <a href="<?= BASE_URL ?>/driver_rates.php">Driver Pay</a>
</div>

<div class="page-head">
  <div>
    <h1>Destinations</h1>
    <p><?= $active ?> active of <?= $total ?> routes. Each client has its own base rate per route — leave it blank if that client doesn't serve the route.</p>
  </div>
  <div class="actions">
    <?php if ($gridMode): ?>
      <a href="<?= BASE_URL ?>/destinations.php" class="btn ghost">← Normal view</a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/destinations.php?grid=1" class="btn ghost">✎ Quick-edit all rates</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$clients): ?>
  <div class="card"><div class="empty"><div class="big">⚑</div>Add a billing client first in <a href="<?= BASE_URL ?>/clients.php">Clients</a>, then set its route rates here.</div></div>
<?php endif; ?>

<?php if ($gridMode): ?>
<!-- Quick-edit grid: every route and every client rate editable in one go -->
<form method="post" action="<?= BASE_URL ?>/destinations.php">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_grid">
  <div class="card">
    <h3>✎ Quick-edit all rates</h3>
    <p class="sub">Change any km or base rate and press Save. Clear a rate to remove that client from the route.</p>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Destination</th><th class="num">km</th>
          <?php foreach ($clients as $c): ?><th class="num"><?= e($c['name']) ?><?= (int) $c['active'] ? '' : ' (inactive)' ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $did = (int) $r['id']; ?>
          <tr>
            <td><b><?= e($r['name']) ?></b><?= (int) $r['active'] ? '' : ' <span class="badge muted">Inactive</span>' ?></td>
            <td class="num"><input type="number" min="0" name="km[<?= $did ?>]" value="<?= (int) $r['km'] ?>" style="width:84px"></td>
            <?php foreach ($clients as $c): $cid = (int) $c['id']; ?>
              <td class="num"><input type="number" step="0.01" min="0" style="width:112px"
                  name="grid[<?= $did ?>][<?= $cid ?>]" value="<?= isset($rates[$did][$cid]) ? e($rates[$did][$cid]) : '' ?>" placeholder="—"></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card" style="position:sticky;bottom:0;margin-bottom:0">
    <div class="form-actions" style="border-top:none;padding-top:0;margin-top:0">
      <button type="submit" class="btn primary">Save all changes</button>
      <span class="note">Every change is written to the audit log.</span>
    </div>
  </div>
</form>
<?php else: ?>

<!-- Add / Edit form -->
<div class="card" id="dest-form">
  <h3><?= $edit ? '✎ Edit “' . e($edit['name']) . '”' : '＋ Add a destination' ?></h3>
  <form method="post" action="<?= BASE_URL ?>/destinations.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_dest">
    <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
    <div class="form-grid">
      <div class="form-row">
        <label class="lbl">Destination name <span class="req">*</span></label>
        <input type="text" name="name" required value="<?= $edit ? e($edit['name']) : '' ?>" placeholder="e.g. Worcester">
      </div>
      <div class="form-row">
        <label class="lbl">Distance (km)</label>
        <input type="number" name="km" min="0" value="<?= $edit ? (int) $edit['km'] : '' ?>" placeholder="244">
      </div>
      <?php foreach ($clients as $c): $cid = (int) $c['id']; ?>
      <div class="form-row">
        <label class="lbl"><?= e($c['name']) ?> base rate</label>
        <div class="field-money"><input type="number" step="0.01" min="0" name="rate[<?= $cid ?>]"
          value="<?= ($edit && isset($rates[(int) $edit['id']][$cid])) ? e($rates[(int) $edit['id']][$cid]) : '' ?>" placeholder="blank = not served"></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn primary"><?= $edit ? 'Save changes' : 'Add destination' ?></button>
      <?php if ($edit): ?>
        <a href="<?= BASE_URL ?>/destinations.php" class="btn ghost">Cancel</a>
      <?php endif; ?>
      <span class="note">Base rates are the engine default — they stay editable per trip when quoting.</span>
    </div>
  </form>
</div>

<?php if ($clients): ?>
<!-- Rate-letter helper -->
<div class="card">
  <h3>％ Apply a rate change to one client</h3>
  <p class="sub">For rate letters: raise (or cut, with a minus sign) every base rate of one client by a percentage.</p>
  <form method="post" action="<?= BASE_URL ?>/destinations.php" class="form-grid"
        onsubmit="return confirm('Change ALL base rates of this client by this percentage? This updates every route.');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_adjust">
    <div class="form-row"><label class="lbl">Client</label>
      <select name="client_id" required><?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-row"><label class="lbl">Change by (%)</label>
      <div class="field-pct"><input type="text" name="pct" required placeholder="e.g. 7.5 or -3"></div></div>
    <div class="form-row"><label class="lbl">&nbsp;</label><button type="submit" class="btn ghost">Apply to all routes</button></div>
  </form>
</div>
<?php endif; ?>

<!-- Rate table -->
<div class="card">
  <h3>◎ Route rate table</h3>
  <p class="sub">Sorted by distance. “—” means that client does not serve the route. Rates shown are base rates (before any levy / markup).</p>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Destination</th>
          <th class="num">km</th>
          <?php foreach ($clients as $c): ?><th class="num"><?= e($c['name']) ?></th><?php endforeach; ?>
          <th>Status</th>
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): $did = (int) $r['id']; ?>
          <tr class="<?= ($editId === $did) ? 'editing' : '' ?>">
            <td><b><?= e($r['name']) ?></b></td>
            <td class="num"><?= num($r['km']) ?></td>
            <?php foreach ($clients as $c): $cid = (int) $c['id']; ?>
              <td class="num"><?= isset($rates[$did][$cid]) ? num($rates[$did][$cid], 2) : '<span class="muted">—</span>' ?></td>
            <?php endforeach; ?>
            <td>
              <?php if ((int) $r['active'] === 1): ?>
                <span class="badge good">Active</span>
              <?php else: ?>
                <span class="badge muted">Inactive</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="row-actions">
                <a href="<?= BASE_URL ?>/destinations.php?edit=<?= $did ?>#dest-form" class="btn ghost sm">Edit</a>
                <form method="post" action="<?= BASE_URL ?>/destinations.php" style="display:inline"
                      onsubmit="return confirm('<?= (int)$r['active']===1 ? 'Deactivate' : 'Reactivate' ?> “<?= e($r['name']) ?>”?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_dest">
                  <input type="hidden" name="id" value="<?= $did ?>">
                  <button type="submit" class="btn ghost sm">
                    <?= (int) $r['active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
