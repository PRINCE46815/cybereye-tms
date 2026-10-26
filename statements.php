<?php
/**
 * TMS — statements.php
 * Choose a client + period; see the balance and open the printable statement.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoice_lib.php';
require_role('master', 'admin', 'finance', 'viewer');

$pageTitle    = 'Statements';
$pageSubtitle = 'Finance';

$clients  = db_all("SELECT id, name, code FROM clients WHERE client_type='billing' ORDER BY name");
$clientId = (int) get('client_id', 0);
$from     = get('from', date('Y-m-01', strtotime('-2 months')));
$to       = get('to', date('Y-m-d'));
$client   = $clientId ? db_row("SELECT id, name, code FROM clients WHERE id=? AND client_type='billing'", array($clientId)) : null;

$rows = array(); $totBilled = 0; $totPaid = 0; $totOut = 0;
if ($client) {
    $rows = db_all(
        "SELECT i.*, COALESCE((SELECT SUM(amount) FROM payments p WHERE p.invoice_id=i.id),0) AS paid
         FROM invoices i WHERE i.client_id=? AND DATE(i.created_at) BETWEEN ? AND ?
         ORDER BY i.created_at ASC", array($clientId, $from, $to));
    foreach ($rows as &$r) { $r['outstanding'] = max(0, $r['total'] - $r['paid']); $totBilled += $r['total']; $totPaid += $r['paid']; $totOut += $r['outstanding']; }
    unset($r);
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/invoices.php">Invoices</a>
  <a href="<?= BASE_URL ?>/statements.php" class="active">Statements</a>
</div>

<div class="page-head">
  <div><h1>Statements</h1><p>A running account per client — invoices, payments and the balance due.</p></div>
</div>

<div class="card">
  <form method="get" action="<?= BASE_URL ?>/statements.php" class="form-grid" style="margin-bottom:0">
    <div class="form-row"><label class="lbl">Client</label>
      <select name="client_id" required><option value="0">— choose —</option>
        <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $clientId===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="form-row"><label class="lbl">From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="form-row"><label class="lbl">To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="form-row" style="align-self:end"><button class="btn dark">Build statement</button></div>
  </form>
</div>

<?php if ($client): ?>
<div class="page-head" style="margin-bottom:14px">
  <div><h2 style="margin:0;font-size:18px"><?= e($client['name']) ?></h2>
    <p class="muted" style="margin:2px 0 0"><?= fdate($from) ?> – <?= fdate($to) ?></p></div>
  <div class="actions">
    <a href="<?= BASE_URL ?>/statement_doc.php?client_id=<?= (int)$client['id'] ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn ghost" target="_blank">View / Print</a>
    <a href="<?= BASE_URL ?>/pdf.php?doc=statement&client_id=<?= (int)$client['id'] ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="btn primary">Download PDF</a>
  </div>
</div>

<div class="kpis">
  <div class="kpi good"><div class="label">Invoiced</div><div class="value" style="font-size:20px"><?= money($totBilled) ?></div></div>
  <div class="kpi"><div class="label">Paid</div><div class="value" style="font-size:20px"><?= money($totPaid) ?></div></div>
  <div class="kpi bad"><div class="label">Balance due</div><div class="value" style="font-size:20px"><?= money($totOut) ?></div></div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Invoice No</th><th>Date</th><th>Status</th><th class="num">Invoiced</th><th class="num">Paid</th><th class="num">Outstanding</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6"><div class="empty"><div class="big">📄</div>No invoices for <?= e($client['name']) ?> in this period.</div></td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><a href="<?= BASE_URL ?>/invoice_view.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['invoice_number']) ?></b></a></td>
            <td class="muted"><?= fdate($r['created_at']) ?></td>
            <td><?= e(ucfirst($r['status'])) ?></td>
            <td class="num"><?= money($r['total']) ?></td>
            <td class="num"><?= money($r['paid']) ?></td>
            <td class="num"><b><?= money($r['outstanding']) ?></b></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
