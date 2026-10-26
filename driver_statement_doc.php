<?php
/**
 * TMS — driver_statement_doc.php
 * Printable driver pay statement. Staff can view any driver; a driver
 * can view their own.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoice_lib.php';
require_login();

$driverId = (int) get('driver_id', 0);
$isStaff  = has_role('master', 'admin', 'finance');
$isOwn    = current_role() === 'driver' && (int) $me['driver_id'] === $driverId && $driverId > 0;
if (!$isStaff && !$isOwn) { http_response_code(403); exit('Access denied.'); }

$driver = db_row("SELECT * FROM drivers WHERE id=?", array($driverId));
if (!$driver) { http_response_code(404); exit('Driver not found.'); }

$from = get('from', date('Y-m-01'));
$to   = get('to', date('Y-m-d'));
$trips = db_all(
    "SELECT t.collection_date, t.container_number, t.route, t.container_size, t.load_state, t.driver_pay, c.name AS client_name
     FROM trips t LEFT JOIN clients c ON c.id=t.billing_client_id
     WHERE t.driver_id=? AND t.collection_date BETWEEN ? AND ?
     ORDER BY t.collection_date ASC, t.id ASC", array($driverId, $from, $to));

$inner = render_driver_statement_doc($driver, $trips, $from, $to, '');
$mpdf  = mpdf_available();
$backUrl = $isStaff ? (BASE_URL . '/driver_view.php?id=' . $driverId) : (BASE_URL . '/my_pay.php');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pay statement — <?= e($driver['name']) ?></title>
<style>
  @page { size: A4; margin: 14mm; }
  body { background:#e9eaec; margin:0; padding:24px; font-family:Arial, sans-serif; }
  .sheet { background:#fff; max-width:800px; margin:0 auto; padding:34px 40px; box-shadow:0 6px 30px rgba(0,0,0,.12); border-radius:4px; }
  .toolbar { max-width:800px; margin:0 auto 16px; display:flex; gap:10px; justify-content:flex-end; }
  .tb-btn { background:#15181D; color:#fff; border:none; border-radius:7px; padding:9px 16px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
  .tb-btn.gold { background:#C19A3E; color:#15181D; }
  .tb-btn.ghost { background:#fff; color:#15181D; border:1px solid #cfd2d6; }
  @media print { body{background:#fff;padding:0} .toolbar{display:none} .sheet{box-shadow:none;max-width:none;padding:0;border-radius:0} }
</style>
</head>
<body>
  <div class="toolbar">
    <a class="tb-btn ghost" href="<?= $backUrl ?>">← Back</a>
    <button class="tb-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
    <a class="tb-btn gold" href="<?= BASE_URL ?>/pdf.php?doc=driver_statement&driver_id=<?= $driverId ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">⬇ Download PDF<?= $mpdf ? '' : ' *' ?></a>
  </div>
  <div class="sheet"><?= $inner ?></div>
  <?php if (!$mpdf): ?>
  <div style="max-width:800px;margin:14px auto 0;color:#6b7280;font-size:12px;text-align:right">* One-click PDF activates after <code>composer require mpdf/mpdf</code>. Until then use Print → Save as PDF.</div>
  <?php endif; ?>
</body>
</html>
