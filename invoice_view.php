<?php
/**
 * TMS — invoice_view.php
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoice_lib.php';
require_role('master', 'admin', 'finance', 'viewer');

$id = (int) get('id', 0);
$inv = db_row("SELECT i.*, c.name AS client_name, c.code AS client_code FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?", array($id));
if (!$inv) { flash('error', 'Invoice not found.'); redirect('invoices.php'); }

$pageTitle    = $inv['invoice_number'];
$pageSubtitle = 'Invoice';

$canFin = in_array($me['role'], array('master', 'admin', 'finance'), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canFin) {
    csrf_check();
    $action = post('action');

    if ($action === 'mark_sent') {
        dbq("UPDATE invoices SET status='sent' WHERE id=? AND status='draft'", array($id));
        audit('send', 'invoice', $id, $inv['invoice_number']);
        flash('success', 'Marked as sent.');
        redirect('invoice_view.php?id=' . $id);
    }

    if ($action === 'add_payment') {
        $amt = (float) clean_number(post('amount'));
        if ($amt <= 0) { flash('error', 'Enter a payment amount.'); redirect('invoice_view.php?id=' . $id); }
        db_insert("INSERT INTO payments (invoice_id, amount, paid_on, method, notes, created_by) VALUES (?,?,?,?,?,?)",
            array($id, round($amt, 2), (post('paid_on') ?: date('Y-m-d')), post('method'), post('notes'), $me['id']));
        invoice_refresh_status($id);
        audit('payment', 'invoice', $id, $inv['invoice_number'] . ' +' . money($amt));
        flash('success', 'Payment of ' . money($amt) . ' recorded.');
        redirect('invoice_view.php?id=' . $id);
    }

    if ($action === 'del_payment') {
        dbq("DELETE FROM payments WHERE id=? AND invoice_id=?", array((int) post('payment_id'), $id));
        invoice_refresh_status($id);
        flash('info', 'Payment removed.');
        redirect('invoice_view.php?id=' . $id);
    }

    if ($action === 'void' && is_master()) {
        dbq("UPDATE trips SET invoice_id=NULL, payment_status='unpaid', outstanding=0, status=IF(status='Paid','Delivered',status) WHERE invoice_id=?", array($id));
        dbq("DELETE FROM payments WHERE invoice_id=?", array($id));
        dbq("DELETE FROM invoices WHERE id=?", array($id));
        audit('void', 'invoice', $id, $inv['invoice_number']);
        flash('success', 'Invoice ' . $inv['invoice_number'] . ' voided; its trips are unbilled again.');
        redirect('invoices.php');
    }
}

$trips = db_all(
    "SELECT t.*, sl.name AS line_name, tr.registration AS truck_reg
     FROM trips t LEFT JOIN clients sl ON sl.id=t.shipping_line_id LEFT JOIN trucks tr ON tr.id=t.truck_id
     WHERE t.invoice_id=? ORDER BY t.collection_date ASC, t.id ASC", array($id));
$payments = db_all("SELECT p.*, u.name AS by_name FROM payments p LEFT JOIN users u ON u.id=p.created_by WHERE p.invoice_id=? ORDER BY p.paid_on ASC, p.id ASC", array($id));
$paid = invoice_paid($id);
$outstanding = max(0, $inv['total'] - $paid);

require_once __DIR__ . '/includes/header.php';

function inv_badge($s) {
    $map = array('draft'=>'muted','sent'=>'info','part'=>'warn','paid'=>'good');
    $lbl = array('draft'=>'Draft','sent'=>'Sent','part'=>'Part-paid','paid'=>'Paid');
    return '<span class="badge ' . (isset($map[$s])?$map[$s]:'muted') . '">' . (isset($lbl[$s])?$lbl[$s]:ucfirst($s)) . '</span>';
}
?>

<div class="subnav">
  <a href="<?= BASE_URL ?>/invoices.php" class="active">Invoices</a>
  <a href="<?= BASE_URL ?>/statements.php">Statements</a>
</div>

<div class="page-head">
  <div><h1>🧾 <?= e($inv['invoice_number']) ?> <?= inv_badge($inv['status']) ?></h1>
    <p><?= e($inv['client_name']) ?> · <?= e($inv['period_label'] ? $inv['period_label'] : (fdate($inv['period_start']).' – '.fdate($inv['period_end']))) ?></p></div>
  <div class="actions">
    <a href="<?= BASE_URL ?>/invoice_doc.php?id=<?= $id ?>" class="btn ghost" target="_blank">View / Print</a>
    <a href="<?= BASE_URL ?>/invoice_xlsx.php?id=<?= $id ?>" class="btn ghost">Export to Excel</a>
    <a href="<?= BASE_URL ?>/pdf.php?doc=invoice&id=<?= $id ?>" class="btn primary">Download PDF</a>
  </div>
</div>

<div class="grid-2">
  <div>
    <div class="card">
      <h3>Line items <span class="muted" style="font-weight:400;font-size:13px">(<?= count($trips) ?> trip<?= count($trips)===1?'':'s' ?>)</span></h3>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Ref</th><th>Date</th><th>Route</th><th>Container</th><th class="num">Amount</th></tr></thead>
          <tbody>
            <?php foreach ($trips as $t): ?>
              <tr>
                <td><a href="<?= BASE_URL ?>/trip_view.php?id=<?= (int)$t['id'] ?>"><b><?= e($t['trip_ref']) ?></b></a></td>
                <td class="muted"><?= fdate($t['collection_date']) ?></td>
                <td><?= e($t['route'] ? $t['route'] : '—') ?></td>
                <td><span class="badge muted"><?= e($t['container_size']) ?> <?= e($t['load_state']) ?></span> <?= e($t['container_number']) ?></td>
                <td class="num"><?= money($t['gross_revenue']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <h3>Payments</h3>
      <?php if (!$payments): ?>
        <p class="muted" style="font-size:13px">No payments recorded yet.</p>
      <?php else: ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>Date</th><th>Method</th><th>By</th><th class="num">Amount</th><?php if ($canFin): ?><th></th><?php endif; ?></tr></thead>
          <tbody>
            <?php foreach ($payments as $p): ?>
              <tr><td><?= fdate($p['paid_on']) ?></td><td><?= e($p['method'] ? $p['method'] : '—') ?></td><td class="muted"><?= e($p['by_name']) ?></td>
                <td class="num"><?= money($p['amount']) ?></td>
                <?php if ($canFin): ?><td style="text-align:right">
                  <form method="post" action="<?= BASE_URL ?>/invoice_view.php?id=<?= $id ?>" style="display:inline" onsubmit="return confirm('Remove this payment?');">
                    <?= csrf_field() ?><input type="hidden" name="action" value="del_payment"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                    <button class="btn ghost sm">✕</button></form>
                </td><?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>

      <?php if ($canFin && $outstanding > 0): ?>
      <form method="post" action="<?= BASE_URL ?>/invoice_view.php?id=<?= $id ?>" class="form-grid" style="margin-top:14px">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_payment">
        <div class="form-row"><label class="lbl">Amount</label>
          <div class="field-money"><input type="number" step="0.01" min="0" name="amount" value="<?= number_format($outstanding,2,'.','') ?>"></div></div>
        <div class="form-row"><label class="lbl">Date</label><input type="date" name="paid_on" value="<?= date('Y-m-d') ?>"></div>
        <div class="form-row"><label class="lbl">Method</label>
          <select name="method"><option>EFT</option><option>Cash</option><option>Card</option><option>Other</option></select></div>
        <div class="form-row" style="align-self:end"><button class="btn dark">Record payment</button></div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="result-card" style="position:static">
      <div class="result-head">
        <div class="result-dest">Balance due</div>
        <div class="result-profit"><?= money($outstanding) ?></div>
        <div class="result-sub"><?= inv_badge($inv['status']) ?></div>
      </div>
      <div class="brk">
        <table class="data brk-table"><tbody>
          <tr><td>Subtotal (excl VAT)</td><td class="num"><?= money($inv['subtotal']) ?></td></tr>
          <tr class="muted"><td>VAT @ <?= num(setting_num('vat_pct'),0) ?>%</td><td class="num muted"><?= money($inv['vat_amount']) ?></td></tr>
          <tr class="brk-strong"><td><b>Total</b></td><td class="num"><b><?= money($inv['total']) ?></b></td></tr>
          <tr><td class="muted">Paid</td><td class="num muted"><?= money($paid) ?></td></tr>
          <tr class="brk-strong"><td><b>Outstanding</b></td><td class="num"><b><?= money($outstanding) ?></b></td></tr>
        </tbody></table>
      </div>
    </div>

    <?php if ($canFin): ?>
    <div class="card">
      <h3>Actions</h3>
      <?php if ($inv['status'] === 'draft'): ?>
      <form method="post" action="<?= BASE_URL ?>/invoice_view.php?id=<?= $id ?>" style="margin-bottom:10px">
        <?= csrf_field() ?><input type="hidden" name="action" value="mark_sent">
        <button class="btn primary block">Mark as sent</button>
      </form>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>/invoice_doc.php?id=<?= $id ?>" class="btn ghost block" target="_blank" style="margin-bottom:10px">Open printable document</a>
      <?php if (is_master()): ?>
      <form method="post" action="<?= BASE_URL ?>/invoice_view.php?id=<?= $id ?>" onsubmit="return confirm('Void this invoice? Its trips become unbilled again.');">
        <?= csrf_field() ?><input type="hidden" name="action" value="void">
        <button class="btn ghost block" style="color:var(--bad)">Void invoice</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
