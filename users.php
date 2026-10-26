<?php
/**
 * TMS — users.php  (MASTER ONLY)
 * Create and manage logins. Drivers/subcontractors are linked to their
 * record so the portals scope to their own data.
 */
require_once __DIR__ . '/includes/auth.php';
require_role('master');

$pageTitle    = 'Users';
$pageSubtitle = 'Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'save_user') {
        $id    = (int) post('id', 0);
        $name  = trim(post('name'));
        $email = strtolower(trim(post('email')));
        $role  = post('role');
        $valid = array('master','admin','finance','viewer','driver','subcontractor');
        if (!in_array($role, $valid, true)) { $role = 'viewer'; }
        if ($name === '' || $email === '') { flash('error', 'Name and email are required.'); redirect('users.php' . ($id ? '?edit=' . $id : '')); }

        $clash = (int) db_val("SELECT COUNT(*) FROM users WHERE email=? AND id<>?", array($email, $id));
        if ($clash > 0) { flash('error', 'That email is already in use.'); redirect('users.php' . ($id ? '?edit=' . $id : '')); }

        $drvId = $role === 'driver' ? ((int) post('driver_id', 0) ?: null) : null;
        $subId = $role === 'subcontractor' ? ((int) post('subcontractor_id', 0) ?: null) : null;

        if ($id > 0) {
            dbq("UPDATE users SET name=?, email=?, role=?, driver_id=?, subcontractor_id=? WHERE id=?",
                array($name, $email, $role, $drvId, $subId, $id));
            $pw = post('password');
            if ($pw !== '') {
                if (!password_strong($pw, $why)) { flash('error', 'Password must be ' . $why . '.'); redirect('users.php?edit=' . $id); }
                dbq("UPDATE users SET password_hash=? WHERE id=?", array(password_hash($pw, PASSWORD_BCRYPT), $id));
            }
            audit('update', 'user', $id, $email);
            flash('success', 'Updated ' . $name . '.');
        } else {
            $pw = post('password');
            if ($pw === '') { flash('error', 'Set a password for the new user.'); redirect('users.php'); }
            if (!password_strong($pw, $why)) { flash('error', 'Password must be ' . $why . '.'); redirect('users.php'); }
            $newId = db_insert("INSERT INTO users (name, email, password_hash, role, driver_id, subcontractor_id, created_by) VALUES (?,?,?,?,?,?,?)",
                array($name, $email, password_hash($pw, PASSWORD_BCRYPT), $role, $drvId, $subId, $me['id']));
            audit('create', 'user', $newId, $email);
            flash('success', 'Created login for ' . $name . '.');
        }
        redirect('users.php');
    }

    if ($action === 'reset_2fa') {
        $id = (int) post('id', 0);
        $row = db_row("SELECT name FROM users WHERE id=?", array($id));
        if ($row) {
            dbq("UPDATE users SET totp_secret=NULL, totp_enabled=0, totp_recovery=NULL WHERE id=?", array($id));
            audit('2fa_reset', 'user', $id, $row['name']);
            flash('info', '2FA reset for ' . $row['name'] . '. They can re-enable it from their account.');
        }
        redirect('users.php');
    }

    if ($action === 'toggle_user') {
        $id = (int) post('id', 0);
        if ($id === (int) $me['id']) { flash('error', "You can't disable your own account."); redirect('users.php'); }
        $row = db_row("SELECT name, status FROM users WHERE id=?", array($id));
        if ($row) { dbq("UPDATE users SET status=? WHERE id=?", array($row['status']==='active'?'inactive':'active', $id)); flash('success', $row['name'] . ' toggled.'); }
        redirect('users.php');
    }
}

$editId = (int) get('edit', 0);
$edit   = $editId ? db_row("SELECT * FROM users WHERE id=?", array($editId)) : null;
$rows   = db_all("SELECT u.*, d.name AS driver_name, s.name AS sub_name FROM users u
                  LEFT JOIN drivers d ON d.id=u.driver_id LEFT JOIN subcontractors s ON s.id=u.subcontractor_id
                  ORDER BY u.role, u.name");
$drivers = db_all("SELECT id, name FROM drivers WHERE status='active' ORDER BY name");
$subs    = db_all("SELECT id, name FROM subcontractors WHERE status='active' ORDER BY name");

$roleBadge = array('master'=>'gold','admin'=>'info','finance'=>'info','viewer'=>'muted','driver'=>'good','subcontractor'=>'good');

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div><h1>Users</h1><p><?= count($rows) ?> logins. Create driver and subcontractor accounts here and link them to their record.</p></div>
</div>

<div class="card" id="user-form">
  <h3><?= $edit ? '✎ Edit ' . e($edit['name']) : '＋ Add a user' ?></h3>
  <form method="post" action="<?= BASE_URL ?>/users.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_user">
    <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
    <div class="form-grid">
      <div class="form-row"><label class="lbl">Name <span class="req">*</span></label>
        <input type="text" name="name" required value="<?= $edit ? e($edit['name']) : '' ?>"></div>
      <div class="form-row"><label class="lbl">Email <span class="req">*</span></label>
        <input type="email" name="email" required value="<?= $edit ? e($edit['email']) : '' ?>"></div>
      <div class="form-row"><label class="lbl">Role</label>
        <select name="role" id="role" onchange="roleChange()">
          <?php foreach (array('master'=>'Master (owner)','admin'=>'Admin / Operations','finance'=>'Finance','viewer'=>'Viewer','driver'=>'Driver (portal)','subcontractor'=>'Subcontractor (portal)') as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($edit ? $edit['role'] : 'viewer')===$k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="form-row"><label class="lbl"><?= $edit ? 'New password (blank = keep)' : 'Password' ?></label>
        <input type="text" name="password" autocomplete="new-password" placeholder="<?= $edit ? 'leave blank to keep' : 'set a password' ?>"></div>
      <div class="form-row" id="link-driver" style="display:none"><label class="lbl">Linked driver</label>
        <select name="driver_id"><option value="0">— choose driver —</option>
          <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['id'] ?>" <?= ($edit && (int)$edit['driver_id']===(int)$d['id'])?'selected':'' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="form-row" id="link-sub" style="display:none"><label class="lbl">Linked subcontractor</label>
        <select name="subcontractor_id"><option value="0">— choose subcontractor —</option>
          <?php foreach ($subs as $s): ?><option value="<?= (int)$s['id'] ?>" <?= ($edit && (int)$edit['subcontractor_id']===(int)$s['id'])?'selected':'' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <div class="form-actions">
      <button class="btn primary"><?= $edit ? 'Save user' : 'Create user' ?></button>
      <?php if ($edit): ?><a href="<?= BASE_URL ?>/users.php" class="btn ghost">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h3>☻ Logins</h3>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Linked to</th><th>Last login</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr class="<?= ($editId === (int)$r['id']) ? 'editing' : '' ?>">
            <td><b><?= e($r['name']) ?></b></td>
            <td class="muted"><?= e($r['email']) ?></td>
            <td><span class="badge <?= isset($roleBadge[$r['role']])?$roleBadge[$r['role']]:'muted' ?>"><?= e($r['role']) ?></span>
              <?php if ((int)$r['totp_enabled']===1): ?><span class="badge good" title="Two-factor enabled">🔒 2FA</span><?php endif; ?></td>
            <td><?= e($r['driver_name'] ? $r['driver_name'] : ($r['sub_name'] ? $r['sub_name'] : '—')) ?></td>
            <td class="muted"><?= $r['last_login'] ? fdate($r['last_login']) : 'never' ?></td>
            <td><?= $r['status']==='active' ? '<span class="badge good">Active</span>' : '<span class="badge muted">Off</span>' ?></td>
            <td><div class="row-actions">
              <a href="<?= BASE_URL ?>/users.php?edit=<?= (int)$r['id'] ?>#user-form" class="btn ghost sm">Edit</a>
              <?php if ((int)$r['totp_enabled']===1): ?>
              <form method="post" action="<?= BASE_URL ?>/users.php" style="display:inline" onsubmit="return confirm('Reset 2FA for <?= e(addslashes($r['name'])) ?>? Use this only if they are locked out.');">
                <?= csrf_field() ?><input type="hidden" name="action" value="reset_2fa"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn ghost sm">Reset 2FA</button>
              </form>
              <?php endif; ?>
              <?php if ((int)$r['id'] !== (int)$me['id']): ?>
              <form method="post" action="<?= BASE_URL ?>/users.php" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn ghost sm"><?= $r['status']==='active'?'Off':'On' ?></button>
              </form>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function roleChange(){
  var r=document.getElementById('role').value;
  document.getElementById('link-driver').style.display = r==='driver' ? 'block':'none';
  document.getElementById('link-sub').style.display    = r==='subcontractor' ? 'block':'none';
}
roleChange();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
