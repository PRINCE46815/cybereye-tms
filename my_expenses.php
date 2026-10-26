<?php
/**
 * TMS — my_expenses.php  (DRIVER PORTAL)
 * A driver logs their own expenses, if the office enabled it for them.
 */
require_once __DIR__ . '/includes/auth.php';
require_role('driver');

$driverId = $me['driver_id'];
$pageTitle    = 'My Expenses';
$pageSubtitle = 'Driver';

$driver = $driverId ? db_row("SELECT * FROM drivers WHERE id=?", array($driverId)) : null;
$canCapture = $driver && (int) $driver['expense_capture'] === 1;

if ($canCapture && $_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'add') {
    csrf_check();
    $amount = clean_number(post('amount')); $amount = $amount !== null ? (float) $amount : 0;
    if ($amount <= 0) { flash('error', 'Enter an amount.'); redirect('my_expenses.php'); }
    $type   = in_array(post('type'), array('diesel','toll','repair','other'), true) ? post('type') : 'other';
    $litres = clean_number(post('litres')); $litres = $litres !== null ? $litres : null;
    $odo    = clean_number(post('odometer')); $odo = $odo !== null ? (int) $odo : null;
    db_insert("INSERT INTO driver_expenses (driver_id, type, litres, amount, odometer, station, spent_on) VALUES (?,?,?,?,?,?,?)",
        array($driverId, $type, $litres, round($amount, 2), $odo, post('station'), (post('spent_on') ?: date('Y-m-d'))));
    flash('success', 'Expense logged. Thanks!');
    redirect('my_expenses.php');
}

require_once __DIR__ . '/includes/header.php';
if (!$driverId) { echo '<div class="card"><div class="empty"><div class="big">⚠</div>Your login isn\'t linked to a driver record yet.</div></div>'; require_once __DIR__ . '/includes/footer.php'; return; }

$mtdFrom = date('Y-m-01');
$rows = db_all("SELECT * FROM driver_expenses WHERE driver_id=? AND spent_on>=? ORDER BY spent_on DESC, id DESC", array($driverId, $mtdFrom));
$total = 0; foreach ($rows as $r) { $total += (float) $r['amount']; }
$typeBadge = array('diesel'=>'gold','toll'=>'info','repair'=>'warn','other'=>'muted');
?>

<div class="page-head">
  <div><h1>My Expenses</h1><p>This month so far.</p></div>
</div>

<?php if (!$canCapture): ?>
  <div class="card"><div class="empty"><div class="big">🔒</div>Expense capture isn't switched on for your account. Ask the office to enable it.</div></div>
<?php else: ?>

<div class="card">
  <h3>＋ Log an expense</h3>
  <form method="post" action="<?= BASE_URL ?>/my_expenses.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="form-grid">
      <div class="form-row"><label class="lbl">Type</label>
        <select name="type"><option value="diesel">Diesel</option><option value="toll">Toll</option><option value="repair">Repair</option><option value="other">Other</option></select></div>
      <div class="form-row"><label class="lbl">Amount <span class="req">*</span></label>
        <div class="field-money"><input type="number" step="0.01" min="0" name="amount" required></div></div>
      <div class="form-row"><label class="lbl">Litres (diesel)</label><input type="number" step="0.01" min="0" name="litres"></div>
      <div class="form-row"><label class="lbl">Odometer</label><input type="number" min="0" name="odometer"></div>
      <div class="form-row"><label class="lbl">Station</label><input type="text" name="station"></div>
      <div class="form-row"><label class="lbl">Date</label><input type="date" name="spent_on" value="<?= date('Y-m-d') ?>"></div>
    </div>
    <div class="form-actions"><button class="btn primary">Log expense</button></div>
  </form>
</div>

<div class="kpis">
  <div class="kpi bad"><div class="label">My expenses (month)</div><div class="value" style="font-size:21px"><?= money($total) ?></div><div class="meta"><?= count($rows) ?> entries</div></div>
</div>

<div class="card">
  <h3>This month</h3>
  <?php if (!$rows): ?>
    <div class="empty"><div class="big">⛽</div>Nothing logged yet this month.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Date</th><th>Type</th><th>Station</th><th class="num">Litres</th><th class="num">Amount</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="muted"><?= fdate($r['spent_on']) ?></td>
            <td><span class="badge <?= $typeBadge[$r['type']] ?>"><?= e(ucfirst($r['type'])) ?></span></td>
            <td><?= e($r['station'] ? $r['station'] : '—') ?></td>
            <td class="num"><?= $r['litres'] ? num($r['litres'],1) : '—' ?></td>
            <td class="num"><b><?= money($r['amount']) ?></b></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
