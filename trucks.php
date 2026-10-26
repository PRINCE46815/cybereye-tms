<?php
/**
 * TMS — trucks.php
 */
require_once __DIR__ . '/includes/auth.php';
require_role('master', 'admin');

$pageTitle    = 'Trucks';
$pageSubtitle = 'Fleet & people';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'save_truck') {
        $id  = (int) post('id', 0);
        $reg = post('registration');
        if ($reg === '') { flash('error', 'A registration is required.'); redirect('trucks.php' . ($id ? '?edit=' . $id : '')); }

        $owner = post('owner_type') === 'subcontractor' ? 'subcontractor' : 'own';
        $subId = (int) post('subcontractor_id', 0);
        $subId = ($owner === 'subcontractor' && $subId > 0) ? $subId : null;
        $odo   = clean_number(post('odometer')); $odo = $odo !== null ? (int) $odo : 0;
        $licE  = post('licence_expiry') ?: null;
        $insE  = post('insurance_expiry') ?: null;
        $svcE  = post('service_expiry') ?: null;

        // uniqueness of reg
        $clash = db_val("SELECT COUNT(*) FROM trucks WHERE registration = ? AND id <> ?", array($reg, $id));
        if ((int) $clash > 0) { flash('error', 'A truck with registration ' . $reg . ' already exists.'); redirect('trucks.php' . ($id ? '?edit=' . $id : '')); }

        if ($id > 0) {
            dbq("UPDATE trucks SET registration=?, truck_type=?, owner_type=?, subcontractor_id=?, size_capability=?, has_genset=?, odometer=?, licence_expiry=?, insurance_expiry=?, service_expiry=? WHERE id=?",
                array($reg, post('truck_type'), $owner, $subId, post('size_capability'), post('has_genset')==='1'?1:0, $odo, $licE, $insE, $svcE, $id));
            audit('update', 'truck', $id, $reg);
            flash('success', 'Updated ' . $reg . '.');
        } else {
            $newId = db_insert("INSERT INTO trucks (registration, truck_type, owner_type, subcontractor_id, size_capability, has_genset, odometer, licence_expiry, insurance_expiry, service_expiry) VALUES (?,?,?,?,?,?,?,?,?,?)",
                array($reg, post('truck_type'), $owner, $subId, post('size_capability'), post('has_genset')==='1'?1:0, $odo, $licE, $insE, $svcE));
            audit('create', 'truck', $newId, $reg);
            flash('success', 'Added ' . $reg . '.');
        }
        redirect('trucks.php');
    }

    if ($action === 'toggle_truck') {
        $id = (int) post('id', 0);
        $row = db_row("SELECT registration, status FROM trucks WHERE id = ?", array($id));
        if ($row) {
            $new = $row['status'] === 'active' ? 'inactive' : 'active';
            dbq("UPDATE trucks SET status = ? WHERE id = ?", array($new, $id));
            flash('success', $row['registration'] . ' is now ' . $new . '.');
        }
        redirect('trucks.php');
    }
}

$editId = (int) get('edit', 0);
$edit   = $editId ? db_row("SELECT * FROM trucks WHERE id = ?", array($editId)) : null;
$subs   = db_all("SELECT id, name FROM subcontractors WHERE status='active' ORDER BY name");
$rows   = db_all("SELECT t.*, s.name AS sub_name FROM trucks t LEFT JOIN subcontractors s ON s.id=t.subcontractor_id ORDER BY t.owner_type, t.registration ASC");
$alertDays = (int) setting('expiry_alert_days', 30);

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/trucks.php" class="active">Trucks</a>
  <a href="<?= BASE_URL ?>/crane.php">Crane Truck</a>
</div>


<div class="subnav">
  <a href="<?= BASE_URL ?>/trucks.php" class="active">Trucks</a>
  <a href="<?= BASE_URL ?>/drivers.php">Drivers</a>
  <a href="<?= BASE_URL ?>/subcontractors.php">Subcontractors</a>
</div>

<div class="page-head">
  <div><h1>Trucks</h1><p><?= count($rows) ?> on record. 6×4 = full + empty (10% commission); 6×2 = empty-only (4%).</p></div>
</div>

<div class="card" id="truck-form">
  <h3><?= $edit ? '✎ Edit ' . e($edit['registration']) : '＋ Add a truck' ?></h3>
  <form method="post" action="<?= BASE_URL ?>/trucks.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_truck">
    <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
    <div class="form-grid">
      <div class="form-row"><label class="lbl">Registration <span class="req">*</span></label>
        <input type="text" name="registration" required value="<?= $edit ? e($edit['registration']) : '' ?>" placeholder="e.g. MC08RKGP"></div>
      <div class="form-row"><label class="lbl">Truck type</label>
        <select name="truck_type">
          <option value="6x4" <?= ($edit && $edit['truck_type']==='6x4') ? 'selected' : '' ?>>6×4 fullbox (full + empty)</option>
          <option value="6x2" <?= ($edit && $edit['truck_type']==='6x2') ? 'selected' : '' ?>>6×2 empty-only</option>
        </select></div>
      <div class="form-row"><label class="lbl">Owner</label>
        <select name="owner_type" id="owner_type" onchange="document.getElementById('sub-wrap').style.display=this.value==='subcontractor'?'block':'none'">
          <option value="own" <?= ($edit && $edit['owner_type']==='own') ? 'selected' : '' ?>>Own fleet</option>
          <option value="subcontractor" <?= ($edit && $edit['owner_type']==='subcontractor') ? 'selected' : '' ?>>Subcontractor</option>
        </select></div>
      <div class="form-row" id="sub-wrap" style="display:<?= ($edit && $edit['owner_type']==='subcontractor') ? 'block' : 'none' ?>">
        <label class="lbl">Which subcontractor</label>
        <select name="subcontractor_id"><option value="0">— choose —</option>
          <?php foreach ($subs as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= ($edit && (int)$edit['subcontractor_id']===(int)$s['id']) ? 'selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="form-row"><label class="lbl">Size capability</label>
        <select name="size_capability">
          <option value="both" <?= (!$edit || $edit['size_capability']==='both') ? 'selected' : '' ?>>Both 6m & 12m</option>
          <option value="6m" <?= ($edit && $edit['size_capability']==='6m') ? 'selected' : '' ?>>6m only</option>
          <option value="12m" <?= ($edit && $edit['size_capability']==='12m') ? 'selected' : '' ?>>12m only</option>
        </select></div>
      <div class="form-row"><label class="lbl">Odometer (km)</label>
        <input type="number" name="odometer" min="0" value="<?= $edit ? (int) $edit['odometer'] : '0' ?>"></div>
      <div class="form-row"><label class="lbl">Licence expiry</label>
        <input type="date" name="licence_expiry" value="<?= $edit ? e($edit['licence_expiry']) : '' ?>"></div>
      <div class="form-row"><label class="lbl">Insurance expiry</label>
        <input type="date" name="insurance_expiry" value="<?= $edit ? e($edit['insurance_expiry']) : '' ?>"></div>
      <div class="form-row"><label class="lbl">Next service</label>
        <input type="date" name="service_expiry" value="<?= $edit ? e($edit['service_expiry']) : '' ?>"></div>
    </div>
    <div class="flex" style="gap:22px;margin:8px 0 16px">
      <label class="sur-toggle"><input type="checkbox" name="has_genset" value="1" <?= ($edit && $edit['has_genset']) ? 'checked' : '' ?>> Has genset (reefer capable)</label>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn primary"><?= $edit ? 'Save changes' : 'Add truck' ?></button>
      <?php if ($edit): ?><a href="<?= BASE_URL ?>/trucks.php" class="btn ghost">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h3>⛟ Trucks</h3>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Reg</th><th>Type</th><th>Owner</th><th>Genset</th><th>Licence</th><th>Service</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8"><div class="empty"><div class="big">⛟</div>No trucks yet.</div></td></tr>
        <?php else: foreach ($rows as $r):
          $licSoon = $r['licence_expiry'] && strtotime($r['licence_expiry']) < strtotime("+$alertDays days");
          $svcSoon = $r['service_expiry'] && strtotime($r['service_expiry']) < strtotime("+$alertDays days");
        ?>
          <tr class="<?= ($editId === (int) $r['id']) ? 'editing' : '' ?>">
            <td><a href="<?= BASE_URL ?>/truck_view.php?id=<?= (int) $r['id'] ?>"><b><?= e($r['registration']) ?></b></a></td>
            <td><span class="badge muted"><?= e($r['truck_type']) ?></span></td>
            <td><?= $r['owner_type']==='own' ? '<span class="badge gold">Own</span>' : e($r['sub_name'] ? $r['sub_name'] : 'Subbie') ?></td>
            <td><?= $r['has_genset'] ? '<span class="badge info">Genset</span>' : '<span class="muted">—</span>' ?></td>
            <td><?= fdate($r['licence_expiry']) ?><?= $licSoon ? ' <span class="badge warn">soon</span>' : '' ?></td>
            <td><?= fdate($r['service_expiry']) ?><?= $svcSoon ? ' <span class="badge warn">soon</span>' : '' ?></td>
            <td><?= $r['status'] === 'active' ? '<span class="badge good">Active</span>' : '<span class="badge muted">Inactive</span>' ?></td>
            <td><div class="row-actions">
              <a href="<?= BASE_URL ?>/trucks.php?edit=<?= (int) $r['id'] ?>#truck-form" class="btn ghost sm">Edit</a>
              <form method="post" action="<?= BASE_URL ?>/trucks.php" style="display:inline" onsubmit="return confirm('Toggle status?');">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle_truck"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button type="submit" class="btn ghost sm"><?= $r['status'] === 'active' ? 'Off' : 'On' ?></button>
              </form>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
