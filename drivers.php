<?php
/**
 * TMS — drivers.php
 */
require_once __DIR__ . '/includes/auth.php';
require_role('master', 'admin');

$pageTitle    = 'Drivers';
$pageSubtitle = 'Fleet & people';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'save_driver') {
        $id   = (int) post('id', 0);
        $name = post('name');
        if ($name === '') { flash('error', 'A driver name is required.'); redirect('drivers.php' . ($id ? '?edit=' . $id : '')); }
        $subId   = (int) post('subcontractor_id', 0);
        $subId   = $subId > 0 ? $subId : null;
        $licExp  = post('licence_expiry'); $licExp = $licExp !== '' ? $licExp : null;
        $pay     = clean_number(post('pay_per_trip')); $pay = $pay !== null ? $pay : 0;

        if ($id > 0) {
            dbq("UPDATE drivers SET name=?, licence_no=?, licence_expiry=?, phone=?, subcontractor_id=?, pay_per_trip=?, bank_name=?, account_no=?, branch_code=?, account_holder=?, expense_capture=?, pay_visibility=? WHERE id=?",
                array($name, post('licence_no'), $licExp, post('phone'), $subId, $pay, post('bank_name'), post('account_no'), post('branch_code'), post('account_holder'), post('expense_capture')==='1'?1:0, post('pay_visibility')==='1'?1:0, $id));
            audit('update', 'driver', $id, $name);
            flash('success', 'Updated ' . $name . '.');
        } else {
            $newId = db_insert("INSERT INTO drivers (name, licence_no, licence_expiry, phone, subcontractor_id, pay_per_trip, bank_name, account_no, branch_code, account_holder, expense_capture, pay_visibility) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                array($name, post('licence_no'), $licExp, post('phone'), $subId, $pay, post('bank_name'), post('account_no'), post('branch_code'), post('account_holder'), post('expense_capture')==='1'?1:0, post('pay_visibility')==='1'?1:0));
            audit('create', 'driver', $newId, $name);
            flash('success', 'Added ' . $name . '.');
        }
        redirect('drivers.php');
    }

    if ($action === 'toggle_driver') {
        $id = (int) post('id', 0);
        $row = db_row("SELECT name, status FROM drivers WHERE id = ?", array($id));
        if ($row) {
            $new = $row['status'] === 'active' ? 'inactive' : 'active';
            dbq("UPDATE drivers SET status = ? WHERE id = ?", array($new, $id));
            flash('success', $row['name'] . ' is now ' . $new . '.');
        }
        redirect('drivers.php');
    }
}

$editId = (int) get('edit', 0);
$edit   = $editId ? db_row("SELECT * FROM drivers WHERE id = ?", array($editId)) : null;
$subs   = db_all("SELECT id, name FROM subcontractors WHERE status='active' ORDER BY name");
$rows   = db_all("SELECT d.*, s.name AS sub_name FROM drivers d LEFT JOIN subcontractors s ON s.id=d.subcontractor_id ORDER BY d.name ASC");
$alertDays = (int) setting('expiry_alert_days', 30);

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/trucks.php">Trucks</a>
  <a href="<?= BASE_URL ?>/drivers.php" class="active">Drivers</a>
  <a href="<?= BASE_URL ?>/subcontractors.php">Subcontractors</a>
</div>

<div class="page-head">
  <div><h1>Drivers</h1><p><?= count($rows) ?> on record. Leave the subcontractor blank for your own driver.</p></div>
</div>

<div class="card" id="driver-form">
  <h3><?= $edit ? '✎ Edit ' . e($edit['name']) : '＋ Add a driver' ?></h3>
  <form method="post" action="<?= BASE_URL ?>/drivers.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_driver">
    <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
    <div class="form-grid">
      <div class="form-row"><label class="lbl">Name <span class="req">*</span></label>
        <input type="text" name="name" required value="<?= $edit ? e($edit['name']) : '' ?>" placeholder="e.g. Sivuyile"></div>
      <div class="form-row"><label class="lbl">Subcontractor (blank = own driver)</label>
        <select name="subcontractor_id"><option value="0">— Own driver —</option>
          <?php foreach ($subs as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= ($edit && (int)$edit['subcontractor_id']===(int)$s['id']) ? 'selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="form-row"><label class="lbl">Phone</label>
        <input type="text" name="phone" value="<?= $edit ? e($edit['phone']) : '' ?>"></div>
      <div class="form-row"><label class="lbl">Licence no.</label>
        <input type="text" name="licence_no" value="<?= $edit ? e($edit['licence_no']) : '' ?>"></div>
      <div class="form-row"><label class="lbl">Licence expiry</label>
        <input type="date" name="licence_expiry" value="<?= $edit ? e($edit['licence_expiry']) : '' ?>"></div>
      <div class="form-row"><label class="lbl">Pay per trip (default)</label>
        <div class="field-money"><input type="number" step="0.01" min="0" name="pay_per_trip" value="<?= $edit ? e($edit['pay_per_trip']) : '0' ?>"></div></div>
      <div class="form-row"><label class="lbl">Bank</label>
        <input type="text" name="bank_name" value="<?= e($edit['bank_name'] ?? '') ?>" placeholder="e.g. Access Bank"></div>
      <div class="form-row"><label class="lbl">Account no.</label>
        <input type="text" name="account_no" value="<?= e($edit['account_no'] ?? '') ?>"></div>
      <div class="form-row"><label class="lbl">Branch code</label>
        <input type="text" name="branch_code" value="<?= e($edit['branch_code'] ?? '') ?>"></div>
      <div class="form-row"><label class="lbl">Account holder</label>
        <input type="text" name="account_holder" value="<?= e($edit['account_holder'] ?? '') ?>" placeholder="if different to name"></div>
    </div>
    <div class="flex" style="gap:22px;flex-wrap:wrap;margin:8px 0 16px">
      <label class="sur-toggle"><input type="checkbox" name="expense_capture" value="1" <?= ($edit && $edit['expense_capture']) ? 'checked' : '' ?>> Captures expenses</label>
      <label class="sur-toggle"><input type="checkbox" name="pay_visibility" value="1" <?= (!$edit || $edit['pay_visibility']) ? 'checked' : '' ?>> Can see own earnings</label>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn primary"><?= $edit ? 'Save changes' : 'Add driver' ?></button>
      <?php if ($edit): ?><a href="<?= BASE_URL ?>/drivers.php" class="btn ghost">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h3>☺ Drivers</h3>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Name</th><th>Belongs to</th><th>Phone</th><th>Licence expiry</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6"><div class="empty"><div class="big">☺</div>No drivers yet. Add your first above.</div></td></tr>
        <?php else: foreach ($rows as $r):
          $expSoon = $r['licence_expiry'] && strtotime($r['licence_expiry']) < strtotime("+$alertDays days");
          $expired = $r['licence_expiry'] && strtotime($r['licence_expiry']) < time();
        ?>
          <tr class="<?= ($editId === (int) $r['id']) ? 'editing' : '' ?>">
            <td><a href="<?= BASE_URL ?>/driver_view.php?id=<?= (int) $r['id'] ?>"><b><?= e($r['name']) ?></b></a></td>
            <td><?= $r['sub_name'] ? e($r['sub_name']) : '<span class="badge gold">Own</span>' ?></td>
            <td><?= e($r['phone'] ? $r['phone'] : '—') ?></td>
            <td><?= fdate($r['licence_expiry']) ?>
              <?php if ($expired): ?> <span class="badge bad">expired</span>
              <?php elseif ($expSoon): ?> <span class="badge warn">soon</span><?php endif; ?></td>
            <td><?= $r['status'] === 'active' ? '<span class="badge good">Active</span>' : '<span class="badge muted">Inactive</span>' ?></td>
            <td><div class="row-actions">
              <a href="<?= BASE_URL ?>/drivers.php?edit=<?= (int) $r['id'] ?>#driver-form" class="btn ghost sm">Edit</a>
              <form method="post" action="<?= BASE_URL ?>/drivers.php" style="display:inline" onsubmit="return confirm('Toggle status?');">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle_driver"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
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
