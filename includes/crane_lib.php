<?php
/**
 * TMS — includes/crane_lib.php
 * Crane truck pricing. Mirrors the other trucks: diesel + running cost per km on the
 * ROUND-TRIP distance, then the 3-man crew hire, then VAT.
 *
 *   diesel cost    = km × (L/100km ÷ 100) × diesel price
 *   running cost   = km × running cost per km
 *   operating cost = diesel + running
 *   call-out       = operating cost × (1 + mark-up %)        (or a manual amount)
 *   crew hire      = max(hours, minimum hours) × 3-man crew rate
 *   subtotal       = call-out + crew hire + optional extra line
 *   VAT / total    = subtotal × VAT %
 */

function crane_config()
{
    return array(
        'diesel_price'  => (float) setting_num('diesel_price'),
        'consumption'   => (float) setting_num('crane_consumption'),
        'running_cost'  => (float) setting_num('crane_running_cost'),
        'crew_rate'     => (float) setting_num('crane_crew_rate'),
        'min_hours'     => (float) setting_num('crane_min_hours'),
        'markup'        => (float) setting_num('crane_callout_markup'),
        'vat_pct'       => (float) setting_num('vat_pct'),
        'due_days'      => (int)   setting_num('crane_due_days'),
    );
}

/** $override = null (use cost-based call-out) or a number (manual call-out amount). */
function crane_calc($km, $hours, $override, $extra, $cfg = null)
{
    $c = $cfg ? $cfg : crane_config();
    $km     = max(0.0, (float) $km);
    $hours  = max(0.0, (float) $hours);
    $extra  = (float) $extra;

    $diesel  = round($km * ($c['consumption'] / 100) * $c['diesel_price'], 2);
    $running = round($km * $c['running_cost'], 2);
    $op      = round($diesel + $running, 2);

    $isOverride = ($override !== null && $override !== '');
    $callout = $isOverride ? round((float) $override, 2) : round($op * (1 + $c['markup'] / 100), 2);

    $billHours = max($hours, $c['min_hours']);
    $crew      = round($billHours * $c['crew_rate'], 2);

    $subtotal = round($callout + $crew + $extra, 2);
    $vat      = round($subtotal * $c['vat_pct'] / 100, 2);

    return array(
        'km' => $km, 'hours' => $hours, 'bill_hours' => $billHours,
        'diesel_cost' => $diesel, 'running_total' => $running, 'op_cost' => $op,
        'callout_override' => $isOverride ? 1 : 0, 'callout_charge' => $callout,
        'crew_charge' => $crew, 'extra_amount' => round($extra, 2),
        'subtotal' => $subtotal, 'vat_amount' => $vat, 'total' => round($subtotal + $vat, 2),
        'callout_margin' => round($callout - $op, 2),
    );
}

/** Take the next document number (QU-0012 / INV-0158) and advance the counter, atomically. */
function crane_next_number($kind)
{
    $isInv  = ($kind === 'invoice');
    $keyN   = $isInv ? 'crane_invoice_next'   : 'crane_quote_next';
    $prefix = (string) setting($isInv ? 'crane_invoice_prefix' : 'crane_quote_prefix', $isInv ? 'INV-' : 'QU-');
    $col    = $isInv ? 'invoice_no' : 'quote_no';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? FOR UPDATE");
        $st->execute(array($keyN));
        $n = (int) $st->fetchColumn();
        if ($n < 1) { $n = 1; }
        $no = '';
        for ($i = 0; $i < 5000; $i++) {                       // skip any number already used
            $no = $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $chk = $pdo->prepare("SELECT COUNT(*) FROM crane_jobs WHERE $col = ?");
            $chk->execute(array($no));
            if ((int) $chk->fetchColumn() === 0) break;
            $n++;
        }
        $up = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        $up->execute(array((string) ($n + 1), $keyN));
        $pdo->commit();
        return $no;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function crane_valid_date($s)
{
    $d = DateTime::createFromFormat('Y-m-d', (string) $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}
