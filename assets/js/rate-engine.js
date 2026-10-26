/* ============================================================
   TMS — rate-engine.js
   The single source of truth for all quote / cost / profit /
   commission math. Settings are injected once via RateEngine.init().
   Mirror of the server-side math (kept in step with the test suite).
   ============================================================ */

var RateEngine = {
  settings: {},
  clients: {},      // billing client config keyed by code

  init: function (settings, clients) { this.settings = settings || {}; this.clients = clients || {}; },

  // numeric setting
  s: function (key) { var v = parseFloat(this.settings[key]); return isNaN(v) ? 0 : v; },

  // numeric client field
  cv: function (client, field) {
    var c = this.clients[client]; if (!c) return 0;
    var v = parseFloat(c[field]); return isNaN(v) ? 0 : v;
  },

  // Levy / markup clients carry a % on top; flat clients do not.
  mult: function (client) {
    var c = this.clients[client]; if (!c) return 1;
    return (c.mechanism === 'levy' || c.mechanism === 'markup') ? 1 + this.cv(client, 'adjust_pct') / 100 : 1;
  },

  /* ---- Full-box charged rate for a client, given a destination row ---- */
  // dest = {rates:{code:base}, km}; returns null if the client does not serve the route.
  clientRate: function (dest, client) {
    if (!this.clients[client] || !dest.rates || dest.rates[client] == null) return null;
    return dest.rates[client] * this.mult(client);
  },

  /* ---- Genset pay by client ---- */
  gensetPay: function (client) { return this.cv(client, 'genset_base') * this.mult(client); },

  /* ---- Standing / waiting ---- */
  standingRate: function (client) { return this.cv(client, 'standing_rate'); },
  freeHours:    function (client) { return this.cv(client, 'free_hours'); },
  standingCharge: function (client, totalHours) {
    var chargeable = Math.max(0, (totalHours || 0) - this.freeHours(client));
    return chargeable * this.standingRate(client);
  },

  /* ---- Operating cost (Volvo FH440) ---- */
  // FULL: outbound empty leg + return loaded leg (+genset burn) + running + driver day + genset wear
  opCostFull: function (km, genset) {
    var diesel = this.s('diesel_price');
    km = km || 0;
    var emptyLeg  = (km / 2) * (this.s('consumption_empty') / 100) * diesel;
    var loadedC   = genset ? this.s('consumption_genset') : this.s('consumption_loaded');
    var loadedLeg = (km / 2) * (loadedC / 100) * diesel;
    var running   = km * this.s('running_cost');
    var driver    = this.s('driver_day_cost');
    var wear      = genset ? this.s('genset_wear') : 0;
    return emptyLeg + loadedLeg + running + driver + wear;
  },
  // EMPTY: light load both ways → empty consumption throughout
  opCostEmpty: function (km, genset) {
    var diesel = this.s('diesel_price');
    km = km || 0;
    var fuel    = km * (this.s('consumption_empty') / 100) * diesel;
    var running = km * this.s('running_cost');
    var driver  = this.s('driver_day_cost');
    var wear    = genset ? this.s('genset_wear') : 0;
    return fuel + running + driver + wear;
  },

  /* ---- Commission (on the haulage rate, excl VAT) ---- */
  commission: function (rateExclVat, truckType) {
    var pct = (truckType === '6x2') ? this.s('commission_6x2') : this.s('commission_6x4');
    return rateExclVat * pct / 100;
  },

  vat: function (amount) { return amount * this.s('vat_pct') / 100; },

  // Operating cost with actual driver pay swapped in for the flat estimate (when > 0)
  tripCost: function (km, genset, isEmpty, driverPay) {
    var base = isEmpty ? this.opCostEmpty(km, genset) : this.opCostFull(km, genset);
    var dd = this.s('driver_day_cost');
    var eff = (driverPay > 0) ? driverPay : dd;
    return base - dd + eff;
  },

  /* ---- Empty rate with 6m derivation ---- */
  emptyRate: function (rate12m, size) {
    if (rate12m == null) return null;
    return size === '6m' ? rate12m * this.s('empty_6m_factor') : rate12m;
  },

  /* ============================================================
     Assemble a full quote for ONE scenario.
     opts = {
       mode:'full'|'empty', client, truckType:'6x4'|'6x2',
       rate (charged haulage rate), genset (bool), standingHours,
       surcharges (number total), km
     }
     ============================================================ */
  quote: function (opts) {
    var rate      = opts.rate || 0;
    var gensetAmt = opts.genset ? this.gensetPay(opts.client) : 0;
    var standing  = opts.client ? this.standingCharge(opts.client, opts.standingHours) : 0;
    var surcharge = opts.surcharges || 0;

    var revenueExcl = rate + gensetAmt + standing + surcharge;
    var vat         = this.vat(revenueExcl);
    var revenueIncl = revenueExcl + vat;

    var cost = (opts.mode === 'empty')
      ? this.opCostEmpty(opts.km, opts.genset)
      : this.opCostFull(opts.km, opts.genset);

    var netProfit = revenueExcl - cost;
    var margin    = revenueExcl > 0 ? (netProfit / revenueExcl) * 100 : 0;
    var commission = this.commission(rate, opts.truckType);

    return {
      rate: rate, genset: gensetAmt, standing: standing, surcharge: surcharge,
      revenueExcl: revenueExcl, vat: vat, revenueIncl: revenueIncl,
      cost: cost, netProfit: netProfit, margin: margin, commission: commission
    };
  }
};

/* Format a number as Rands for display */
function R(n) {
  if (n == null || isNaN(n)) return 'R 0.00';
  return 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
