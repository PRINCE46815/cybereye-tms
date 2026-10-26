<?php
/**
 * TMS — includes/invoice_lib.php
 * Invoice/statement logic + document builders. Documents are built as
 * mPDF-safe, table-based HTML so they (a) print cleanly from any browser
 * today and (b) render through mPDF for one-click PDF once it's installed.
 */

/** Company identity from settings (auto-fills documents). */
function company_identity()
{
    $keys = array('company_name','company_address','company_reg','company_vat','company_email',
                  'company_phone','company_bank','company_account','bank_branch_code','bank_account_type',
                  'company_tagline','company_motto','invoice_terms');
    $out = array();
    foreach ($keys as $k) { $out[$k] = setting($k, ''); }
    return $out;
}

/** Invoice layout per client (Admin → Clients): A = container-led, B = haulier-led. */
function invoice_template_for($clientCode)
{
    static $cache = array();
    if (!array_key_exists($clientCode, $cache)) {
        $v = db_val("SELECT invoice_layout FROM clients WHERE code = ?", array($clientCode));
        $cache[$clientCode] = ($v === 'A') ? 'A' : 'B';
    }
    return $cache[$clientCode];
}

/** Per-client invoice number, e.g. BOL-2026-001. */
function next_invoice_number($clientId, $clientCode)
{
    $year   = date('Y');
    $prefix = strtoupper(substr(preg_replace('/[^a-z]/i', '', $clientCode), 0, 3));
    if ($prefix === '') { $prefix = 'INV'; }
    $count = (int) db_val(
        "SELECT COUNT(*) FROM invoices WHERE client_id = ? AND YEAR(created_at) = ?",
        array($clientId, $year));
    $seq = $count + 1;
    // ensure uniqueness even if rows were deleted
    do {
        $num = $prefix . '-' . $year . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
        $clash = (int) db_val("SELECT COUNT(*) FROM invoices WHERE invoice_number = ?", array($num));
        $seq++;
    } while ($clash > 0);
    return $num;
}

/** Total paid against an invoice. */
function invoice_paid($invoiceId)
{
    return (float) db_val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?", array($invoiceId));
}

/** Outstanding = total − paid. */
function invoice_outstanding($invoice)
{
    return max(0, (float) $invoice['total'] - invoice_paid($invoice['id']));
}

/** Recompute and persist an invoice's status from its payments. */
function invoice_refresh_status($invoiceId)
{
    $inv = db_row("SELECT id, total FROM invoices WHERE id = ?", array($invoiceId));
    if (!$inv) return;
    $paid = invoice_paid($invoiceId);
    if ($paid <= 0)               $status = 'sent';
    elseif ($paid + 0.01 < $inv['total']) $status = 'part';
    else                          $status = 'paid';
    // don't downgrade a draft that hasn't been sent
    $cur = db_val("SELECT status FROM invoices WHERE id = ?", array($invoiceId));
    if ($cur === 'draft' && $paid <= 0) $status = 'draft';
    dbq("UPDATE invoices SET status = ? WHERE id = ?", array($status, $invoiceId));

    // reflect onto the trips
    $tp = ($status === 'paid') ? 'paid' : ($status === 'part' ? 'part' : 'unpaid');
    $ts = ($status === 'paid') ? 'Paid' : 'Invoiced';
    dbq("UPDATE trips SET payment_status = ?, status = IF(status IN ('Invoiced','Paid','Delivered'), ?, status) WHERE invoice_id = ?",
        array($tp, $ts, $invoiceId));
}

/* ===================================================================
 *  DOCUMENT RENDERING (mPDF-safe HTML)
 * =================================================================== */

function doc_styles()
{
    return '<style>
    .doc{font-family:DejaVu Sans, Arial, sans-serif;color:#1a1d22;font-size:11px;line-height:1.45}
    .doc h1{font-size:22px;margin:0;color:#15181D;letter-spacing:.5px}
    .doc .gold{color:#9C7A21}
    .doc table{width:100%;border-collapse:collapse}
    .doc .hdr td{vertical-align:top;padding:0}
    .doc .meta{text-align:right}
    .doc .doc-title{font-size:16px;font-weight:bold;letter-spacing:2px;color:#9C7A21;margin:0 0 4px}
    .doc .muted{color:#6b7280}
    .doc .rule{border-bottom:2px solid #C19A3E;margin:10px 0 14px}
    .doc .billto{margin:6px 0 14px}
    .doc .billto .to{font-size:13px;font-weight:bold;color:#15181D}
    .doc table.lines{margin-top:6px}
    .doc table.lines th{background:#15181D;color:#fff;font-size:9.5px;text-transform:uppercase;letter-spacing:.4px;padding:7px 8px;text-align:left}
    .doc table.lines th.r,.doc table.lines td.r{text-align:right}
    .doc table.lines td{padding:7px 8px;border-bottom:1px solid #e5e7eb;font-size:10.5px}
    .doc table.lines tr.sub td{background:#faf7ef;font-style:italic;color:#555;padding-left:18px}
    .doc .totals{width:46%;margin-left:54%;margin-top:12px}
    .doc .totals td{padding:5px 8px;font-size:11px}
    .doc .totals td.r{text-align:right}
    .doc .totals tr.grand td{border-top:2px solid #15181D;font-weight:bold;font-size:13px;color:#15181D}
    .doc .vat-line td{color:#6b7280}
    .doc .bank{margin-top:18px;border:1px solid #e5e7eb;border-radius:6px;padding:10px 12px;background:#fafafa}
    .doc .bank .h{font-size:9.5px;text-transform:uppercase;letter-spacing:.5px;color:#9C7A21;font-weight:bold;margin-bottom:4px}
    .doc .terms{margin-top:12px;color:#6b7280;font-size:10px}
    .doc .foot{margin-top:16px;text-align:center;color:#9C7A21;font-size:10px;letter-spacing:1px}
    .doc .badge-paid{color:#15803d;border:2px solid #15803d;padding:2px 10px;font-weight:bold;letter-spacing:1px;border-radius:4px;font-size:12px}
    </style>';
}

/** Header block shared by invoice + statement. $title e.g. 'TAX INVOICE'. */
function doc_header($co, $title, $metaRows)
{
    $meta = '';
    foreach ($metaRows as $label => $val) {
        $meta .= '<div><span class="muted">' . e($label) . ':</span> <b>' . e($val) . '</b></div>';
    }
    return '<table class="hdr"><tr>'
        . '<td>'
        . '<h1>' . e($co['company_name']) . '</h1>'
        . '<div class="gold" style="font-size:11px;margin:2px 0 6px">' . e($co['company_tagline']) . '</div>'
        . '<div class="muted">' . e($co['company_address']) . '</div>'
        . '<div class="muted">Reg ' . e($co['company_reg']) . ' &nbsp;·&nbsp; VAT ' . e($co['company_vat']) . '</div>'
        . '<div class="muted">' . e($co['company_email']) . ($co['company_phone'] ? ' &nbsp;·&nbsp; ' . e($co['company_phone']) : '') . '</div>'
        . '</td>'
        . '<td class="meta"><div class="doc-title">' . e($title) . '</div>' . $meta . '</td>'
        . '</tr></table><div class="rule"></div>';
}

function doc_banking($co, $reference)
{
    return '<div class="bank"><table><tr>'
        . '<td style="width:60%"><div class="h">Banking details</div>'
        . '<div><b>' . e($co['company_bank']) . '</b> &nbsp; ' . e($co['bank_account_type']) . '</div>'
        . '<div>Account: <b>' . e($co['company_account']) . '</b>' . ($co['bank_branch_code'] ? ' &nbsp; Branch: ' . e($co['bank_branch_code']) : '') . '</div>'
        . '<div>Account name: <b>' . e($co['company_name']) . '</b></div>'
        . '</td>'
        . '<td style="width:40%;text-align:right;vertical-align:bottom"><div class="h">Use as reference</div><div style="font-size:14px;font-weight:bold;color:#15181D">' . e($reference) . '</div></td>'
        . '</tr></table></div>';
}

/**
 * Full invoice document (inner HTML incl. styles).
 * $trips rows need: collection_date, route, container_number, container_size,
 * load_state, rate, genset_amount, standing_amount, surcharge_total,
 * gross_revenue, to_number, line_name (shipping line), truck_reg.
 */
function render_invoice_doc($invoice, $client, $trips)
{
    $co = company_identity();
    $template = invoice_template_for($client['code']);
    $due = (int) setting('invoice_due_days', 30);
    $dueDate = date('d M Y', strtotime($invoice['created_at'] . ' +' . $due . ' days'));
    $isPaid = $invoice['status'] === 'paid';

    $meta = array(
        'Invoice No' => $invoice['invoice_number'],
        'Date'       => date('d M Y', strtotime($invoice['created_at'])),
        'Due'        => $dueDate,
        'Period'     => $invoice['period_label'] ? $invoice['period_label'] : (fdate($invoice['period_start']) . ' – ' . fdate($invoice['period_end'])),
    );

    $html  = doc_styles() . '<div class="doc">';
    $html .= doc_header($co, 'TAX INVOICE', $meta);
    $html .= '<div class="billto"><div class="muted">Invoice to</div><div class="to">' . e($client['name']) . '</div>'
           . ($isPaid ? '<div style="margin-top:6px"><span class="badge-paid">PAID</span></div>' : '') . '</div>';

    if ($template === 'A') {
        // Container-led layout (A)
        $html .= '<table class="lines"><thead><tr>'
              . '<th>Container No</th><th>Date</th><th>From – To</th><th>ISO Type</th><th class="r">Qty</th>'
              . '<th class="r">Price</th><th class="r">Genset</th><th class="r">Total (excl VAT)</th></tr></thead><tbody>';
        foreach ($trips as $t) {
            $iso = strtoupper($t['container_size'] . ' ' . $t['load_state']);
            $lineTotal = (float) $t['rate'] + (float) $t['genset_amount'] + (float) $t['standing_amount'] + (float) $t['surcharge_total'];
            // primary row: rate + genset shown; standing/surcharge as sub-lines below
            $primary = (float) $t['rate'] + (float) $t['genset_amount'];
            $html .= '<tr>'
                  . '<td><b>' . e($t['container_number'] ? $t['container_number'] : '—') . '</b></td>'
                  . '<td>' . fdate($t['collection_date']) . '</td>'
                  . '<td>' . e($t['route'] ? $t['route'] : '—') . '</td>'
                  . '<td>' . e($iso) . '</td>'
                  . '<td class="r">1</td>'
                  . '<td class="r">' . number_format((float)$t['rate'], 2) . '</td>'
                  . '<td class="r">' . ((float)$t['genset_amount'] > 0 ? number_format((float)$t['genset_amount'], 2) : '—') . '</td>'
                  . '<td class="r">' . number_format($primary, 2) . '</td></tr>';
            if ((float) $t['standing_amount'] > 0) {
                $html .= '<tr class="sub"><td colspan="7">Standing time — ' . e($t['container_number']) . '</td><td class="r">' . number_format((float)$t['standing_amount'], 2) . '</td></tr>';
            }
            if ((float) $t['surcharge_total'] > 0) {
                $html .= '<tr class="sub"><td colspan="7">Depot surcharge — ' . e($t['container_number']) . '</td><td class="r">' . number_format((float)$t['surcharge_total'], 2) . '</td></tr>';
            }
        }
        $html .= '</tbody></table>';
    } else {
        // Haulier-led layout (B)
        $html .= '<table class="lines"><thead><tr>'
              . '<th>Date</th><th>Haulier</th><th>Client</th><th>T.O</th><th>From – To</th>'
              . '<th>Vehicle</th><th>Container</th><th>Size</th><th>Type</th><th class="r">Qty</th><th class="r">Rate</th></tr></thead><tbody>';
        foreach ($trips as $t) {
            $rate = (float) $t['gross_revenue']; // haulier rate folds in any extras
            $html .= '<tr>'
                  . '<td>' . fdate($t['collection_date']) . '</td>'
                  . '<td>' . e(brand_word()) . '</td>'
                  . '<td>' . e($t['line_name'] ? $t['line_name'] : '—') . '</td>'
                  . '<td>' . e($t['to_number'] ? $t['to_number'] : '—') . '</td>'
                  . '<td>' . e($t['route'] ? $t['route'] : '—') . '</td>'
                  . '<td>' . e($t['truck_reg'] ? $t['truck_reg'] : '—') . '</td>'
                  . '<td><b>' . e($t['container_number'] ? $t['container_number'] : '—') . '</b></td>'
                  . '<td>' . e($t['container_size']) . '</td>'
                  . '<td>' . e($t['load_state']) . '</td>'
                  . '<td class="r">1</td>'
                  . '<td class="r">' . number_format($rate, 2) . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }

    // Totals
    $html .= '<table class="totals"><tbody>'
          . '<tr><td>Subtotal (excl VAT)</td><td class="r">R ' . number_format((float)$invoice['subtotal'], 2) . '</td></tr>'
          . '<tr class="vat-line"><td>VAT @ ' . num(setting_num('vat_pct'), 0) . '%</td><td class="r">R ' . number_format((float)$invoice['vat_amount'], 2) . '</td></tr>'
          . '<tr class="grand"><td>TOTAL DUE</td><td class="r">R ' . number_format((float)$invoice['total'], 2) . '</td></tr>'
          . '</tbody></table>';

    $html .= doc_banking($co, $invoice['invoice_number']);
    if ($co['invoice_terms']) { $html .= '<div class="terms">' . e($co['invoice_terms']) . '</div>'; }
    $html .= '<div class="foot">' . e($co['company_motto']) . '</div>';
    $html .= '</div>';
    return $html;
}

/**
 * Statement document. $rows = invoices for the client in period (each with
 * invoice_number, created_at, total, status, and computed 'paid'+'outstanding').
 */
function render_statement_doc($client, $rows, $from, $to, $label)
{
    $co = company_identity();
    $totalBilled = 0; $totalPaid = 0; $totalOut = 0;
    foreach ($rows as $r) { $totalBilled += (float)$r['total']; $totalPaid += (float)$r['paid']; $totalOut += (float)$r['outstanding']; }

    $meta = array(
        'Statement date' => date('d M Y'),
        'Period'         => $label ? $label : (fdate($from) . ' – ' . fdate($to)),
        'Account'        => $client['name'],
    );

    $html  = doc_styles() . '<div class="doc">';
    $html .= doc_header($co, 'STATEMENT', $meta);
    $html .= '<div class="billto"><div class="muted">Statement for</div><div class="to">' . e($client['name']) . '</div></div>';

    $html .= '<table class="lines"><thead><tr>'
          . '<th>Invoice No</th><th>Date</th><th>Status</th><th class="r">Invoiced</th><th class="r">Paid</th><th class="r">Outstanding</th></tr></thead><tbody>';
    if (!$rows) {
        $html .= '<tr><td colspan="6" style="text-align:center;color:#6b7280;padding:18px">No invoices in this period.</td></tr>';
    } else {
        foreach ($rows as $r) {
            $html .= '<tr>'
                  . '<td><b>' . e($r['invoice_number']) . '</b></td>'
                  . '<td>' . fdate($r['created_at']) . '</td>'
                  . '<td>' . e(ucfirst($r['status'])) . '</td>'
                  . '<td class="r">' . number_format((float)$r['total'], 2) . '</td>'
                  . '<td class="r">' . number_format((float)$r['paid'], 2) . '</td>'
                  . '<td class="r"><b>' . number_format((float)$r['outstanding'], 2) . '</b></td></tr>';
        }
    }
    $html .= '</tbody></table>';

    $html .= '<table class="totals"><tbody>'
          . '<tr><td>Total invoiced</td><td class="r">R ' . number_format($totalBilled, 2) . '</td></tr>'
          . '<tr class="vat-line"><td>Total paid</td><td class="r">R ' . number_format($totalPaid, 2) . '</td></tr>'
          . '<tr class="grand"><td>BALANCE DUE</td><td class="r">R ' . number_format($totalOut, 2) . '</td></tr>'
          . '</tbody></table>';

    $html .= doc_banking($co, 'Statement ' . date('Y-m'));
    $html .= '<div class="foot">' . e($co['company_motto']) . '</div>';
    $html .= '</div>';
    return $html;
}

/**
 * Driver pay statement (Curtis/Phineas style) — what the driver is paid.
 * $trips rows need: client_name, container_number, route, container_size,
 * load_state, driver_pay, collection_date.
 */
function render_driver_statement_doc($driver, $trips, $from, $to, $label)
{
    $co = company_identity();
    $total = 0; foreach ($trips as $t) { $total += (float) $t['driver_pay']; }

    $meta = array(
        'Statement date' => date('d M Y'),
        'Period'         => $label ? $label : (fdate($from) . ' - ' . fdate($to)),
        'Driver'         => $driver['name'],
    );

    $html  = doc_styles() . '<div class="doc">';
    $html .= doc_header($co, 'DRIVER PAY STATEMENT', $meta);
    $html .= '<div class="billto"><div class="muted">Pay to</div><div class="to">' . e($driver['name']) . '</div></div>';

    $html .= '<table class="lines"><thead><tr>'
          . '<th>Client</th><th>Container</th><th>Trip</th><th>ISO Type</th><th class="r">Qty</th><th class="r">Amount</th></tr></thead><tbody>';
    if (!$trips) {
        $html .= '<tr><td colspan="6" style="text-align:center;color:#6b7280;padding:18px">No paid trips in this period.</td></tr>';
    } else {
        foreach ($trips as $t) {
            $iso = strtoupper($t['container_size'] . ' ' . $t['load_state']);
            $html .= '<tr>'
                  . '<td>' . e($t['client_name'] ? $t['client_name'] : '—') . '</td>'
                  . '<td><b>' . e($t['container_number'] ? $t['container_number'] : '—') . '</b></td>'
                  . '<td>' . e($t['route'] ? $t['route'] : '—') . '</td>'
                  . '<td>' . e($iso) . '</td>'
                  . '<td class="r">1</td>'
                  . '<td class="r">' . number_format((float)$t['driver_pay'], 2) . '</td></tr>';
        }
    }
    $html .= '</tbody></table>';

    $html .= '<table class="totals"><tbody>'
          . '<tr class="grand"><td>TOTAL PAY</td><td class="r">R ' . number_format($total, 2) . '</td></tr>'
          . '</tbody></table>';

    // Driver banking
    $html .= '<div class="bank"><div class="h">Pay to account</div>'
          . '<div>Account holder: <b>' . e(($driver['account_holder'] ?? '') ? $driver['account_holder'] : $driver['name']) . '</b></div>'
          . '<div><b>' . e(($driver['bank_name'] ?? '') ? $driver['bank_name'] : '—') . '</b> &nbsp; Account: <b>' . e(($driver['account_no'] ?? '') ? $driver['account_no'] : '—') . '</b>'
          . (($driver['branch_code'] ?? '') ? ' &nbsp; Branch: ' . e($driver['branch_code']) : '') . '</div></div>';
    $html .= '<div class="foot">' . e($co['company_motto']) . '</div></div>';
    return $html;
}

/* ===================================================================
 *  PDF (mPDF) — graceful when not installed
 * =================================================================== */

function mpdf_available()
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) return false;
    require_once $autoload;
    return class_exists('\\Mpdf\\Mpdf');
}

/** Stream a PDF from inner HTML. Returns false if mPDF isn't installed. */
function stream_pdf($innerHtml, $filename)
{
    if (!mpdf_available()) return false;
    $tmp = sys_get_temp_dir() . '/mpdf';
    if (!is_dir($tmp)) { @mkdir($tmp, 0775, true); }
    $mpdf = new \Mpdf\Mpdf(array(
        'format' => 'A4', 'margin_left' => 12, 'margin_right' => 12,
        'margin_top' => 12, 'margin_bottom' => 14, 'tempDir' => $tmp,
    ));
    $mpdf->SetTitle($filename);
    $mpdf->WriteHTML($innerHtml);
    $mpdf->Output($filename, 'D'); // D = force download
    return true;
}
