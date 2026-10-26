<?php
/**
 * TMS — subcontractors.php
 * Manage subcontractors. Lean now; full profiles/performance come later.
 */
require_once __DIR__ . '/includes/auth.php';
require_role('master', 'admin');

$pageTitle    = 'Subcontractors';
$pageSubtitle = 'Fleet & people';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'save_sub') {
        $id    = (int) post('id', 0);
        $name  = post('name');
        if ($name === '') { flash('error', 'A subcontractor name is required.'); redirect('subcontractors.php' . ($id ? '?edit=' . $id : '')); }

        $fields = array(
            'name'            => $name,
            'contact_person'  => post('contact_person'),
            'phone'           => post('phone'),
            'email'           => post('email'),
            'vat_registered'  => post('vat_registered') === '1' ? 1 : 0,
            'dedicated'       => post('dedicated') === '1' ? 1 : 0,
            'pay_visibility'  => post('pay_visibility') === '1' ? 1 : 0,
            'expense_capture' => post('expense_capture') === '1' ? 1 : 0,
        );

        if ($id > 0) {
            dbq("UPDATE subcontractors SET name=?, contact_person=?, phone=?, email=?, vat_registered=?, dedicated=?, pay_visibility=?, expense_capture=? WHERE id=?",
                array($fields['name'],$fields['contact_person'],$fields['phone'],$fields['email'],$fields['vat_registered'],$fields['dedicated'],$fields['pay_visibility'],$fields['expense_capture'],$id));
            audit('update', 'subcontractor', $id, $name);
            flash('success', 'Updated ' . $name . '.');
        } else {
            $newId = db_insert("INSERT INTO subcontractors (name, contact_person, phone, email, vat_registered, dedicated, pay_visibility, expense_capture, created_by) VALUES (?,?,?,?,?,?,?,?,?)",
                array($fields['name'],$fields['contact_person'],$fields['phone'],$fields['email'],$fields['vat_registered'],$fields['dedicated'],$fields['pay_visibility'],$fields['expense_capture'], $me['id']));
            audit('create', 'subcontractor', $newId, $name);
            flash('success', 'Added ' . $name . '.');
        }
        redirect('subcontractors.php');
    }

    if ($action === 'toggle_sub') {
        $id = (int) post('id', 0);
        $row = db_row("SELECT name, status FROM subcontractors WHERE id = ?", array($id));
        if ($row) {
            $new = $row['status'] === 'active' ? 'inactive' : 'active';
            dbq("UPDATE subcontractors SET status = ? WHERE id = ?", array($new, $id));
            flash('success', $row['name'] . ' is now ' . $new . '.');
        }
        redirect('subcontractors.php');
    }
}

$editId = (int) get('edit', 0);
$edit   = $editId ? db_row("SELECT * FROM subcontractors WHERE id = ?", array($editId)) : null;
$rows   = db_all("SELECT s.*, (SELECT COUNT(*) FROM trucks t WHERE t.subcontractor_id=s.id) AS trucks,
                         (SELECT COUNT(*) FROM drivers d WHERE d.subcontractor_id=s.id) AS drivers
                  FROM subcontractors s ORDER BY s.name ASC");

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/trucks.php">Trucks</a>
  <a href="<?= BASE_URL ?>/drivers.php">Drivers</a>
  <a href="<?= BASE_URL ?>/subcontractors.php" class="active">Subcontractors</a>
</div>

<div class="page-head">
  <div><h1>Subcontractors</h1><p><?= count($rows) ?> on record.</p></div>
</div>

<div class="card" id="sub-form">
  <h3><?= $edit ? '✎ Edit ' . e($edit['name']) : '＋ Add a subcontractor' ?></h3>
  <form method="post" action="<?= BASE_URL ?>/subcontractors.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_sub">
    <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
    <div class="form-grid">
      <div class="form-row"><label class="lbl">Name <span class="req">*</span></label>
        <input type="text" name="name" required value="<?= $edit ? e($edit['name']) : '' ?>" placeholder="e.g. Sivuyile Transport"></div>
      <div class="form-row"><label class="lbl">Contact person</label>
        <input type="text" name="contact_person" value="<?= $edit ? e($edit['contact_person']) : '' ?>"></div>
      <div class="form-row"><label class="lbl">Phone</label>
        <input type="text" name="phone" value="<?= $edit ? e($edit['phone']) : '' ?>"></div>
      <div class="form-row"><label class="lbl">Email</label>
        <input type="email" name="email" value="<?= $edit ? e($edit['email']) : '' ?>"></div>
    </div>
    <div class="flex" style="gap:22px;flex-wrap:wrap;margin:8px 0 16px">
      <label class="sur-toggle"><input type="checkbox" name="vat_registered" value="1" <?= ($edit && $edit['vat_registered']) ? 'checked' : '' ?>> VAT registered</label>
      <label class="sur-toggle"><input type="checkbox" name="dedicated" value="1" <?= ($edit && $edit['dedicated']) ? 'checked' : '' ?>> Dedicated to us</label>
      <label class="sur-toggle"><input type="checkbox" name="pay_visibility" value="1" <?= (!$edit || $edit['pay_visibility']) ? 'checked' : '' ?>> Can see their pay</label>
      <label class="sur-toggle"><input type="checkbox" name="expense_capture" value="1" <?= (!$edit || $edit['expense_capture']) ? 'checked' : '' ?>> Captures expenses</label>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn primary"><?= $edit ? 'Save changes' : 'Add subcontractor' ?></button>
      <?php if ($edit): ?><a href="<?= BASE_URL ?>/subcontractors.php" class="btn ghost">Cancel</a><?php endif; ?>
      <span class="note">VAT-registered subbies get VAT added to their payout.</span>
    </div>
  </form>
</div>

<div class="card">
  <h3>⚇ Subcontractors</h3>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Name</th><th>Contact</th><th>Phone</th><th>VAT</th><th class="num">Trucks</th><th class="num">Drivers</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8"><div class="empty"><div class="big">⚇</div>No subcontractors yet. Add your first above.</div></td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr class="<?= ($editId === (int) $r['id']) ? 'editing' : '' ?>">
            <td><a href="<?= BASE_URL ?>/subcontractor_view.php?id=<?= (int) $r['id'] ?>"><b><?= e($r['name']) ?></b></a></td>
            <td><?= e($r['contact_person'] ? $r['contact_person'] : '—') ?></td>
            <td><?= e($r['phone'] ? $r['phone'] : '—') ?></td>
            <td><?= $r['vat_registered'] ? '<span class="badge info">VAT</span>' : '<span class="muted">—</span>' ?></td>
            <td class="num"><?= (int) $r['trucks'] ?></td>
            <td class="num"><?= (int) $r['drivers'] ?></td>
            <td><?= $r['status'] === 'active' ? '<span class="badge good">Active</span>' : '<span class="badge muted">Inactive</span>' ?></td>
            <td><div class="row-actions">
              <a href="<?= BASE_URL ?>/subcontractors.php?edit=<?= (int) $r['id'] ?>#sub-form" class="btn ghost sm">Edit</a>
              <form method="post" action="<?= BASE_URL ?>/subcontractors.php" style="display:inline" onsubmit="return confirm('Toggle status?');">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle_sub"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
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
