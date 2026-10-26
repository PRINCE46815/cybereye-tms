<?php
/**
 * TMS — finance.php  (Debtors)
 * Receivables ageing (0–30 / 31–60 / 61–90 / 90+) per client, plus the
 * list of outstanding invoices. Ages run from the invoice date.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/charts.php';
require_role('master', 'admin', 'finance', 'viewer');

$pageTitle    = 'Finance & Debtors';
$pageSubtitle = 'Finance';

$open = db_all(
    "SELECT i.id, i.invoice_number, i.client_id, c.name AS client_name, i.created_at, i.total, i.status,
            COALESCE((SELECT SUM(amount) FROM payments p WHERE p.invoice_id=i.id),0) AS paid
     FROM invoices i JOIN clients c ON c.id=i.client_id
     WHERE i.status <> 'paid' ORDER BY i.created_at ASC");

$buckets = array('b0'=>0,'b1'=>0,'b2'=>0,'b3'=>0);     // 0-30, 31-60, 61-90, 90+
$byClient = array();
$rowsOut = array();
$today = time();

foreach ($open as $inv) {
    $out = max(0, (float) $inv['total'] - (float) $inv['paid']);
    if ($out <= 0.005) { continue; }
    $age = (int) floor(($today - strtotime($inv['created_at'])) / 86400);
    if     ($age <= 30) $b = 'b0';
    elseif ($age <= 60) $b = 'b1';
    elseif ($age <= 90) $b = 'b2';
    else                $b = 'b3';

    $buckets[$b] += $out;
    $cid = (int) $inv['client_id'];
    if (!isset($byClient[$cid])) { $byClient[$cid] = array('name'=>$inv['client_name'],'b0'=>0,'b1'=>0,'b2'=>0,'b3'=>0,'total'=>0); }
    $byClient[$cid][$b] += $out;
    $byClient[$cid]['total'] += $out;

    $inv['outstanding'] = $out; $inv['age'] = $age; $inv['bucket'] = $b;
    $rowsOut[] = $inv;
}
$grand = $buckets['b0'] + $buckets['b1'] + $buckets['b2'] + $buckets['b3'];

// Order clients by total owed desc
uasort($byClient, function ($a, $b) { return $b['total'] <=> $a['total']; });

$bucketLabels = array('b0'=>'0–30 days','b1'=>'31–60 days','b2'=>'61–90 days','b3'=>'90+ days');

function ageBadge($b) {
    $m = array('b0'=>'good','b1'=>'info','b2'=>'warn','b3'=>'bad');
    $l = array('b0'=>'0–30','b1'=>'31–60','b2'=>'61–90','b3'=>'90+');
    return '<span class="badge ' . $m[$b] . '">' . $l[$b] . '</span>';
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/invoices.php">Invoices</a>
  <a href="<?= BASE_URL ?>/statements.php">Statements</a>
  <a href="<?= BASE_URL ?>/finance.php" class="active">Debtors</a>
</div>

<div class="page-head">
  <div><h1>Finance &amp; Debtors</h1><p><?= money($grand) ?> outstanding across <?= count($byClient) ?> client<?= count($byClient)===1?'':'s' ?>.</p></div>
</div>

<!-- Ageing buckets -->
<div class="card">
  <h3>Receivables ageing</h3>
  <div class="age-grid">
    <div class="age-cell"><div class="lab">0–30 days</div><div class="amt"><?= money($buckets['b0']) ?></div></div>
    <div class="age-cell"><div class="lab">31–60 days</div><div class="amt"><?= money($buckets['b1']) ?></div></div>
    <div class="age-cell <?= $buckets['b2']>0?'hot':'' ?>"><div class="lab">61–90 days</div><div class="amt"><?= money($buckets['b2']) ?></div></div>
    <div class="age-cell <?= $buckets['b3']>0?'hot':'' ?>"><div class="lab">90+ days</div><div class="amt"><?= money($buckets['b3']) ?></div></div>
  </div>
  <?= chart_hbars(array(
        array('label'=>'0–30 days','value'=>$buckets['b0'],'tone'=>'good'),
        array('label'=>'31–60 days','value'=>$buckets['b1'],'tone'=>'gold'),
        array('label'=>'61–90 days','value'=>$buckets['b2'],'tone'=>'gold'),
        array('label'=>'90+ days','value'=>$buckets['b3'],'tone'=>'bad'),
      ), array('money'=>true)) ?>
</div>

<!-- Per client ageing -->
<div class="card">
  <h3>By client</h3>
  <?php if (!$byClient): ?>
    <div class="empty"><div class="big">✓</div>Nothing outstanding — every invoice is paid.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Client</th><th class="num">0–30</th><th class="num">31–60</th><th class="num">61–90</th><th class="num">90+</th><th class="num">Total owed</th></tr></thead>
      <tbody>
        <?php foreach ($byClient as $cid => $c): ?>
          <tr>
            <td><b><?= e($c['name']) ?></b></td>
            <td class="num"><?= $c['b0']>0?money($c['b0']):'—' ?></td>
            <td class="num"><?= $c['b1']>0?money($c['b1']):'—' ?></td>
            <td class="num"><?= $c['b2']>0?'<span style="color:var(--bad)">'.money($c['b2']).'</span>':'—' ?></td>
            <td class="num"><?= $c['b3']>0?'<span style="color:var(--bad)">'.money($c['b3']).'</span>':'—' ?></td>
            <td class="num"><b><?= money($c['total']) ?></b></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr style="border-top:2px solid var(--line)">
        <td><b>All clients</b></td>
        <td class="num"><b><?= money($buckets['b0']) ?></b></td>
        <td class="num"><b><?= money($buckets['b1']) ?></b></td>
        <td class="num"><b><?= money($buckets['b2']) ?></b></td>
        <td class="num"><b><?= money($buckets['b3']) ?></b></td>
        <td class="num"><b><?= money($grand) ?></b></td>
      </tr></tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Outstanding invoices -->
<?php if ($rowsOut): ?>
<div class="card">
  <h3>Outstanding invoices</h3>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Invoice</th><th>Client</th><th>Date</th><th class="num">Age</th><th>Bucket</th><th class="num">Outstanding</th><th style="text-align:right">·</th></tr></thead>
      <tbody>
        <?php foreach ($rowsOut as $r): ?>
          <tr>
            <td><a href="<?= BASE_URL ?>/invoice_view.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['invoice_number']) ?></b></a></td>
            <td><?= e($r['client_name']) ?></td>
            <td class="muted"><?= fdate($r['created_at']) ?></td>
            <td class="num"><?= (int)$r['age'] ?>d</td>
            <td><?= ageBadge($r['bucket']) ?></td>
            <td class="num"><b><?= money($r['outstanding']) ?></b></td>
            <td style="text-align:right"><a href="<?= BASE_URL ?>/invoice_view.php?id=<?= (int)$r['id'] ?>" class="btn ghost sm">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
