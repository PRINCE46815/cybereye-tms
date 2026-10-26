<?php
/**
 * TMS — invoice_doc.php
 * Standalone, print-ready invoice. Print or Save-as-PDF from any browser;
 * the Download-PDF button uses mPDF once it's installed.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoice_lib.php';
require_role('master', 'admin', 'finance', 'viewer');

$id = (int) get('id', 0);
$inv = db_row("SELECT i.*, c.name AS client_name, c.code AS client_code FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?", array($id));
if (!$inv) { http_response_code(404); exit('Invoice not found.'); }

$client = array('id' => $inv['client_id'], 'name' => $inv['client_name'], 'code' => $inv['client_code']);
$trips = db_all(
    "SELECT t.*, sl.name AS line_name, tr.registration AS truck_reg
     FROM trips t LEFT JOIN clients sl ON sl.id=t.shipping_line_id LEFT JOIN trucks tr ON tr.id=t.truck_id
     WHERE t.invoice_id=? ORDER BY t.collection_date ASC, t.id ASC", array($id));

$inner = render_invoice_doc($inv, $client, $trips);
$mpdf  = mpdf_available();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($inv['invoice_number']) ?> — <?= e($inv['client_name']) ?></title>
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
    <a class="tb-btn ghost" href="<?= BASE_URL ?>/invoice_view.php?id=<?= $id ?>">← Back</a>
    <button class="tb-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
    <a class="tb-btn ghost" href="<?= BASE_URL ?>/invoice_xlsx.php?id=<?= $id ?>">⬇ Export to Excel</a>
    <a class="tb-btn gold" href="<?= BASE_URL ?>/pdf.php?doc=invoice&id=<?= $id ?>">⬇ Download PDF<?= $mpdf ? '' : ' *' ?></a>
  </div>
  <div class="sheet"><?= $inner ?></div>
  <?php if (!$mpdf): ?>
  <div style="max-width:800px;margin:14px auto 0;color:#6b7280;font-size:12px;text-align:right">
    * One-click PDF activates after <code>composer require mpdf/mpdf</code>. Until then use Print → Save as PDF.
  </div>
  <?php endif; ?>
</body>
</html>
