<?php
/**
 * TMS — invoice_xlsx.php
 * Exports an invoice as an Excel file (.xls) laid out like a haulier
 * reference sheet. Opens in Excel/Sheets. PDF export is unchanged.
 *
 * Money values follow the reference: Rate, then Fuel Surcharge = Rate x 15%,
 * Sub Total, VAT (15%), Total, and Grand Total = Total(rate) + Total(fuel).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoice_lib.php';
require_role('master', 'admin', 'finance', 'viewer');

$id  = (int) get('id', 0);
$inv = db_row("SELECT i.*, c.name AS client_name, c.code AS client_code FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?", array($id));
if (!$inv) { http_response_code(404); exit('Invoice not found.'); }

$trips = db_all(
    "SELECT t.*, sl.name AS line_name, tr.registration AS truck_reg
     FROM trips t LEFT JOIN clients sl ON sl.id=t.shipping_line_id LEFT JOIN trucks tr ON tr.id=t.truck_id
     WHERE t.invoice_id=? ORDER BY t.collection_date ASC, t.id ASC", array($id));

$co = company_identity();

// ---- Money: use the invoice's stored, authoritative totals (same as the PDF) ----
// Rates already include each client's fuel surcharge; VAT is applied last.
$subtotal = (float) $inv['subtotal'];
$vat      = (float) $inv['vat_amount'];
$total    = (float) $inv['total'];
$vatPct   = (float) setting('vat_pct', 15);

$fname = 'Invoice-' . preg_replace('/[^A-Za-z0-9_-]/', '', $inv['invoice_number']) . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Helpers for cells
function R($n) { return 'R ' . number_format((float) $n, 2); }
function x($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }

$GREY = '#EAEDF1';
$invDate = date('d-M-y', strtotime($inv['created_at']));
$period  = $inv['period_label'] ? $inv['period_label'] : (fdate($inv['period_start']) . ' - ' . fdate($inv['period_end']));

echo "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:x='urn:schemas-microsoft-com:office:excel'>";
echo "<head><meta charset='utf-8'>
<style>
  td,th{font-family:Arial;font-size:10pt;border:0.5pt solid #c9ccd1;padding:3px 6px;vertical-align:top}
  .nb{border:none}
  .grey{background:$GREY}
  .b{font-weight:bold}
  .c{text-align:center}
  .r{text-align:right}
  .title{font-size:13pt;font-weight:bold}
  .hdr{background:#15181D;color:#ffffff;font-weight:bold;text-align:center}
</style></head><body>";

echo "<table cellspacing='0'>";

// Column sizing hints
echo "<colgroup>"
   . "<col width='80'><col width='90'><col width='70'><col width='95'><col width='160'>"
   . "<col width='135'><col width='115'><col width='55'><col width='60'><col width='45'>"
   . "<col width='105'></colgroup>";

// ---- Supplier details ----
echo "<tr><td class='grey b' colspan='11'>Supplier Details</td></tr>";
echo "<tr><td class='grey' colspan='11'>Company name: " . x($co['company_name'] ?: company_name()) . "</td></tr>";
echo "<tr><td class='grey' colspan='11'>Company address: " . x($co['company_address']) . "</td></tr>";
echo "<tr><td class='grey' colspan='11'>Registration / VAT Number: " . x($co['company_reg']) . "   |   " . x($co['company_vat']) . "</td></tr>";
echo "<tr><td class='grey' colspan='11'>Contact: " . x($co['company_phone']) . "  |  " . x($co['company_email']) . "</td></tr>";
echo "<tr><td class='nb' colspan='11'>&nbsp;</td></tr>";

// ---- Title ----
echo "<tr><td class='nb title' colspan='11'>TAX INVOICE</td></tr>";
echo "<tr><td class='nb' colspan='11'>&nbsp;</td></tr>";

// ---- Bill-to (left) + invoice meta (right) ----
echo "<tr>"
   . "<td class='b' colspan='6'>Invoice To: " . x($inv['client_name']) . "</td>"
   . "<td class='b' colspan='2'>Invoice Date</td><td class='c' colspan='3'>" . x($invDate) . "</td></tr>";
echo "<tr>"
   . "<td colspan='6'>&nbsp;</td>"
   . "<td class='b' colspan='2'>Invoice Number</td><td class='c' colspan='3'>" . x($inv['invoice_number']) . "</td></tr>";
echo "<tr>"
   . "<td colspan='6'>&nbsp;</td>"
   . "<td class='b' colspan='2'>VAT Number</td><td class='c' colspan='3'>" . x($co['company_vat']) . "</td></tr>";
echo "<tr>"
   . "<td colspan='6'>&nbsp;</td>"
   . "<td class='b' colspan='2'>Period</td><td class='c' colspan='3'>" . x($period) . "</td></tr>";
echo "<tr><td class='nb' colspan='11'>&nbsp;</td></tr>";

// ---- Line items header ----
echo "<tr>"
   . "<td class='hdr'>Date</td><td class='hdr'>Haulier</td><td class='hdr'>Client</td><td class='hdr'>T.O Number</td>"
   . "<td class='hdr'>From - To</td><td class='hdr'>Vehicle Reg.</td><td class='hdr'>Container No.</td><td class='hdr'>Size</td>"
   . "<td class='hdr'>Type</td><td class='hdr'>Qty</td><td class='hdr'>Rate</td></tr>";

// ---- Line items ----
foreach ($trips as $t) {
    $rate = (float) $t['gross_revenue'];
    $reg  = ($t['truck_reg'] ? $t['truck_reg'] : '—') . ((int) $t['has_genset'] === 1 ? ' (GENSET)' : '');
    echo "<tr>"
       . "<td class='c'>" . x(fdate($t['collection_date'])) . "</td>"
       . "<td>" . x(brand_word()) . "</td>"
       . "<td class='c b'>" . x($t['line_name'] ? $t['line_name'] : '—') . "</td>"
       . "<td class='c'>" . x($t['to_number'] ? $t['to_number'] : '—') . "</td>"
       . "<td>" . x($t['route'] ? $t['route'] : '—') . "</td>"
       . "<td class='c'>" . x($reg) . "</td>"
       . "<td class='c'>" . x($t['container_number'] ? $t['container_number'] : '—') . "</td>"
       . "<td class='c'>" . x(strtoupper($t['container_size'])) . "</td>"
       . "<td class='c'>" . x($t['load_state']) . "</td>"
       . "<td class='c'>1</td>"
       . "<td class='r'>" . R($rate) . "</td></tr>";
}

// ---- Totals (rate already includes fuel surcharge; VAT applied last) ----
echo "<tr><td class='nb' colspan='9'></td><td class='b r'>Subtotal</td><td class='b r'>" . R($subtotal) . "</td></tr>";
echo "<tr><td class='nb' colspan='9'></td><td class='b r'>VAT (" . rtrim(rtrim(number_format($vatPct,2),'0'),'.') . "%)</td><td class='r'>" . R($vat) . "</td></tr>";
echo "<tr><td class='nb' colspan='9'></td><td class='b r'>TOTAL DUE</td><td class='b r'>" . R($total) . "</td></tr>";
echo "<tr><td class='nb' colspan='11'>&nbsp;</td></tr>";

// ---- Banking ----
echo "<tr><td class='grey b' colspan='11'>Banking Details</td></tr>";
echo "<tr><td class='grey' colspan='11'>Account name: " . x($co['company_name'] ?: company_name()) . "</td></tr>";
echo "<tr><td class='grey' colspan='11'>Bank: " . x($co['company_bank']) . ($co['bank_account_type'] ? '  (' . x($co['bank_account_type']) . ')' : '') . "</td></tr>";
echo "<tr><td class='grey' colspan='11'>Account number: " . x($co['company_account']) . ($co['bank_branch_code'] ? '   Branch: ' . x($co['bank_branch_code']) : '') . "</td></tr>";
if ($co['invoice_terms']) {
    echo "<tr><td class='nb' colspan='11'>&nbsp;</td></tr>";
    echo "<tr><td class='nb' colspan='11'>" . x($co['invoice_terms']) . "</td></tr>";
}
echo "<tr><td class='nb' colspan='11'>&nbsp;</td></tr>";
echo "<tr><td class='nb c' colspan='11'>" . x($co['company_motto']) . "</td></tr>";

echo "</table></body></html>";
