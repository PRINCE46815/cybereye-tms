<?php
/**
 * TMS — includes/rate_engine.php
 * Server-side mirror of assets/js/rate-engine.js. Used when a trip is
 * saved so the stored financials are authoritative and match the live
 * calculator exactly. PHP 7/8-safe (no typed properties / arrow fns).
 */

class RateEngine
{
    private $s;        // settings map
    private $clients;  // billing client config keyed by code

    public function __construct($settings, $clients = array())
    {
        $this->s       = is_array($settings) ? $settings : array();
        $this->clients = is_array($clients) ? $clients : array();
    }

    /** numeric setting */
    private function v($key)
    {
        return isset($this->s[$key]) ? (float) $this->s[$key] : 0;
    }

    /** client config field (0 when the client is unknown) */
    private function cv($client, $field)
    {
        return isset($this->clients[$client][$field]) ? (float) $this->clients[$client][$field] : 0;
    }

    /** Multiplier for a client: levy / markup clients carry a % on top; flat clients do not. */
    public function mult($client)
    {
        if (!isset($this->clients[$client])) return 1;
        $m = $this->clients[$client]['mechanism'];
        return ($m === 'levy' || $m === 'markup') ? 1 + $this->cv($client, 'adjust_pct') / 100 : 1;
    }

    /**
     * Full-box charged rate for a client. $dest['rates'] is a map client-code => base rate
     * (a client with no entry does not serve the route -> null).
     */
    public function clientRate($dest, $client)
    {
        if (!isset($this->clients[$client])) return null;
        if (!isset($dest['rates'][$client]) || $dest['rates'][$client] === null) return null;
        return $dest['rates'][$client] * $this->mult($client);
    }

    public function gensetPay($client)
    {
        return $this->cv($client, 'genset_base') * $this->mult($client);
    }

    public function standingRate($client) { return $this->cv($client, 'standing_rate'); }
    public function freeHours($client)    { return $this->cv($client, 'free_hours'); }

    public function standingCharge($client, $totalHours)
    {
        $chargeable = max(0, ((float) $totalHours) - $this->freeHours($client));
        return $chargeable * $this->standingRate($client);
    }

    public function opCostFull($km, $genset)
    {
        $diesel  = $this->v('diesel_price');
        $km      = (float) $km;
        $emptyLeg  = ($km / 2) * ($this->v('consumption_empty') / 100) * $diesel;
        $loadedC   = $genset ? $this->v('consumption_genset') : $this->v('consumption_loaded');
        $loadedLeg = ($km / 2) * ($loadedC / 100) * $diesel;
        $running   = $km * $this->v('running_cost');
        $driver    = $this->v('driver_day_cost');
        $wear      = $genset ? $this->v('genset_wear') : 0;
        return $emptyLeg + $loadedLeg + $running + $driver + $wear;
    }

    /** EMPTY op cost: light load both ways. */
    public function opCostEmpty($km, $genset)
    {
        $diesel  = $this->v('diesel_price');
        $km      = (float) $km;
        $fuel    = $km * ($this->v('consumption_empty') / 100) * $diesel;
        $running = $km * $this->v('running_cost');
        $driver  = $this->v('driver_day_cost');
        $wear    = $genset ? $this->v('genset_wear') : 0;
        return $fuel + $running + $driver + $wear;
    }

    public function commission($rateExclVat, $truckType)
    {
        $pct = ($truckType === '6x2') ? $this->v('commission_6x2') : $this->v('commission_6x4');
        return $rateExclVat * $pct / 100;
    }

    public function vat($amount) { return $amount * $this->v('vat_pct') / 100; }

    public function emptyRate($rate12m, $size)
    {
        if ($rate12m === null) return null;
        return $size === '6m' ? $rate12m * $this->v('empty_6m_factor') : $rate12m;
    }

    /**
     * Compute a trip's financials from FINAL values (rate may be user-overridden).
     * $in = rate, genset_amount, standing_amount, surcharge_total, km,
     *       genset (bool), is_empty (bool), truck_type, is_subbie (bool)
     * Returns the full money set ready to store on the trip.
     */
    public function tripFinancials($in)
    {
        $rate      = (float) (isset($in['rate']) ? $in['rate'] : 0);
        $genset    = (float) (isset($in['genset_amount']) ? $in['genset_amount'] : 0);
        $standing  = (float) (isset($in['standing_amount']) ? $in['standing_amount'] : 0);
        $surcharge = (float) (isset($in['surcharge_total']) ? $in['surcharge_total'] : 0);
        $km        = (float) (isset($in['km']) ? $in['km'] : 0);
        $gensetOn  = !empty($in['genset']);
        $isEmpty   = !empty($in['is_empty']);
        $truckType = isset($in['truck_type']) ? $in['truck_type'] : '6x4';
        $isSubbie  = !empty($in['is_subbie']);

        $gross      = $rate + $genset + $standing + $surcharge;     // excl VAT
        $vat        = $this->vat($gross);
        $commission = $this->commission($rate, $truckType);
        $subbiePay  = $isSubbie ? ($rate - $commission) : 0;
        // Operating cost: swap the flat driver-day estimate for the actual driver pay when we have it
        $driverPay  = (float) (isset($in['driver_pay']) ? $in['driver_pay'] : 0);
        $baseCost   = $isEmpty ? $this->opCostEmpty($km, $gensetOn) : $this->opCostFull($km, $gensetOn);
        $driverDay  = $this->v('driver_day_cost');
        $effDriver  = $driverPay > 0 ? $driverPay : $driverDay;
        $cost       = $baseCost - $driverDay + $effDriver;
        $netProfit  = $gross - $cost;
        $margin     = $gross > 0 ? ($netProfit / $gross) * 100 : 0;

        return array(
            'rate'            => round($rate, 2),
            'genset_amount'   => round($genset, 2),
            'standing_amount' => round($standing, 2),
            'surcharge_total' => round($surcharge, 2),
            'gross_revenue'   => round($gross, 2),
            'vat'             => round($vat, 2),
            'commission'      => round($commission, 2),
            'subbie_payout'   => round($subbiePay, 2),
            'est_cost'        => round($cost, 2),
            'net_profit'      => round($netProfit, 2),
            'margin_pct'      => round($margin, 2),
        );
    }
}

/** Billing-client config keyed by code (used by the engine, quote screen and forms). */
function billing_client_config()
{
    $out = array();
    try {
        $rows = db_all("SELECT id, name, code, mechanism, adjust_pct, genset_base, standing_rate, free_hours,
                               dual_flat, to_required, invoice_layout
                        FROM clients WHERE client_type='billing' AND active=1 ORDER BY name");
    } catch (Exception $e) { return $out; }
    foreach ($rows as $r) {
        $out[$r['code']] = array(
            'id' => (int) $r['id'], 'name' => $r['name'], 'mechanism' => $r['mechanism'],
            'adjust_pct' => (float) $r['adjust_pct'], 'genset_base' => (float) $r['genset_base'],
            'standing_rate' => (float) $r['standing_rate'], 'free_hours' => (float) $r['free_hours'],
            'dual_flat' => (float) $r['dual_flat'], 'to_required' => (int) $r['to_required'],
            'invoice_layout' => $r['invoice_layout'], 'surcharges' => array(),
        );
    }
    $ids = array();
    foreach ($out as $code => $c) { $ids[$c['id']] = $code; }
    if ($ids) {
        $sur = db_all("SELECT client_id, id, label, amount, adjust FROM client_surcharges WHERE active=1 ORDER BY id");
        foreach ($sur as $s) {
            if (!isset($ids[(int) $s['client_id']])) continue;
            $out[$ids[(int) $s['client_id']]]['surcharges'][] = array(
                'key' => 's' . (int) $s['id'], 'label' => $s['label'],
                'amount' => (float) $s['amount'], 'adjust' => (int) $s['adjust']);
        }
    }
    return $out;
}

/** Destination rows with their per-client base rates: [ {id,name,km,rates:{code:base}} ]. */
function destinations_with_rates($onlyActive = true)
{
    $codes = array();
    foreach (db_all("SELECT id, code FROM clients WHERE client_type='billing'") as $c) { $codes[(int) $c['id']] = $c['code']; }
    $dests = db_all("SELECT id, name, km, active FROM destinations" . ($onlyActive ? " WHERE active=1" : "") . " ORDER BY name");
    $byId = array();
    foreach ($dests as $d) { $d['id'] = (int) $d['id']; $d['km'] = (int) $d['km']; $d['rates'] = array(); $byId[$d['id']] = $d; }
    foreach (db_all("SELECT client_id, destination_id, base_rate FROM client_rates") as $r) {
        $did = (int) $r['destination_id']; $cid = (int) $r['client_id'];
        if (isset($byId[$did]) && isset($codes[$cid])) { $byId[$did]['rates'][$codes[$cid]] = (float) $r['base_rate']; }
    }
    return array_values($byId);
}

/** Build a RateEngine from the settings + clients tables. */
function rate_engine()
{
    static $engine = null;
    if ($engine === null) {
        $rows = db_all("SELECT setting_key, setting_value FROM settings");
        $map = array();
        foreach ($rows as $r) { $map[$r['setting_key']] = $r['setting_value']; }
        $engine = new RateEngine($map, billing_client_config());
    }
    return $engine;
}
