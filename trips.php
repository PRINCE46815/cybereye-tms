<?php
/**
 * TMS — trips.php
 * The operations hub: every trip, with search, filters and live stats.
 */
require_once __DIR__ . '/includes/auth.php';
require_role('master', 'admin', 'finance', 'viewer');

$pageTitle    = 'Loads / Trips';
$pageSubtitle = 'Operations';

/* ---- Filters ---- */
$q      = get('q');
$fClient= (int) get('client', 0);
$fStat  = get('status');
$fPod   = get('pod');           // collected | not | (any)
$fFrom  = get('from');
$fTo    = get('to');
$page   = max(1, (int) get('page', 1));
$perPage= 25;

$where = array("1=1");
$args  = array();
if ($q !== '') {
    $where[] = "(t.trip_ref LIKE ? OR t.container_number LIKE ? OR t.route LIKE ? OR t.to_number LIKE ?)";
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like);
}
if ($fClient > 0) { $where[] = "t.billing_client_id = ?"; $args[] = $fClient; }
if ($fStat !== '') { $where[] = "t.status = ?"; $args[] = $fStat; }
if ($fPod === 'collected') { $where[] = "t.pod_collected = 'collected'"; }
if ($fPod === 'not')       { $where[] = "t.pod_collected = 'not_collected'"; }
if ($fFrom !== '') { $where[] = "t.collection_date >= ?"; $args[] = $fFrom; }
if ($fTo !== '')   { $where[] = "t.collection_date <= ?"; $args[] = $fTo; }
$whereSql = implode(' AND ', $where);

$total = (int) db_val("SELECT COUNT(*) FROM trips t WHERE $whereSql", $args);
$pages = max(1, (int) ceil($total / $perPage));
$page  = min($page, $pages);
$offset= ($page - 1) * $perPage;

$rows = db_all(
    "SELECT t.*, c.name AS client_name, c.code AS client_code, d.name AS dest_name
     FROM trips t
     LEFT JOIN clients c     ON c.id = t.billing_client_id
     LEFT JOIN destinations d ON d.id = t.destination_id
     WHERE $whereSql
     ORDER BY COALESCE(t.collection_date, t.created_at) DESC, t.id DESC
     LIMIT $perPage OFFSET $offset", $args);

/* ---- Stats (respect filters where useful; here overall + this month) ---- */
$monthStart = date('Y-m-01');
$statRevenue = (float) db_val("SELECT COALESCE(SUM(gross_revenue),0) FROM trips WHERE collection_date >= ?", array($monthStart));
$statProfit  = (float) db_val("SELECT COALESCE(SUM(net_profit),0) FROM trips WHERE collection_date >= ?", array($monthStart));
$statOut     = (float) db_val("SELECT COALESCE(SUM(outstanding),0) FROM trips WHERE payment_status <> 'paid'");
$statPod     = (int) db_val("SELECT COUNT(*) FROM trips WHERE pod_collected='not_collected' AND status IN ('Delivered','Invoiced','Paid')");

$clientsFilter = db_all("SELECT id, name FROM clients WHERE client_type='billing' ORDER BY name");
$statusList = array('Draft','Scheduled','In Transit','Delivered','Invoiced','Paid','On Hold','Cancelled');

function statusBadge($s) {
    $map = array('Draft'=>'muted','Scheduled'=>'info','In Transit'=>'gold','Delivered'=>'good',
                 'Invoiced'=>'info','Paid'=>'good','On Hold'=>'warn','Cancelled'=>'bad');
    $cls = isset($map[$s]) ? $map[$s] : 'muted';
    return '<span class="badge ' . $cls . '">' . e($s) . '</span>';
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div><h1>Loads / Trips</h1><p><?= num($total) ?> trip<?= $total===1?'':'s' ?> matching.</p></div>
  <?php if (can_edit_trips()): ?>
  <div class="actions"><a href="<?= BASE_URL ?>/trip_form.php" class="btn primary">＋ New trip</a></div>
  <?php endif; ?>
</div>

<!-- Stats -->
<div class="kpis">
  <div class="kpi good"><div class="label">Revenue (MTD)</div><div class="value" style="font-size:20px"><?= money($statRevenue) ?></div></div>
  <div class="kpi"><div class="label">Net profit (MTD)</div><div class="value" style="font-size:20px"><?= money($statProfit) ?></div></div>
  <div class="kpi bad"><div class="label">Outstanding</div><div class="value" style="font-size:20px"><?= money($statOut) ?></div></div>
  <div class="kpi"><div class="label">PODs to collect</div><div class="value"><?= $statPod ?></div><div class="meta">delivered, not yet collected</div></div>
</div>

<!-- Filters -->
<div class="card">
  <form method="get" action="<?= BASE_URL ?>/trips.php">
    <div class="form-grid" style="margin-bottom:0">
      <div class="form-row"><label class="lbl">Search</label>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="ref, container, route, T.O"></div>
      <div class="form-row"><label class="lbl">Client</label>
        <select name="client"><option value="0">All clients</option>
          <?php foreach ($clientsFilter as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $fClient===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="form-row"><label class="lbl">Status</label>
        <select name="status"><option value="">Any status</option>
          <?php foreach ($statusList as $s): ?><option value="<?= e($s) ?>" <?= $fStat===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?>
        </select></div>
      <div class="form-row"><label class="lbl">POD</label>
        <select name="pod"><option value="">Any</option>
          <option value="not" <?= $fPod==='not'?'selected':'' ?>>Not collected</option>
          <option value="collected" <?= $fPod==='collected'?'selected':'' ?>>Collected</option>
        </select></div>
      <div class="form-row"><label class="lbl">From</label><input type="date" name="from" value="<?= e($fFrom) ?>"></div>
      <div class="form-row"><label class="lbl">To</label><input type="date" name="to" value="<?= e($fTo) ?>"></div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn dark">Apply filters</button>
      <a href="<?= BASE_URL ?>/trips.php" class="btn ghost">Clear</a>
    </div>
  </form>
</div>

<!-- Table -->
<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Ref</th><th>Date</th><th>Client</th><th>Route</th><th>Container</th>
        <th class="num">Rate</th><th class="num">Profit</th><th>Status</th><th>POD</th><th style="text-align:right">·</th>
      </tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="10"><div class="empty"><div class="big">▤</div>No trips yet.
            <?php if (can_edit_trips()): ?><br><a href="<?= BASE_URL ?>/trip_form.php">Capture your first trip →</a><?php endif; ?></div></td></tr>
        <?php else: foreach ($rows as $t): ?>
          <tr>
            <td><a href="<?= BASE_URL ?>/trip_view.php?id=<?= (int)$t['id'] ?>"><b><?= e($t['trip_ref']) ?></b></a></td>
            <td class="muted"><?= fdate($t['collection_date']) ?></td>
            <td><?= e($t['client_name'] ? $t['client_name'] : '—') ?></td>
            <td><?= e($t['route'] ? $t['route'] : ($t['dest_name'] ? $t['dest_name'] : '—')) ?></td>
            <td><span class="badge muted"><?= e($t['container_size']) ?> <?= e($t['load_state']) ?></span></td>
            <td class="num"><?= money($t['rate']) ?></td>
            <td class="num"><b><?= money($t['net_profit']) ?></b></td>
            <td><?= statusBadge($t['status']) ?></td>
            <td><?= $t['pod_collected']==='collected' ? '<span class="badge good">✓</span>' : '<span class="badge warn">—</span>' ?></td>
            <td style="text-align:right"><a href="<?= BASE_URL ?>/trip_view.php?id=<?= (int)$t['id'] ?>" class="btn ghost sm">View</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="flex" style="justify-content:center;gap:8px;margin-top:16px">
      <?php
        $qs = $_GET; 
        for ($p = 1; $p <= $pages; $p++) {
          $qs['page'] = $p;
          $active = $p === $page ? 'dark' : 'ghost';
          echo '<a class="btn ' . $active . ' sm" href="' . BASE_URL . '/trips.php?' . e(http_build_query($qs)) . '">' . $p . '</a>';
        }
      ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
