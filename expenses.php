<?php
/**
 * TMS — expenses.php
 * Office capture of driver/truck expenses (diesel, tolls, repairs).
 * These feed subcontractor earnings and cost reporting.
 */
require_once __DIR__ . '/includes/auth.php';
require_role('master', 'admin', 'finance');

$pageTitle    = 'Expenses';
$pageSubtitle = 'Money';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'add_expense') {
        $driverId = (int) post('driver_id', 0);
        if (!$driverId) { flash('error', 'Choose a driver.'); redirect('expenses.php'); }
        $amount = clean_number(post('amount')); $amount = $amount !== null ? (float) $amount : 0;
        if ($amount <= 0) { flash('error', 'Enter an amount.'); redirect('expenses.php'); }
        $type   = in_array(post('type'), array('diesel','toll','repair','other'), true) ? post('type') : 'other';
        $litres = clean_number(post('litres')); $litres = $litres !== null ? $litres : null;
        $odo    = clean_number(post('odometer')); $odo = $odo !== null ? (int) $odo : null;
        $truckId= (int) post('truck_id', 0) ?: null;

        db_insert("INSERT INTO driver_expenses (driver_id, truck_id, type, litres, amount, odometer, station, spent_on) VALUES (?,?,?,?,?,?,?,?)",
            array($driverId, $truckId, $type, $litres, round($amount, 2), $odo, post('station'), (post('spent_on') ?: date('Y-m-d'))));
        audit('create', 'expense', $driverId, $type . ' ' . money($amount));
        flash('success', 'Expense recorded.');
        redirect('expenses.php');
    }

    if ($action === 'del_expense') {
        dbq("DELETE FROM driver_expenses WHERE id=?", array((int) post('id', 0)));
        flash('info', 'Expense removed.');
        redirect('expenses.php');
    }
}

$fDriver = (int) get('driver', 0);
$fFrom   = get('from', date('Y-m-01'));
$fTo     = get('to', date('Y-m-d'));

$where = array('e.spent_on BETWEEN ? AND ?'); $args = array($fFrom, $fTo);
if ($fDriver > 0) { $where[] = 'e.driver_id = ?'; $args[] = $fDriver; }
$whereSql = implode(' AND ', $where);

$rows = db_all("SELECT e.*, d.name AS driver_name, t.registration AS truck_reg
                FROM driver_expenses e LEFT JOIN drivers d ON d.id=e.driver_id LEFT JOIN trucks t ON t.id=e.truck_id
                WHERE $whereSql ORDER BY e.spent_on DESC, e.id DESC", $args);
$total = 0; $litresTot = 0; foreach ($rows as $r) { $total += (float) $r['amount']; $litresTot += (float) $r['litres']; }

$drivers = db_all("SELECT id, name FROM drivers WHERE status='active' ORDER BY name");
$trucks  = db_all("SELECT id, registration FROM trucks WHERE status='active' ORDER BY registration");

$typeBadge = array('diesel'=>'gold','toll'=>'info','repair'=>'warn','other'=>'muted');

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div><h1>Expenses</h1><p>Fuel, tolls and repairs logged against drivers and trucks.</p></div>
</div>

<div class="card" id="exp-form">
  <h3>＋ Record an expense</h3>
  <form method="post" action="<?= BASE_URL ?>/expenses.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_expense">
    <div class="form-grid">
      <div class="form-row"><label class="lbl">Driver <span class="req">*</span></label>
        <select name="driver_id" required><option value="0">— choose —</option>
          <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-row"><label class="lbl">Truck</label>
        <select name="truck_id"><option value="0">— none —</option>
          <?php foreach ($trucks as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['registration']) ?></option><?php endforeach; ?></select></div>
      <div class="form-row"><label class="lbl">Type</label>
        <select name="type"><option value="diesel">Diesel</option><option value="toll">Toll</option><option value="repair">Repair</option><option value="other">Other</option></select></div>
      <div class="form-row"><label class="lbl">Amount <span class="req">*</span></label>
        <div class="field-money"><input type="number" step="0.01" min="0" name="amount" required></div></div>
      <div class="form-row"><label class="lbl">Litres (diesel)</label><input type="number" step="0.01" min="0" name="litres"></div>
      <div class="form-row"><label class="lbl">Odometer</label><input type="number" min="0" name="odometer"></div>
      <div class="form-row"><label class="lbl">Station / supplier</label><input type="text" name="station"></div>
      <div class="form-row"><label class="lbl">Date</label><input type="date" name="spent_on" value="<?= date('Y-m-d') ?>"></div>
    </div>
    <div class="form-actions"><button class="btn primary">Record expense</button></div>
  </form>
</div>

<div class="card">
  <form method="get" action="<?= BASE_URL ?>/expenses.php" class="flex" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-row" style="margin:0"><label class="lbl">Driver</label>
      <select name="driver"><option value="0">All</option>
        <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $fDriver===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-row" style="margin:0"><label class="lbl">From</label><input type="date" name="from" value="<?= e($fFrom) ?>"></div>
    <div class="form-row" style="margin:0"><label class="lbl">To</label><input type="date" name="to" value="<?= e($fTo) ?>"></div>
    <button class="btn dark">Filter</button>
  </form>
</div>

<div class="kpis">
  <div class="kpi bad"><div class="label">Total expenses</div><div class="value" style="font-size:20px"><?= money($total) ?></div><div class="meta"><?= count($rows) ?> entries</div></div>
  <div class="kpi"><div class="label">Diesel litres</div><div class="value" style="font-size:20px"><?= num($litresTot,1) ?> L</div></div>
</div>

<div class="card">
  <h3>Logged expenses</h3>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Date</th><th>Driver</th><th>Truck</th><th>Type</th><th>Station</th><th class="num">Litres</th><th class="num">Amount</th><th style="text-align:right">·</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8"><div class="empty"><div class="big">⛽</div>No expenses in this period.</div></td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td class="muted"><?= fdate($r['spent_on']) ?></td>
            <td><b><?= e($r['driver_name'] ? $r['driver_name'] : '—') ?></b></td>
            <td><?= e($r['truck_reg'] ? $r['truck_reg'] : '—') ?></td>
            <td><span class="badge <?= $typeBadge[$r['type']] ?>"><?= e(ucfirst($r['type'])) ?></span></td>
            <td><?= e($r['station'] ? $r['station'] : '—') ?></td>
            <td class="num"><?= $r['litres'] ? num($r['litres'],1) : '—' ?></td>
            <td class="num"><b><?= money($r['amount']) ?></b></td>
            <td style="text-align:right">
              <form method="post" action="<?= BASE_URL ?>/expenses.php" style="display:inline" onsubmit="return confirm('Remove this expense?');">
                <?= csrf_field() ?><input type="hidden" name="action" value="del_expense"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn ghost sm">✕</button></form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
