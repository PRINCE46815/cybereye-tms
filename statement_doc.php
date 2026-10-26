<?php
/**
 * TMS — statement_doc.php
 * Standalone, print-ready client statement.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoice_lib.php';
require_role('master', 'admin', 'finance', 'viewer');

$clientId = (int) get('client_id', 0);
$from = get('from', date('Y-m-01', strtotime('-2 months')));
$to   = get('to', date('Y-m-d'));
$client = db_row("SELECT id, name, code FROM clients WHERE id=? AND client_type='billing'", array($clientId));
if (!$client) { http_response_code(404); exit('Client not found.'); }

$rows = db_all(
    "SELECT i.*, COALESCE((SELECT SUM(amount) FROM payments p WHERE p.invoice_id=i.id),0) AS paid
     FROM invoices i WHERE i.client_id=? AND DATE(i.created_at) BETWEEN ? AND ?
     ORDER BY i.created_at ASC", array($clientId, $from, $to));
foreach ($rows as &$r) { $r['outstanding'] = max(0, $r['total'] - $r['paid']); }
unset($r);

$inner = render_statement_doc($client, $rows, $from, $to, '');
$mpdf  = mpdf_available();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Statement — <?= e($client['name']) ?></title>
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
    <a class="tb-btn ghost" href="<?= BASE_URL ?>/statements.php?client_id=<?= $clientId ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">← Back</a>
    <button class="tb-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
    <a class="tb-btn gold" href="<?= BASE_URL ?>/pdf.php?doc=statement&client_id=<?= $clientId ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">⬇ Download PDF<?= $mpdf ? '' : ' *' ?></a>
  </div>
  <div class="sheet"><?= $inner ?></div>
  <?php if (!$mpdf): ?>
  <div style="max-width:800px;margin:14px auto 0;color:#6b7280;font-size:12px;text-align:right">
    * One-click PDF activates after <code>composer require mpdf/mpdf</code>. Until then use Print → Save as PDF.
  </div>
  <?php endif; ?>
</body>
</html>
