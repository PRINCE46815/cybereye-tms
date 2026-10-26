<?php
/**
 * TMS — pdf.php
 * Streams an invoice or statement PDF via mPDF. If mPDF isn't installed,
 * redirects to the printable document with a friendly note.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoice_lib.php';
require_login();

$doc = get('doc');
$isStaff = has_role('master', 'admin', 'finance', 'viewer');

if ($doc === 'invoice') {
    if (!$isStaff) { http_response_code(403); exit('Access denied.'); }
    $id = (int) get('id', 0);
    $inv = db_row("SELECT i.*, c.name AS client_name, c.code AS client_code FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?", array($id));
    if (!$inv) { http_response_code(404); exit('Invoice not found.'); }
    $client = array('id' => $inv['client_id'], 'name' => $inv['client_name'], 'code' => $inv['client_code']);
    $trips = db_all(
        "SELECT t.*, sl.name AS line_name, tr.registration AS truck_reg
         FROM trips t LEFT JOIN clients sl ON sl.id=t.shipping_line_id LEFT JOIN trucks tr ON tr.id=t.truck_id
         WHERE t.invoice_id=? ORDER BY t.collection_date ASC, t.id ASC", array($id));
    $inner = render_invoice_doc($inv, $client, $trips);
    $filename = 'Invoice-' . $inv['invoice_number'] . '.pdf';
    if (!stream_pdf($inner, $filename)) {
        flash('info', 'Install mPDF for one-click PDF. For now, use Print → Save as PDF.');
        redirect('invoice_doc.php?id=' . $id);
    }
    exit;
}

if ($doc === 'statement') {
    if (!$isStaff) { http_response_code(403); exit('Access denied.'); }
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
    $filename = 'Statement-' . preg_replace('/[^A-Za-z0-9]/', '', $client['name']) . '-' . date('Ymd') . '.pdf';
    if (!stream_pdf($inner, $filename)) {
        flash('info', 'Install mPDF for one-click PDF. For now, use Print → Save as PDF.');
        redirect('statement_doc.php?client_id=' . $clientId . '&from=' . urlencode($from) . '&to=' . urlencode($to));
    }
    exit;
}

if ($doc === 'driver_statement') {
    $driverId = (int) get('driver_id', 0);
    $isOwn = current_role() === 'driver' && (int) $me['driver_id'] === $driverId && $driverId > 0;
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
    $filename = 'DriverPay-' . preg_replace('/[^A-Za-z0-9]/', '', $driver['name']) . '-' . date('Ymd') . '.pdf';
    if (!stream_pdf($inner, $filename)) {
        flash('info', 'Install mPDF for one-click PDF. For now, use Print → Save as PDF.');
        redirect('driver_statement_doc.php?driver_id=' . $driverId . '&from=' . urlencode($from) . '&to=' . urlencode($to));
    }
    exit;
}

http_response_code(400);
exit('Unknown document type.');