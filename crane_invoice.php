<?php
/**
 * TMS — crane_invoice.php
 * Printable TAX INVOICE (or QUOTATION while still a quote) for a crane truck job.
 * Use the Print button → "Save as PDF" to email it.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoice_lib.php';
require_role('master', 'admin', 'finance');

$id  = (int) get('id', 0);
$job = db_row("SELECT * FROM crane_jobs WHERE id = ?", array($id));
if (!$job) { http_response_code(404); echo 'Document not found.'; exit; }

$co      = company_identity();
$isQuote = ($job['status'] === 'quote');
$title   = $isQuote ? 'QUOTATION' : 'TAX INVOICE';
$docNo   = $isQuote ? $job['quote_no'] : $job['invoice_no'];
$docDate = $isQuote ? $job['job_date'] : ($job['invoice_date'] ?: $job['job_date']);
$ref     = $isQuote ? ($job['customer_ref'] ?: '') : ($job['quote_no'] ?: $job['customer_ref']);
$d       = function ($s) { return $s ? date('j M Y', strtotime($s)) : ''; };
$n       = function ($v) { return number_format((float) $v, 2); };
$hrs     = rtrim(rtrim(number_format(max((float) $job['crew_hours'], (float) setting_num('crane_min_hours')), 2, '.', ''), '0'), '.');
$vatp    = rtrim(rtrim(number_format((float) $job['vat_pct'], 2, '.', ''), '0'), '.');
$addrLines = array_filter(array_map('trim', preg_split('/[,\n]+/', (string) $co['company_address'])));
$custLines = array_filter(array_map('trim', preg_split('/[,\n]+/', (string) $job['customer_address'])));
$logo = is_file(__DIR__ . '/assets/img/logo.png') ? BASE_URL . '/assets/img/logo.png' : '';

$lines = array();
$lines[] = array('Crane Hire ' . $hrs . ' hours standard rate (3-man crew)', $job['crew_charge']);
$lines[] = array('Call-out - ' . $job['destination'], $job['callout_charge']);
if ($job['extra_desc'] && (float) $job['extra_amount'] > 0) { $lines[] = array($job['extra_desc'], $job['extra_amount']); }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title . ' ' . $docNo) ?></title>
<style>
  @page { size: A4; margin: 14mm; }
  *{box-sizing:border-box}
  body{font-family:Calibri,Carlito,Arial,Helvetica,sans-serif;color:#111;font-size:13px;margin:0;background:#eef0f3}
  .bar{max-width:210mm;margin:12px auto 0;display:flex;gap:8px;justify-content:space-between;align-items:center;padding:0 4px}
  .bar a,.bar button{font:600 13px Arial;padding:8px 14px;border-radius:6px;border:1px solid <?= brand_color() ?>;background:<?= brand_color() ?>;color:#fff;text-decoration:none;cursor:pointer}
  .bar a.ghost{background:#fff;color:<?= brand_color() ?>}
  .page{width:210mm;min-height:297mm;margin:10px auto 30px;background:#fff;padding:14mm 14mm 12mm;position:relative;box-shadow:0 2px 14px rgba(0,0,0,.12)}
  .logo{text-align:right;height:30mm}
  .logo img{max-height:30mm;max-width:75mm;object-fit:contain}
  .top{display:grid;grid-template-columns:1.15fr 1fr 1fr;gap:10mm;margin-top:6mm}
  h1{font-size:30px;font-weight:400;margin:0 0 10px;letter-spacing:.3px}
  .cust{padding-left:6mm;line-height:1.45}
  .meta b{display:block;margin-top:8px}
  .meta b:first-child{margin-top:0}
  .co{line-height:1.45}
  table{width:100%;border-collapse:collapse;margin-top:16mm}
  th{text-align:left;font-weight:700;padding:6px 6px;border-bottom:1px solid #111;font-size:12.5px}
  td{padding:7px 6px;border-bottom:1px solid #d9d9d9;vertical-align:top}
  .r{text-align:right}
  .tot td{border:none;padding:4px 6px}
  .tot .gt td{border-top:1px solid #111;font-weight:700;padding-top:7px}
  .due{margin-top:12mm;font-weight:700;font-size:15px}
  .bank{margin-top:8px;line-height:1.5}
  .foot{position:absolute;left:14mm;right:14mm;bottom:10mm;font-size:10px;color:#222}
  .stamp{position:absolute;top:60mm;right:14mm;border:3px solid #b03030;color:#b03030;padding:4px 14px;font:700 26px Arial;transform:rotate(-12deg);opacity:.75}
  @media print{body{background:#fff}.bar{display:none}.page{box-shadow:none;margin:0;width:auto;min-height:0;padding:0}.foot{position:fixed}}
</style>
</head>
<body>
<div class="bar">
  <a class="ghost" href="<?= BASE_URL ?>/crane.php#jobs">← Back to Crane Truck</a>
  <button onclick="window.print()">🖨 Print / Save as PDF</button>
</div>
<div class="page">
  <?php if ($job['status'] === 'cancelled'): ?><div class="stamp">CANCELLED</div><?php endif; ?>
  <?php if ($job['status'] === 'paid'): ?><div class="stamp" style="color:#1e7a3c;border-color:#1e7a3c">PAID</div><?php endif; ?>

  <div class="logo"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><b style="font-size:22px"><?= e($co['company_name']) ?></b><?php endif; ?></div>

  <div class="top">
    <div>
      <h1><?= e($title) ?></h1>
      <div class="cust">
        <?= e($job['customer_name']) ?><br>
        <?php foreach ($custLines as $l): ?><?= e($l) ?><br><?php endforeach; ?>
        <?php if ($job['customer_vat']): ?>VAT Number: <?= e($job['customer_vat']) ?><?php endif; ?>
      </div>
    </div>
    <div class="meta">
      <b><?= $isQuote ? 'Quote Date' : 'Invoice Date' ?></b><?= e($d($docDate)) ?>
      <b><?= $isQuote ? 'Quote Number' : 'Invoice Number' ?></b><?= e($docNo) ?>
      <?php if ($ref): ?><b>Reference</b><?= e($ref) ?><?php endif; ?>
      <?php if ($co['company_vat']): ?><b>VAT Number</b><?= e($co['company_vat']) ?><?php endif; ?>
    </div>
    <div class="co">
      <?= e($co['company_name']) ?><br>
      <?php foreach ($addrLines as $l): ?><?= e($l) ?><br><?php endforeach; ?>
      <?php if ($co['company_vat']): ?>VAT Number: <?= e($co['company_vat']) ?><?php endif; ?>
    </div>
  </div>

  <table>
    <thead><tr><th style="width:9%">Item</th><th>Description</th><th class="r" style="width:10%">Quantity</th><th class="r" style="width:14%">Unit Price</th><th class="r" style="width:8%">VAT</th><th class="r" style="width:15%">Amount ZAR</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $i => $ln): ?>
      <tr><td></td><td><?= e($ln[0]) ?></td><td class="r">1.00</td><td class="r"><?= $n($ln[1]) ?></td><td class="r"><?= e($vatp) ?>%</td><td class="r"><?= $n($ln[1]) ?></td></tr>
    <?php endforeach; ?>
    <tr class="tot"><td colspan="4"></td><td class="r">Subtotal</td><td class="r"><?= $n($job['subtotal']) ?></td></tr>
    <tr class="tot"><td colspan="4"></td><td class="r">TOTAL&nbsp; VAT</td><td class="r"><?= $n($job['vat_amount']) ?></td></tr>
    <tr class="tot gt"><td colspan="3"></td><td colspan="2" class="r">TOTAL ZAR</td><td class="r"><?= $n($job['total']) ?></td></tr>
    </tbody>
  </table>

  <?php if ($isQuote): ?>
    <div class="due">Quote valid for 30 days</div>
  <?php else: ?>
    <div class="due">Due Date: <?= e($d($job['due_date'] ?: $docDate)) ?></div>
  <?php endif; ?>
  <div class="bank">
    Banking Details:<br><br>
    <?= e($co['company_bank']) ?><br>
    Account No.: <?= e($co['company_account']) ?><br>
    <?php if ($co['bank_branch_code']): ?>Branch Code: <?= e($co['bank_branch_code']) ?><br><?php endif; ?>
    <?php if ($co['invoice_terms']): ?><br><?= nl2br(e($co['invoice_terms'])) ?><?php endif; ?>
  </div>

  <div class="foot">
    <?php if ($co['company_reg']): ?>Company Registration No: <?= e($co['company_reg']) ?>.&nbsp; <?php endif; ?>
    Registered Office: Attention: Finance, <?= e($co['company_address']) ?>.
  </div>
</div>
</body>
</html>
