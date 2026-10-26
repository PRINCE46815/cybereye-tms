<?php
/**
 * TMS — driver_rates.php
 * Manage the driver pay rate book: route + container size -> amount.
 */
require_once __DIR__ . '/includes/auth.php';
require_role('master', 'admin');

$pageTitle    = 'Driver Pay Rates';
$pageSubtitle = 'Fleet & people';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'save_rate') {
        $id    = (int) post('id', 0);
        $route = strtoupper(trim(post('route')));
        $size  = post('container_size') === '6m' ? '6m' : '12m';
        $amt   = clean_number(post('amount')); $amt = $amt !== null ? (float) $amt : 0;
        if ($route === '') { flash('error', 'A route is required.'); redirect('driver_rates.php' . ($id ? '?edit=' . $id : '')); }

        $clash = db_val("SELECT COUNT(*) FROM driver_pay_rates WHERE route=? AND container_size=? AND id<>?", array($route, $size, $id));
        if ((int) $clash > 0) { flash('error', $route . ' (' . $size . ') already exists.'); redirect('driver_rates.php' . ($id ? '?edit=' . $id : '')); }

        if ($id > 0) {
            dbq("UPDATE driver_pay_rates SET route=?, container_size=?, amount=?, notes=? WHERE id=?",
                array($route, $size, $amt, post('notes'), $id));
            audit('update', 'driver_pay_rate', $id, $route);
            flash('success', 'Updated ' . $route . '.');
        } else {
            $newId = db_insert("INSERT INTO driver_pay_rates (route, container_size, amount, notes, created_by) VALUES (?,?,?,?,?)",
                array($route, $size, $amt, post('notes'), $me['id']));
            audit('create', 'driver_pay_rate', $newId, $route);
            flash('success', 'Added ' . $route . '.');
        }
        redirect('driver_rates.php');
    }

    /* ---- Quick edit: change many amounts at once ---- */
    if ($action === 'save_grid') {
        $amounts = isset($_POST['amount']) && is_array($_POST['amount']) ? $_POST['amount'] : array();
        $changed = 0;
        foreach ($amounts as $rid => $val) {
            $rid = (int) $rid;
            $new = clean_number($val);
            if ($new === null) continue;
            $old = db_val("SELECT amount FROM driver_pay_rates WHERE id=?", array($rid));
            if ($old === null || (float) $old === (float) $new) continue;
            dbq("UPDATE driver_pay_rates SET amount=? WHERE id=?", array((float) $new, $rid));
            $changed++;
        }
        if ($changed) { audit('update', 'driver_pay_rates', null, $changed . ' driver pay rate(s) changed in the grid'); }
        flash($changed ? 'success' : 'info', $changed ? $changed . ' driver pay rate(s) updated.' : 'No changes were made.');
        redirect('driver_rates.php?grid=1');
    }

    /* ---- Raise or cut every active rate by a % (optionally one container size) ---- */
    if ($action === 'bulk_adjust') {
        $raw  = post('pct');
        $neg  = strpos($raw, '-') !== false;
        $pct  = clean_number(str_replace('-', '', $raw));
        $size = in_array(post('size'), array('6m', '12m'), true) ? post('size') : '';
        if ($pct === null || (float) $pct <= 0 || (float) $pct > 100) {
            flash('error', 'Enter a percentage between 0 and 100 (use a minus sign to reduce).');
            redirect('driver_rates.php');
        }
        $factor = 1 + ($neg ? -1 : 1) * ((float) $pct) / 100;
        $sql = "UPDATE driver_pay_rates SET amount = ROUND(amount * ?, 2) WHERE active = 1" . ($size ? " AND container_size = ?" : "");
        $n = dbq($sql, $size ? array($factor, $size) : array($factor))->rowCount();
        audit('update', 'driver_pay_rates', null, ($neg ? '-' : '+') . $pct . '% on ' . $n . ' rate(s)' . ($size ? ' (' . $size . ')' : ''));
        flash('success', $n . ' driver pay rate' . ($n === 1 ? '' : 's') . ' ' . ($neg ? 'reduced' : 'increased') . ' by ' . $pct . '%.');
        redirect('driver_rates.php');
    }

    if ($action === 'delete_rate') {
        $id  = (int) post('id', 0);
        $row = db_row("SELECT route, container_size FROM driver_pay_rates WHERE id=?", array($id));
        if ($row) {
            dbq("DELETE FROM driver_pay_rates WHERE id=?", array($id));
            audit('delete', 'driver_pay_rate', $id, $row['route'] . ' ' . $row['container_size']);
            flash('success', 'Removed ' . $row['route'] . ' (' . $row['container_size'] . '). Trips already captured keep the pay they were given.');
        }
        redirect('driver_rates.php');
    }

    if ($action === 'toggle_rate') {
        $id = (int) post('id', 0);
        $row = db_row("SELECT route, active FROM driver_pay_rates WHERE id=?", array($id));
        if ($row) { dbq("UPDATE driver_pay_rates SET active=? WHERE id=?", array($row['active'] ? 0 : 1, $id)); flash('success', $row['route'] . ' toggled.'); }
        redirect('driver_rates.php');
    }
}

$editId = (int) get('edit', 0);
$edit   = $editId ? db_row("SELECT * FROM driver_pay_rates WHERE id=?", array($editId)) : null;
$rows   = db_all("SELECT * FROM driver_pay_rates ORDER BY route ASC, container_size ASC");
$gridMode = get('grid') === '1';

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/trucks.php">Trucks</a>
  <a href="<?= BASE_URL ?>/drivers.php">Drivers</a>
  <a href="<?= BASE_URL ?>/subcontractors.php">Subcontractors</a>
  <a href="<?= BASE_URL ?>/driver_rates.php" class="active">Driver Pay</a>
</div>

<div class="page-head">
  <div><h1>Driver Pay Rates</h1><p><?= count($rows) ?> rates. What a driver earns per route — applied automatically when you capture a trip.</p></div>
  <div class="actions">
    <?php if ($gridMode): ?><a href="<?= BASE_URL ?>/driver_rates.php" class="btn ghost">← Normal view</a>
    <?php else: ?><a href="<?= BASE_URL ?>/driver_rates.php?grid=1" class="btn ghost">✎ Quick-edit all rates</a><?php endif; ?>
  </div>
</div>

<?php if ($gridMode): ?>
<form method="post" action="<?= BASE_URL ?>/driver_rates.php">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_grid">
  <div class="card">
    <h3>✎ Quick-edit driver pay</h3>
    <p class="sub">Change any amount and press Save. Changes apply to trips captured from now on; trips already captured keep their pay.</p>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Route</th><th>Size</th><th class="num">Driver pay (R)</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><b><?= e($r['route']) ?></b></td>
            <td><span class="badge muted"><?= e($r['container_size']) ?></span></td>
            <td class="num"><input type="number" step="0.01" min="0" style="width:120px" name="amount[<?= (int) $r['id'] ?>]" value="<?= e($r['amount']) ?>"></td>
            <td><?= $r['active'] ? '<span class="badge good">Active</span>' : '<span class="badge muted">Off</span>' ?></td>
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
<?php require_once __DIR__ . '/includes/footer.php'; return; endif; ?>

<div class="card" id="rate-form">
  <h3><?= $edit ? '✎ Edit ' . e($edit['route']) : '＋ Add a driver pay rate' ?></h3>
  <form method="post" action="<?= BASE_URL ?>/driver_rates.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_rate">
    <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
    <div class="form-grid">
      <div class="form-row"><label class="lbl">Route <span class="req">*</span></label>
        <input type="text" name="route" required value="<?= $edit ? e($edit['route']) : '' ?>" placeholder="e.g. WORCESTER or BOLSTER - PORT"></div>
      <div class="form-row"><label class="lbl">Container size</label>
        <select name="container_size">
          <option value="12m" <?= (!$edit || $edit['container_size']==='12m') ? 'selected' : '' ?>>12m (40ft)</option>
          <option value="6m" <?= ($edit && $edit['container_size']==='6m') ? 'selected' : '' ?>>6m (20ft)</option>
        </select></div>
      <div class="form-row"><label class="lbl">Driver pay (R)</label>
        <div class="field-money"><input type="number" step="0.01" min="0" name="amount" required value="<?= $edit ? e($edit['amount']) : '' ?>"></div></div>
      <div class="form-row"><label class="lbl">Notes</label>
        <input type="text" name="notes" value="<?= $edit ? e($edit['notes']) : '' ?>"></div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn primary"><?= $edit ? 'Save changes' : 'Add rate' ?></button>
      <?php if ($edit): ?><a href="<?= BASE_URL ?>/driver_rates.php" class="btn ghost">Cancel</a><?php endif; ?>
      <span class="note">Same route can have separate 6m and 12m rates.</span>
    </div>
  </form>
</div>

<div class="card">
  <h3>％ Change all driver rates at once</h3>
  <p class="sub">Raise (or cut, with a minus sign) every active driver pay rate by a percentage — all sizes, or just one.</p>
  <form method="post" action="<?= BASE_URL ?>/driver_rates.php" class="form-grid"
        onsubmit="return confirm('Change ALL active driver pay rates by this percentage?');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_adjust">
    <div class="form-row"><label class="lbl">Change by (%)</label>
      <div class="field-pct"><input type="text" name="pct" required placeholder="e.g. 5 or -2.5"></div></div>
    <div class="form-row"><label class="lbl">Container size</label>
      <select name="size"><option value="">All sizes</option><option value="12m">12m only</option><option value="6m">6m only</option></select></div>
    <div class="form-row"><label class="lbl">&nbsp;</label><button type="submit" class="btn ghost">Apply to all</button></div>
  </form>
</div>

<div class="card">
  <h3>⛁ Driver pay book</h3>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Route</th><th>Size</th><th class="num">Driver pay</th><th>Notes</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6"><div class="empty"><div class="big">⛁</div>No driver pay rates yet.</div></td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr class="<?= ($editId === (int) $r['id']) ? 'editing' : '' ?>">
            <td><b><?= e($r['route']) ?></b></td>
            <td><span class="badge muted"><?= e($r['container_size']) ?></span></td>
            <td class="num"><b><?= money($r['amount']) ?></b></td>
            <td class="muted"><?= e($r['notes'] ? $r['notes'] : '—') ?></td>
            <td><?= $r['active'] ? '<span class="badge good">Active</span>' : '<span class="badge muted">Off</span>' ?></td>
            <td><div class="row-actions">
              <a href="<?= BASE_URL ?>/driver_rates.php?edit=<?= (int) $r['id'] ?>#rate-form" class="btn ghost sm">Edit</a>
              <form method="post" action="<?= BASE_URL ?>/driver_rates.php" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle_rate"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn ghost sm"><?= $r['active'] ? 'Off' : 'On' ?></button>
              </form>
              <form method="post" action="<?= BASE_URL ?>/driver_rates.php" style="display:inline" onsubmit="return confirm('Remove this rate for good?');">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete_rate"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn ghost sm">Remove</button>
              </form>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
