<?php
/**
 * TMS — search.php
 * One box to find any trip: ref, container, T.O, route, or by client,
 * truck or driver name.
 */
require_once __DIR__ . '/includes/auth.php';
require_role('master', 'admin', 'finance', 'viewer');

$pageTitle    = 'Search Trips';
$pageSubtitle = 'Operations';

$q = trim(get('q', ''));
$rows = array();
if ($q !== '') {
    $like = '%' . $q . '%';
    $rows = db_all(
        "SELECT t.id, t.trip_ref, t.collection_date, t.route, t.container_number, t.container_size, t.load_state,
                t.to_number, t.status, t.net_profit, c.name AS client_name, tr.registration AS truck_reg, dr.name AS driver_name
         FROM trips t
         LEFT JOIN clients c  ON c.id = t.billing_client_id
         LEFT JOIN trucks tr  ON tr.id = t.truck_id
         LEFT JOIN drivers dr ON dr.id = t.driver_id
         WHERE t.trip_ref LIKE ? OR t.container_number LIKE ? OR t.to_number LIKE ? OR t.route LIKE ?
            OR c.name LIKE ? OR tr.registration LIKE ? OR dr.name LIKE ?
         ORDER BY COALESCE(t.collection_date, t.created_at) DESC, t.id DESC
         LIMIT 100",
        array($like, $like, $like, $like, $like, $like, $like));
}

function st_badge($s) {
    $map = array('Draft'=>'muted','Scheduled'=>'info','In Transit'=>'gold','Delivered'=>'good','Invoiced'=>'info','Paid'=>'good','On Hold'=>'warn','Cancelled'=>'bad');
    return '<span class="badge ' . (isset($map[$s])?$map[$s]:'muted') . '">' . e($s) . '</span>';
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div><h1>Search Trips</h1><p>Find a load by reference, container, T.O, route, client, truck or driver.</p></div>
</div>

<div class="card">
  <form method="get" action="<?= BASE_URL ?>/search.php" class="flex" style="gap:12px;align-items:flex-end">
    <div class="form-row" style="margin:0;flex:1"><label class="lbl">Search</label>
      <input type="text" name="q" value="<?= e($q) ?>" autofocus placeholder="e.g. TLLU1234567, MC08RKGP, Worcester, RT00012"></div>
    <button class="btn primary">Search</button>
    <?php if ($q !== ''): ?><a href="<?= BASE_URL ?>/search.php" class="btn ghost">Clear</a><?php endif; ?>
  </form>
</div>

<?php if ($q !== ''): ?>
<div class="card">
  <h3><?= count($rows) ?> result<?= count($rows)===1?'':'s' ?> for “<?= e($q) ?>”<?= count($rows)===100 ? ' (showing first 100)' : '' ?></h3>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Ref</th><th>Date</th><th>Client</th><th>Route</th><th>Container</th><th>Truck</th><th>Driver</th><th>Status</th><th style="text-align:right">·</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9"><div class="empty"><div class="big">⌕</div>No trips match “<?= e($q) ?>”.</div></td></tr>
        <?php else: foreach ($rows as $t): ?>
          <tr>
            <td><a href="<?= BASE_URL ?>/trip_view.php?id=<?= (int)$t['id'] ?>"><b><?= e($t['trip_ref']) ?></b></a></td>
            <td class="muted"><?= fdate($t['collection_date']) ?></td>
            <td><?= e($t['client_name'] ? $t['client_name'] : '—') ?></td>
            <td><?= e($t['route'] ? $t['route'] : '—') ?></td>
            <td><span class="badge muted"><?= e($t['container_size']) ?></span> <?= e($t['container_number'] ? $t['container_number'] : '—') ?></td>
            <td><?= e($t['truck_reg'] ? $t['truck_reg'] : '—') ?></td>
            <td><?= e($t['driver_name'] ? $t['driver_name'] : '—') ?></td>
            <td><?= st_badge($t['status']) ?></td>
            <td style="text-align:right"><a href="<?= BASE_URL ?>/trip_view.php?id=<?= (int)$t['id'] ?>" class="btn ghost sm">Open</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="card"><div class="empty"><div class="big">⌕</div>Type something above to find a trip.</div></div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
