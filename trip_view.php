<?php
/**
 * TMS — trip_view.php
 * One trip in full: parties, container, money, and the POD-collection
 * control (mark collected when you've taken the physical POD).
 */
require_once __DIR__ . '/includes/auth.php';
require_role('master', 'admin', 'finance', 'viewer');

$id   = (int) get('id', 0);
$trip = db_row(
    "SELECT t.*, c.name AS client_name, sl.name AS line_name, d.name AS dest_name,
            tr.registration AS truck_reg, dr.name AS driver_name, s.name AS sub_name,
            uc.name AS created_name, um.name AS modified_name, pc.name AS pod_collector
     FROM trips t
     LEFT JOIN clients c       ON c.id = t.billing_client_id
     LEFT JOIN clients sl      ON sl.id = t.shipping_line_id
     LEFT JOIN destinations d  ON d.id = t.destination_id
     LEFT JOIN trucks tr       ON tr.id = t.truck_id
     LEFT JOIN drivers dr      ON dr.id = t.driver_id
     LEFT JOIN subcontractors s ON s.id = t.subcontractor_id
     LEFT JOIN users uc        ON uc.id = t.created_by
     LEFT JOIN users um        ON um.id = t.modified_by
     LEFT JOIN users pc        ON pc.id = t.pod_collected_by
     WHERE t.id = ?", array($id));

if (!$trip) { flash('error', 'That trip was not found.'); redirect('trips.php'); }

$pageTitle    = 'Trip ' . $trip['trip_ref'];
$pageSubtitle = 'Loads / Trips';

/* ---- Actions ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'toggle_pod' && can_edit_trips()) {
        if ($trip['pod_collected'] === 'collected') {
            dbq("UPDATE trips SET pod_collected='not_collected', pod_collected_at=NULL, pod_collected_by=NULL WHERE id=?", array($id));
            audit('pod_uncollect', 'trip', $id, $trip['trip_ref']);
            flash('info', 'POD marked as NOT collected (back with the driver).');
        } else {
            dbq("UPDATE trips SET pod_collected='collected', pod_collected_at=NOW(), pod_collected_by=? WHERE id=?", array($me['id'], $id));
            audit('pod_collect', 'trip', $id, $trip['trip_ref']);
            flash('success', 'POD marked as collected. Ready to submit to the client.');
        }
        redirect('trip_view.php?id=' . $id);
    }

    if ($action === 'change_status' && can_edit_trips()) {
        $new = post('status');
        dbq("UPDATE trips SET status=?, modified_by=?, modified_at=NOW() WHERE id=?", array($new, $me['id'], $id));
        audit('status', 'trip', $id, $trip['trip_ref'] . ' → ' . $new);
        flash('success', 'Status changed to ' . $new . '.');
        redirect('trip_view.php?id=' . $id);
    }

    if ($action === 'toggle_flag' && can_edit_trips()) {
        $new = (int) $trip['flagged'] === 1 ? 0 : 1;
        dbq("UPDATE trips SET flagged=?, flag_note=? WHERE id=?", array($new, post('flag_note'), $id));
        flash('success', $new ? 'Trip flagged for review.' : 'Flag cleared.');
        redirect('trip_view.php?id=' . $id);
    }

    if ($action === 'delete_trip' && is_master()) {
        dbq("DELETE FROM trips WHERE id=?", array($id));
        audit('delete', 'trip', $id, $trip['trip_ref']);
        flash('success', 'Trip ' . $trip['trip_ref'] . ' deleted.');
        redirect('trips.php');
    }
}

$vat = $trip['gross_revenue'] * setting_num('vat_pct') / 100;
$statusList = array('Draft','Scheduled','In Transit','Delivered','Invoiced','Paid','On Hold','Cancelled');

function statusBadge2($s) {
    $map = array('Draft'=>'muted','Scheduled'=>'info','In Transit'=>'gold','Delivered'=>'good',
                 'Invoiced'=>'info','Paid'=>'good','On Hold'=>'warn','Cancelled'=>'bad');
    $cls = isset($map[$s]) ? $map[$s] : 'muted';
    return '<span class="badge ' . $cls . '">' . e($s) . '</span>';
}
function dlrow($label, $value) {
    echo '<div class="dl-row"><span class="dl-l">' . e($label) . '</span><span class="dl-v">' . $value . '</span></div>';
}

require_once __DIR__ . '/includes/header.php';
$marginClass = $trip['margin_pct'] >= 25 ? 'good' : ($trip['margin_pct'] >= 15 ? 'warn' : 'bad');
?>

<div class="page-head">
  <div>
    <h1><?= e($trip['trip_ref']) ?> <?= statusBadge2($trip['status']) ?>
      <?php if ((int)$trip['flagged']===1): ?><span class="badge bad">⚑ flagged</span><?php endif; ?></h1>
    <p><?= e($trip['route'] ? $trip['route'] : ($trip['dest_name'] ? $trip['dest_name'] : 'No route')) ?>
       · <?= e($trip['container_size']) ?> <?= e($trip['load_state']) ?></p>
  </div>
  <div class="actions">
    <a href="<?= BASE_URL ?>/trips.php" class="btn ghost">← All trips</a>
    <?php if (can_edit_trips()): ?><a href="<?= BASE_URL ?>/trip_form.php?id=<?= $id ?>" class="btn primary">Edit</a><?php endif; ?>
  </div>
</div>

<div class="grid-2">

  <!-- LEFT: details -->
  <div>
    <div class="card">
      <h3>Container &amp; route</h3>
      <div class="dl">
        <?php
          dlrow('Container number', e($trip['container_number'] ? $trip['container_number'] : '—'));
          dlrow('Size / state', e($trip['container_size'] . ' · ' . $trip['load_state']));
          dlrow('Job type', e($trip['job_type'] ? ucfirst($trip['job_type']) : '—'));
          dlrow('Origin', e($trip['origin'] ? $trip['origin'] : '—'));
          dlrow('Route', e($trip['route'] ? $trip['route'] : '—'));
          dlrow('Distance', $trip['km'] ? num($trip['km']) . ' km' : '—');
          dlrow('Genset', $trip['has_genset'] ? '<span class="badge info">Yes</span>' : 'No');
        ?>
      </div>
    </div>

    <div class="card">
      <h3>Parties</h3>
      <div class="dl">
        <?php
          dlrow('Billing client', e($trip['client_name'] ? $trip['client_name'] : '—'));
          dlrow('Shipping line', e($trip['line_name'] ? $trip['line_name'] : '—'));
          dlrow('T.O number', e($trip['to_number'] ? $trip['to_number'] : '—'));
          dlrow('Truck', e($trip['truck_reg'] ? $trip['truck_reg'] : '—'));
          dlrow('Driver', e($trip['driver_name'] ? $trip['driver_name'] : '—'));
          dlrow('Subcontractor', $trip['sub_name'] ? e($trip['sub_name']) : '<span class="badge gold">Own fleet</span>');
        ?>
      </div>
    </div>

    <div class="card">
      <h3>Dates</h3>
      <div class="dl">
        <?php
          dlrow('Collection', fdate($trip['collection_date']));
          dlrow('Loading', fdate($trip['loading_date']));
          dlrow('Delivery', fdate($trip['delivery_date']));
        ?>
      </div>
    </div>

    <?php if ($trip['notes']): ?>
    <div class="card"><h3>Notes</h3><p class="mt0"><?= nl2br(e($trip['notes'])) ?></p></div>
    <?php endif; ?>

    <p class="muted" style="font-size:12px">
      Created by <?= e($trip['created_name'] ? $trip['created_name'] : '—') ?> on <?= fdate($trip['created_at']) ?>.
      <?php if ($trip['modified_name']): ?> Last edited by <?= e($trip['modified_name']) ?> on <?= fdate($trip['modified_at']) ?>.<?php endif; ?>
    </p>
  </div>

  <!-- RIGHT: money + POD + controls -->
  <div>
    <div class="result-card" style="position:static">
      <div class="result-head">
        <div class="result-dest">Net profit</div>
        <div class="result-profit"><?= money($trip['net_profit']) ?></div>
        <div class="result-sub"><span class="badge <?= $marginClass ?>"><?= num($trip['margin_pct'],1) ?>% margin</span></div>
      </div>
      <div class="brk">
        <div class="brk-title">Money</div>
        <table class="data brk-table"><tbody>
          <tr><td>Rate</td><td class="num"><?= money($trip['rate']) ?></td></tr>
          <?php if ($trip['genset_amount']>0): ?><tr><td>Genset</td><td class="num"><?= money($trip['genset_amount']) ?></td></tr><?php endif; ?>
          <?php if ($trip['standing_amount']>0): ?><tr><td>Standing (<?= num($trip['standing_hrs'],1) ?>h)</td><td class="num"><?= money($trip['standing_amount']) ?></td></tr><?php endif; ?>
          <?php if ($trip['surcharge_total']>0): ?><tr><td>Surcharges</td><td class="num"><?= money($trip['surcharge_total']) ?></td></tr><?php endif; ?>
          <tr class="brk-strong"><td><b>Revenue (excl VAT)</b></td><td class="num"><b><?= money($trip['gross_revenue']) ?></b></td></tr>
          <tr><td class="muted">VAT</td><td class="num muted"><?= money($vat) ?></td></tr>
          <tr><td class="muted">Revenue (incl VAT)</td><td class="num muted"><?= money($trip['gross_revenue'] + $vat) ?></td></tr>
          <tr><td style="color:var(--bad)">Operating cost</td><td class="num" style="color:var(--bad)">−<?= money($trip['est_cost']) ?></td></tr>
          <tr class="brk-strong"><td style="color:var(--good)"><b>Net profit</b></td><td class="num" style="color:var(--good);font-weight:700"><?= money($trip['net_profit']) ?></td></tr>
          <tr><td class="muted">Commission</td><td class="num muted"><?= money($trip['commission']) ?></td></tr>
          <?php if ($trip['subbie_payout']>0): ?><tr><td class="muted">Subbie payout</td><td class="num muted"><?= money($trip['subbie_payout']) ?></td></tr><?php endif; ?>
          <?php if (isset($trip['driver_pay']) && $trip['driver_pay']>0): ?><tr><td class="muted">Driver pay</td><td class="num muted"><?= money($trip['driver_pay']) ?></td></tr><?php endif; ?>
        </tbody></table>
      </div>
    </div>

    <!-- POD collection -->
    <div class="card">
      <h3>📄 Proof of delivery</h3>
      <?php if (!empty($trip['pod_path'])): ?>
        <a href="<?= BASE_URL ?>/<?= e($trip['pod_path']) ?>" target="_blank" class="btn ghost block" style="margin-bottom:12px">View uploaded POD (from driver)</a>
      <?php endif; ?>
      <?php if ($trip['pod_collected'] === 'collected'): ?>
        <div class="flash success" style="margin-bottom:14px">
          ✓ Collected<?= $trip['pod_collector'] ? ' by ' . e($trip['pod_collector']) : '' ?>
          on <?= fdate($trip['pod_collected_at']) ?>. Ready to submit to the client.
        </div>
      <?php else: ?>
        <div class="flash warn" style="margin-bottom:14px">— Not collected. The POD is still with the driver.</div>
      <?php endif; ?>
      <?php if (can_edit_trips()): ?>
      <form method="post" action="<?= BASE_URL ?>/trip_view.php?id=<?= $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="toggle_pod">
        <button type="submit" class="btn <?= $trip['pod_collected']==='collected' ? 'ghost' : 'primary' ?> block">
          <?= $trip['pod_collected']==='collected' ? 'Mark as NOT collected' : '✓ Mark POD collected' ?>
        </button>
      </form>
      <?php endif; ?>
    </div>

    <?php if (can_edit_trips()): ?>
    <!-- Status -->
    <div class="card">
      <h3>Status</h3>
      <form method="post" action="<?= BASE_URL ?>/trip_view.php?id=<?= $id ?>" class="flex" style="gap:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_status">
        <select name="status" style="flex:1">
          <?php foreach ($statusList as $s): ?><option value="<?= e($s) ?>" <?= $trip['status']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn dark">Update</button>
      </form>
    </div>

    <!-- Flag / delete -->
    <div class="card">
      <h3>Review</h3>
      <form method="post" action="<?= BASE_URL ?>/trip_view.php?id=<?= $id ?>" style="margin-bottom:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="toggle_flag">
        <?php if ((int)$trip['flagged']===0): ?>
          <input type="text" name="flag_note" placeholder="Reason (optional)" style="margin-bottom:8px">
          <button type="submit" class="btn ghost block">⚑ Flag for review</button>
        <?php else: ?>
          <p class="muted" style="font-size:13px">Flagged<?= $trip['flag_note'] ? ': ' . e($trip['flag_note']) : '' ?></p>
          <button type="submit" class="btn ghost block">Clear flag</button>
        <?php endif; ?>
      </form>
      <?php if (is_master()): ?>
      <form method="post" action="<?= BASE_URL ?>/trip_view.php?id=<?= $id ?>" onsubmit="return confirm('Delete this trip permanently?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_trip">
        <button type="submit" class="btn ghost block" style="color:var(--bad)">Delete trip</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
