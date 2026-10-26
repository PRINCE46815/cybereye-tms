<?php
/**
 * TMS — commission.php  (MASTER KEY ONLY)
 * Commission for a period, grouped by each subcontractor and by the company's
 * own trucks, with per-trip detail. Master sees this; nobody else.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/charts.php';
require_role('master');

$pageTitle    = 'Commission breakdown';
$pageSubtitle = 'Master key';

$from = get('from', date('Y-m-01'));
$to   = get('to', date('Y-m-d'));

$trips = db_all(
    "SELECT t.id, t.trip_ref, t.collection_date, t.route, t.rate, t.commission, t.subcontractor_id,
            s.name AS sub_name, tr.registration AS truck_reg, c.name AS client_name
     FROM trips t
     LEFT JOIN subcontractors s ON s.id = t.subcontractor_id
     LEFT JOIN trucks tr        ON tr.id = t.truck_id
     LEFT JOIN clients c        ON c.id = t.billing_client_id
     WHERE t.collection_date BETWEEN ? AND ? AND t.commission > 0
     ORDER BY t.collection_date ASC, t.id ASC", array($from, $to));

$groups = array();   // key => ['label','own'=>bool,'trips'=>[],'rate'=>0,'comm'=>0]
$totalComm = 0; $ownComm = 0; $subComm = 0;
foreach ($trips as $t) {
    $own = $t['subcontractor_id'] === null;
    $key = $own ? 'own' : ('s' . $t['subcontractor_id']);
    if (!isset($groups[$key])) {
        $groups[$key] = array('label' => $own ? 'Own trucks' : $t['sub_name'], 'own' => $own, 'trips' => array(), 'rate' => 0, 'comm' => 0);
    }
    $groups[$key]['trips'][] = $t;
    $groups[$key]['rate'] += (float) $t['rate'];
    $groups[$key]['comm'] += (float) $t['commission'];
    $totalComm += (float) $t['commission'];
    if ($own) { $ownComm += (float) $t['commission']; } else { $subComm += (float) $t['commission']; }
}
// own first, then subbies by commission desc
uasort($groups, function ($a, $b) {
    if ($a['own'] !== $b['own']) { return $a['own'] ? -1 : 1; }
    return $b['comm'] <=> $a['comm'];
});

$barRows = array();
foreach ($groups as $g) { $barRows[] = array('label' => $g['label'], 'value' => $g['comm'], 'meta' => count($g['trips']) . '×', 'tone' => $g['own'] ? 'ink' : 'gold'); }

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div><h1>🔑 Commission breakdown</h1><p>Master-key report — your earnings on own trucks and what you keep from each subbie.</p></div>
</div>

<div class="card">
  <form method="get" action="<?= BASE_URL ?>/commission.php" class="flex" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-row" style="margin:0"><label class="lbl">From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="form-row" style="margin:0"><label class="lbl">To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <button class="btn dark">Apply</button>
    <a href="<?= BASE_URL ?>/commission.php?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>" class="btn ghost">This year</a>
  </form>
</div>

<div class="kpis">
  <div class="kpi gold"><div class="label">Total commission</div><div class="value" style="font-size:21px"><?= money($totalComm) ?></div><div class="meta"><?= num(count($trips)) ?> trips</div></div>
  <div class="kpi"><div class="label">On own trucks</div><div class="value" style="font-size:20px"><?= money($ownComm) ?></div><div class="meta">your earnings</div></div>
  <div class="kpi"><div class="label">From subbies</div><div class="value" style="font-size:20px"><?= money($subComm) ?></div><div class="meta">kept on subbie loads</div></div>
</div>

<?php if (!$trips): ?>
  <div class="card"><div class="empty"><div class="big">◔</div>No commission-bearing trips in this period.</div></div>
<?php else: ?>

<div class="card">
  <h3>Commission by group</h3>
  <?= chart_hbars($barRows, array('money'=>true)) ?>
</div>

<div class="card">
  <h3>Summary</h3>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Group</th><th>Type</th><th class="num">Trips</th><th class="num">Total rate</th><th class="num">Commission</th></tr></thead>
      <tbody>
        <?php foreach ($groups as $g): ?>
          <tr>
            <td><b><?= e($g['label']) ?></b></td>
            <td><?= $g['own'] ? '<span class="badge gold">Own</span>' : '<span class="badge muted">Subbie</span>' ?></td>
            <td class="num"><?= num(count($g['trips'])) ?></td>
            <td class="num"><?= money($g['rate']) ?></td>
            <td class="num"><b><?= money($g['comm']) ?></b></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr style="border-top:2px solid var(--line)">
        <td colspan="4"><b>Total commission</b></td><td class="num"><b><?= money($totalComm) ?></b></td>
      </tr></tfoot>
    </table>
  </div>
</div>

<!-- Per-group detail -->
<?php foreach ($groups as $g): ?>
<div class="card">
  <h3><?= e($g['label']) ?> <span class="muted" style="font-weight:400;font-size:13px">· <?= money($g['comm']) ?> over <?= count($g['trips']) ?> trip<?= count($g['trips'])===1?'':'s' ?></span></h3>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Ref</th><th>Date</th><th>Truck</th><th>Client</th><th>Route</th><th class="num">Rate</th><th class="num">Commission</th></tr></thead>
      <tbody>
        <?php foreach ($g['trips'] as $t): ?>
          <tr>
            <td><a href="<?= BASE_URL ?>/trip_view.php?id=<?= (int)$t['id'] ?>"><b><?= e($t['trip_ref']) ?></b></a></td>
            <td class="muted"><?= fdate($t['collection_date']) ?></td>
            <td><?= e($t['truck_reg'] ? $t['truck_reg'] : '—') ?></td>
            <td><?= e($t['client_name'] ? $t['client_name'] : '—') ?></td>
            <td><?= e($t['route'] ? $t['route'] : '—') ?></td>
            <td class="num"><?= money($t['rate']) ?></td>
            <td class="num"><b><?= money($t['commission']) ?></b></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
