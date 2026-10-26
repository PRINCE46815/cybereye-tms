<?php
/**
 * TMS — my_trip.php  (DRIVER PORTAL)
 * A driver views one of their own trips and can flag a mistake for the office.
 */
require_once __DIR__ . '/includes/auth.php';
require_role('driver');

$driverId = $me['driver_id'];
$id = (int) get('id', 0);
$trip = db_row("SELECT t.*, c.name AS client_name, sl.name AS line_name, tr.registration AS truck_reg
                FROM trips t LEFT JOIN clients c ON c.id=t.billing_client_id
                LEFT JOIN clients sl ON sl.id=t.shipping_line_id LEFT JOIN trucks tr ON tr.id=t.truck_id
                WHERE t.id=? AND t.driver_id=?", array($id, $driverId));

$pageTitle    = $trip ? $trip['trip_ref'] : 'Trip';
$pageSubtitle = 'Driver';

if ($trip && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('action') === 'flag') {
        dbq("UPDATE trips SET flagged=1, flag_note=? WHERE id=? AND driver_id=?", array(post('flag_note'), $id, $driverId));
        audit('driver_flag', 'trip', $id, $trip['trip_ref']);
        flash('success', 'Flagged for the office. They\'ll take a look.');
        redirect('my_trip.php?id=' . $id);
    }
}

require_once __DIR__ . '/includes/header.php';
if (!$trip) { echo '<div class="card"><div class="empty"><div class="big">⚠</div>Trip not found (or not yours).</div></div>'; require_once __DIR__ . '/includes/footer.php'; return; }
?>

<div class="page-head">
  <div><h1><?= e($trip['trip_ref']) ?> <span class="badge <?= $trip['status']==='Paid'?'good':'info' ?>"><?= e($trip['status']) ?></span>
    <?php if ((int)$trip['flagged']===1): ?><span class="badge bad">⚑ flagged</span><?php endif; ?></h1>
    <p><?= e($trip['route'] ? $trip['route'] : '—') ?> · <?= e($trip['container_size']) ?> <?= e($trip['load_state']) ?></p></div>
  <div class="actions"><a href="<?= BASE_URL ?>/my_dashboard.php" class="btn ghost">← Back</a></div>
</div>

<div class="grid-2">
  <div>
    <div class="card">
      <h3>Trip</h3>
      <div class="dl">
        <div class="dl-row"><span class="dl-l">Date</span><span class="dl-v"><?= fdate($trip['collection_date']) ?></span></div>
        <div class="dl-row"><span class="dl-l">Client</span><span class="dl-v"><?= e($trip['client_name'] ? $trip['client_name'] : '—') ?></span></div>
        <div class="dl-row"><span class="dl-l">Shipping line</span><span class="dl-v"><?= e($trip['line_name'] ? $trip['line_name'] : '—') ?></span></div>
        <div class="dl-row"><span class="dl-l">Container</span><span class="dl-v"><?= e($trip['container_number'] ? $trip['container_number'] : '—') ?></span></div>
        <div class="dl-row"><span class="dl-l">Truck</span><span class="dl-v"><?= e($trip['truck_reg'] ? $trip['truck_reg'] : '—') ?></span></div>
        <div class="dl-row"><span class="dl-l">T.O number</span><span class="dl-v"><?= e($trip['to_number'] ? $trip['to_number'] : '—') ?></span></div>
        <div class="dl-row"><span class="dl-l">My pay</span><span class="dl-v"><?= money($trip['driver_pay']) ?></span></div>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <h3>📄 Proof of delivery</h3>
      <?php if ($trip['pod_path']): ?>
        <a href="<?= BASE_URL ?>/<?= e($trip['pod_path']) ?>" target="_blank" class="btn ghost block">View uploaded POD</a>
      <?php else: ?>
        <div class="flash warn">No POD on file for this trip.</div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3>Something wrong?</h3>
      <?php if ((int)$trip['flagged']===1): ?>
        <div class="flash info">You flagged this trip<?= $trip['flag_note'] ? ': ' . e($trip['flag_note']) : '' ?>. The office has been notified.</div>
      <?php else: ?>
        <form method="post" action="<?= BASE_URL ?>/my_trip.php?id=<?= $id ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="flag">
          <div class="form-row"><label class="lbl">Tell the office what's wrong</label>
            <textarea name="flag_note" rows="2" placeholder="e.g. pay rate looks wrong, wrong route..."></textarea></div>
          <button class="btn ghost block">⚑ Flag this trip</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
