<?php
/**
 * TMS — invoices.php  (list)
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoice_lib.php';
require_role('master', 'admin', 'finance', 'viewer');

$pageTitle    = 'Invoices';
$pageSubtitle = 'Finance';

$fClient = (int) get('client', 0);
$fStatus = get('status');

$where = array('1=1'); $args = array();
if ($fClient > 0) { $where[] = 'i.client_id = ?'; $args[] = $fClient; }
if ($fStatus !== '') { $where[] = 'i.status = ?'; $args[] = $fStatus; }
$whereSql = implode(' AND ', $where);

$rows = db_all(
    "SELECT i.*, c.name AS client_name,
            COALESCE((SELECT SUM(amount) FROM payments p WHERE p.invoice_id=i.id),0) AS paid
     FROM invoices i JOIN clients c ON c.id = i.client_id
     WHERE $whereSql ORDER BY i.created_at DESC, i.id DESC", $args);

$totBilled = 0; $totOut = 0;
foreach ($rows as &$r) { $r['outstanding'] = max(0, $r['total'] - $r['paid']); $totBilled += $r['total']; $totOut += $r['outstanding']; }
unset($r);

$clients = db_all("SELECT id, name FROM clients WHERE client_type='billing' ORDER BY name");

function inv_status_badge($s) {
    $map = array('draft'=>'muted','sent'=>'info','part'=>'warn','paid'=>'good');
    $lbl = array('draft'=>'Draft','sent'=>'Sent','part'=>'Part-paid','paid'=>'Paid');
    $c = isset($map[$s]) ? $map[$s] : 'muted';
    return '<span class="badge ' . $c . '">' . (isset($lbl[$s]) ? $lbl[$s] : ucfirst($s)) . '</span>';
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/invoices.php" class="active">Invoices</a>
  <a href="<?= BASE_URL ?>/statements.php">Statements</a>
</div>

<div class="page-head">
  <div><h1>Invoices</h1><p><?= count($rows) ?> invoice<?= count($rows)===1?'':'s' ?> · <?= money($totOut) ?> outstanding.</p></div>
  <?php if (can_edit_trips()): ?><div class="actions"><a href="<?= BASE_URL ?>/invoice_new.php" class="btn primary">＋ New invoice</a></div><?php endif; ?>
</div>

<div class="kpis">
  <div class="kpi good"><div class="label">Total invoiced</div><div class="value" style="font-size:20px"><?= money($totBilled) ?></div></div>
  <div class="kpi bad"><div class="label">Outstanding</div><div class="value" style="font-size:20px"><?= money($totOut) ?></div></div>
  <div class="kpi"><div class="label">Collected</div><div class="value" style="font-size:20px"><?= money($totBilled - $totOut) ?></div></div>
</div>

<div class="card">
  <form method="get" action="<?= BASE_URL ?>/invoices.php" class="flex" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-row" style="margin:0"><label class="lbl">Client</label>
      <select name="client"><option value="0">All clients</option>
        <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $fClient===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="form-row" style="margin:0"><label class="lbl">Status</label>
      <select name="status"><option value="">Any</option>
        <?php foreach (array('draft'=>'Draft','sent'=>'Sent','part'=>'Part-paid','paid'=>'Paid') as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $fStatus===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
      </select></div>
    <button type="submit" class="btn dark">Filter</button>
    <a href="<?= BASE_URL ?>/invoices.php" class="btn ghost">Clear</a>
  </form>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Invoice No</th><th>Client</th><th>Period</th><th>Date</th><th class="num">Total</th><th class="num">Outstanding</th><th>Status</th><th style="text-align:right">·</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8"><div class="empty"><div class="big">🧾</div>No invoices yet.
            <?php if (can_edit_trips()): ?><br><a href="<?= BASE_URL ?>/invoice_new.php">Create your first invoice →</a><?php endif; ?></div></td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><a href="<?= BASE_URL ?>/invoice_view.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['invoice_number']) ?></b></a></td>
            <td><?= e($r['client_name']) ?></td>
            <td class="muted"><?= e($r['period_label'] ? $r['period_label'] : (fdate($r['period_start']).' – '.fdate($r['period_end']))) ?></td>
            <td class="muted"><?= fdate($r['created_at']) ?></td>
            <td class="num"><?= money($r['total']) ?></td>
            <td class="num"><b><?= money($r['outstanding']) ?></b></td>
            <td><?= inv_status_badge($r['status']) ?></td>
            <td style="text-align:right"><a href="<?= BASE_URL ?>/invoice_view.php?id=<?= (int)$r['id'] ?>" class="btn ghost sm">Open</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
