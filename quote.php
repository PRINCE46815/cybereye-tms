<?php
/**
 * TMS — quote.php
 * Live quoting calculator. Pick full/empty, the destination or carrier,
 * and instantly see rate, genset, standing, surcharges, revenue, cost,
 * profit, margin, commission — plus the best-client ranking with the
 * 2nd-best option as leverage. All math is instant (client-side).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rate_engine.php';
require_role('master', 'admin');

$pageTitle    = 'Quote & Price';
$pageSubtitle = 'Live rate calculator';

/* ---- Data for the engine ---- */
$dests   = destinations_with_rates(true);
$clients = billing_client_config();

try {
    $empties = db_all("SELECT id, carrier, from_location, to_location, rate_12m
                       FROM empty_rates WHERE active = 1 ORDER BY carrier, rate_12m DESC");
} catch (Exception $e) { $empties = array(); }

$settingsRows = db_all("SELECT setting_key, setting_value FROM settings");
$settings = array();
foreach ($settingsRows as $r) { $settings[$r['setting_key']] = $r['setting_value']; }

// Shape destinations for JS
$destsJs = array();
foreach ($dests as $d) {
    $destsJs[] = array(
        'id'    => (int) $d['id'],
        'name'  => $d['name'],
        'rates' => (object) $d['rates'],
        'km'    => (int) $d['km'],
    );
}
$emptiesJs = array();
foreach ($empties as $em) {
    $emptiesJs[] = array(
        'id'      => (int) $em['id'],
        'carrier' => $em['carrier'],
        'from'    => $em['from_location'],
        'to'      => $em['to_location'],
        'rate12m' => (float) $em['rate_12m'],
    );
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Quote &amp; Price</h1>
    <p>Instant quotes off your live rates. Change anything and the numbers update as you type.</p>
  </div>
</div>

<!-- Mode toggle -->
<div class="tabs" style="margin-bottom:20px">
  <button type="button" id="mode-full" class="active" onclick="setMode('full')">📦 Full Box</button>
  <button type="button" id="mode-empty" onclick="setMode('empty')">⬚ Empty Container</button>
</div>

<div class="quote-grid">

  <!-- ============ INPUTS ============ -->
  <div>
    <!-- FULL inputs -->
    <div class="card" id="full-inputs">
      <h3>Job details</h3>
      <div class="form-row">
        <label class="lbl">Destination</label>
        <select id="f_dest"><option value="">— choose a destination —</option>
          <?php foreach ($destsJs as $i => $d): ?>
            <option value="<?= $i ?>"><?= e($d['name']) ?> (<?= $d['km'] ?> km)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-grid">
        <div class="form-row">
          <label class="lbl">Container size</label>
          <select id="f_size"><option value="12m">12m (40ft)</option><option value="6m">6m (20ft)</option></select>
          <div class="hint">Full-box rate is the same for both sizes</div>
        </div>
        <div class="form-row">
          <label class="lbl">Truck type</label>
          <select id="f_truck"><option value="6x4">6×4 fullbox (<?= e(rtrim(rtrim(number_format(setting_num('commission_6x4'),2,'.',''),'0'),'.')) ?>%)</option><option value="6x2">6×2 empty-only (<?= e(rtrim(rtrim(number_format(setting_num('commission_6x2'),2,'.',''),'0'),'.')) ?>%)</option></select>
        </div>
        <div class="form-row">
          <label class="lbl">Genset?</label>
          <select id="f_genset"><option value="0">No</option><option value="1">Yes — reefer</option></select>
        </div>
        <div class="form-row">
          <label class="lbl">Standing hours (total waited)</label>
          <input type="number" id="f_standing" min="0" step="0.5" value="0">
          <div class="hint" id="f_freehint">free hours deducted automatically</div>
        </div>
      </div>
      <div id="f_surcharges" class="surcharge-box"></div>
    </div>

    <!-- EMPTY inputs -->
    <div class="card" id="empty-inputs" style="display:none">
      <h3>Empty move details</h3>
      <div class="form-grid">
        <div class="form-row">
          <label class="lbl">Carrier / line</label>
          <select id="e_carrier"><option value="">— choose carrier —</option></select>
        </div>
        <div class="form-row">
          <label class="lbl">Route (from → to)</label>
          <select id="e_route"><option value="">— choose carrier first —</option></select>
        </div>
        <div class="form-row">
          <label class="lbl">Container size</label>
          <select id="e_size"><option value="12m">12m (40ft)</option><option value="6m">6m (20ft) — half</option></select>
        </div>
        <div class="form-row">
          <label class="lbl">Truck type</label>
          <select id="e_truck"><option value="6x2">6×2 empty-only (<?= e(rtrim(rtrim(number_format(setting_num('commission_6x2'),2,'.',''),'0'),'.')) ?>%)</option><option value="6x4">6×4 fullbox (<?= e(rtrim(rtrim(number_format(setting_num('commission_6x4'),2,'.',''),'0'),'.')) ?>%)</option></select>
        </div>
        <div class="form-row">
          <label class="lbl">Distance (km, for cost)</label>
          <input type="number" id="e_km" min="0" step="1" value="0" placeholder="round trip km">
        </div>
        <div class="form-row">
          <label class="lbl">Standing hours × rate</label>
          <div style="display:flex;gap:8px">
            <input type="number" id="e_standing" min="0" step="0.5" value="0" style="flex:1" title="hours">
            <div class="field-money" style="flex:1"><input type="number" id="e_standrate" min="0" step="1" value="350" title="R/hr"></div>
          </div>
        </div>
        <div class="form-row">
          <label class="lbl">Genset add (R)</label>
          <div class="field-money"><input type="number" id="e_genset" min="0" step="1" value="0"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ============ RESULTS ============ -->
  <div>
    <div class="result-card" id="results">
      <div class="empty"><div class="big">⊞</div>Choose a destination to see the quote.</div>
    </div>
  </div>

</div>

<script src="<?= BASE_URL ?>/assets/js/rate-engine.js"></script>
<script>
var CLIENTS = <?= json_encode((object) $clients) ?>;
RateEngine.init(<?= json_encode($settings) ?>, CLIENTS);
var DESTS   = <?= json_encode($destsJs) ?>;
var EMPTIES = <?= json_encode($emptiesJs) ?>;

var mode = 'full';
var selClient = null;            // chosen client for the detailed breakdown (null = best)
var selSurcharges = {};          // surcharge key -> bool

var CLIENT_LABELS = {};
Object.keys(CLIENTS).forEach(function(c){ CLIENT_LABELS[c] = CLIENTS[c].name; });

/* ---- Surcharge definitions (set per client in Admin → Clients) ---- */
function surchargeDefs(client){
  var c = CLIENTS[client]; if (!c) return [];
  return (c.surcharges || []).map(function(s){
    return { key: s.key, label: s.label,
             amount: s.amount * (parseInt(s.adjust,10) ? RateEngine.mult(client) : 1) };
  });
}

function setMode(m){
  mode = m;
  document.getElementById('mode-full').classList.toggle('active', m==='full');
  document.getElementById('mode-empty').classList.toggle('active', m==='empty');
  document.getElementById('full-inputs').style.display  = m==='full' ? '' : 'none';
  document.getElementById('empty-inputs').style.display = m==='empty' ? '' : 'none';
  recompute();
}

/* ===================== FULL ===================== */
function computeFull(){
  var di = document.getElementById('f_dest').value;
  if (di===''){ showEmpty('Choose a destination to see the quote.'); return; }
  var dest = DESTS[parseInt(di,10)];
  var d = { rates:dest.rates, km:dest.km };

  var genset   = document.getElementById('f_genset').value==='1';
  var standing = parseFloat(document.getElementById('f_standing').value)||0;
  var truck    = document.getElementById('f_truck').value;

  // Rank serving clients by profit (rate + genset + standing, no surcharges — like-for-like)
  var ranking = [];
  Object.keys(CLIENTS).forEach(function(c){
    var rate = RateEngine.clientRate(d, c);
    if (rate==null) return;
    var q = RateEngine.quote({ mode:'full', client:c, truckType:truck, rate:rate,
                               genset:genset, standingHours:standing, surcharges:0, km:d.km });
    ranking.push({ client:c, rate:rate, profit:q.netProfit, margin:q.margin, revenueExcl:q.revenueExcl });
  });
  ranking.sort(function(a,b){ return b.profit-a.profit; });

  if (ranking.length===0){ showEmpty('No client serves this destination yet. Add a rate in Admin → Destinations.'); return; }

  var best = ranking[0];
  // chosen client must be one that serves; else best
  var chosen = best.client;
  if (selClient){ for (var i=0;i<ranking.length;i++){ if(ranking[i].client===selClient){ chosen=selClient; break; } } }

  // detailed quote for chosen client (with surcharges)
  var defs = surchargeDefs(chosen);
  var surTotal = 0, surList = [];
  defs.forEach(function(def){
    var on = !!selSurcharges[chosen+'_'+def.key];
    if (on){ surTotal += def.amount; }
    surList.push({ key:chosen+'_'+def.key, label:def.label, amount:def.amount, on:on });
  });

  var rate = RateEngine.clientRate(d, chosen);
  var detail = RateEngine.quote({ mode:'full', client:chosen, truckType:truck, rate:rate,
                                  genset:genset, standingHours:standing, surcharges:surTotal, km:d.km });

  // free-hours hint
  document.getElementById('f_freehint').textContent =
    CLIENT_LABELS[chosen] + ' allows ' + RateEngine.freeHours(chosen) + ' free hours';

  renderFull(dest, ranking, best, chosen, detail, surList, standing);
}

function renderFull(dest, ranking, best, chosen, q, surList, standingHrs){
  var html = '';

  // Headline profit
  var marginClass = q.margin>=25 ? 'good' : (q.margin>=15 ? 'warn' : 'bad');
  html += '<div class="result-head">';
  html += '<div class="result-dest">'+esc(dest.name)+' · '+dest.km+' km</div>';
  html += '<div class="result-profit">'+R(q.netProfit)+'<span class="pl"> profit</span></div>';
  html += '<div class="result-sub"><span class="badge '+marginClass+'">'+q.margin.toFixed(1)+'% margin</span> '
        + 'as <b>'+CLIENT_LABELS[chosen]+'</b></div>';
  html += '</div>';

  // Best-client ranking
  html += '<div class="rank-block"><div class="rank-title">Best client for this run</div>';
  ranking.forEach(function(r){
    var isBest = (r.client===best.client);
    var isSel  = (r.client===chosen);
    html += '<div class="rank-row'+(isSel?' sel':'')+'" onclick="pickClient(\''+r.client+'\')">'
          + '<div class="rank-name">'+CLIENT_LABELS[r.client]+(isBest?' <span class="badge gold">BEST</span>':'')+'</div>'
          + '<div class="rank-nums"><span class="rank-rate">'+R(r.rate)+'</span>'
          + '<span class="rank-profit">'+R(r.profit)+'</span>'
          + '<span class="badge '+(r.margin>=25?'good':(r.margin>=15?'warn':'bad'))+'">'+r.margin.toFixed(0)+'%</span></div>'
          + '</div>';
  });
  // Leverage banner
  if (ranking.length>1){
    var gap = ranking[0].profit - ranking[1].profit;
    html += '<div class="leverage">💡 '+CLIENT_LABELS[ranking[0].client]+' nets <b>'+R(gap)
          + '</b> more than '+CLIENT_LABELS[ranking[1].client]+' on this run.</div>';
  }
  html += '</div>';

  // Breakdown
  html += '<div class="brk"><div class="brk-title">Breakdown — '+CLIENT_LABELS[chosen]+'</div><table class="data brk-table"><tbody>';
  html += brkRow('Haulage rate', q.rate);
  if (q.genset>0)   html += brkRow('Genset', q.genset);
  if (q.standing>0) html += brkRow('Standing ('+standingHrs+'h waited)', q.standing);
  if (q.surcharge>0)html += brkRow('Surcharges', q.surcharge);
  html += brkRow('<b>Revenue (excl VAT)</b>', q.revenueExcl, 'sub');
  html += brkRow('VAT', q.vat, 'muted');
  html += brkRow('Revenue (incl VAT)', q.revenueIncl, 'muted');
  html += brkRow('Operating cost', -q.cost, 'cost');
  html += brkRow('<b>Net profit</b>', q.netProfit, 'profit');
  html += brkRow('Commission ('+document.getElementById('f_truck').selectedOptions[0].text.split(' ')[0]+')', q.commission, 'muted');
  html += '</tbody></table></div>';

  // Surcharge toggles
  if (surList.length){
    html += '<div class="brk"><div class="brk-title">Add surcharges</div><div class="sur-toggles">';
    surList.forEach(function(s){
      html += '<label class="sur-toggle"><input type="checkbox" '+(s.on?'checked':'')
            + ' onchange="toggleSur(\''+s.key+'\',this.checked)"> '+s.label
            + ' <span class="muted">'+R(s.amount)+'</span></label>';
    });
    html += '</div></div>';
  }

  document.getElementById('results').innerHTML = html;
}

/* ===================== EMPTY ===================== */
function computeEmpty(){
  var carrier = document.getElementById('e_carrier').value;
  var ri = document.getElementById('e_route').value;
  if (carrier==='' || ri===''){ showEmpty('Choose a carrier and route to see the empty quote.'); return; }
  var em = EMPTIES[parseInt(ri,10)];

  var size  = document.getElementById('e_size').value;
  var truck = document.getElementById('e_truck').value;
  var km    = parseFloat(document.getElementById('e_km').value)||0;
  var sHrs  = parseFloat(document.getElementById('e_standing').value)||0;
  var sRate = parseFloat(document.getElementById('e_standrate').value)||0;
  var gAmt  = parseFloat(document.getElementById('e_genset').value)||0;

  var rate = RateEngine.emptyRate(em.rate12m, size);
  var standing = sHrs * sRate;
  var revenueExcl = rate + standing + gAmt;
  var vat = RateEngine.vat(revenueExcl);
  var cost = RateEngine.opCostEmpty(km, gAmt>0);
  var profit = revenueExcl - cost;
  var margin = revenueExcl>0 ? profit/revenueExcl*100 : 0;
  var commission = RateEngine.commission(rate, truck);

  // alternative carriers for the same route
  var alts = EMPTIES.filter(function(x){ return x.from===em.from && x.to===em.to && x.carrier!==em.carrier; });

  var html = '';
  var marginClass = margin>=25 ? 'good' : (margin>=15 ? 'warn' : 'bad');
  html += '<div class="result-head">';
  html += '<div class="result-dest">'+esc(em.carrier)+' · '+esc(em.from)+' → '+esc(em.to)+'</div>';
  html += '<div class="result-profit">'+R(km>0?profit:rate)+'<span class="pl"> '+(km>0?'profit':'rate')+'</span></div>';
  html += '<div class="result-sub"><span class="badge muted">'+size+' empty</span> '
        + (km>0?'<span class="badge '+marginClass+'">'+margin.toFixed(1)+'% margin</span>':'<span class="muted">enter km for profit</span>')+'</div>';
  html += '</div>';

  html += '<div class="brk"><div class="brk-title">Breakdown</div><table class="data brk-table"><tbody>';
  html += brkRow(size+' empty rate'+(size==='6m'?' (½ of 12m)':''), rate);
  if (standing>0) html += brkRow('Standing ('+sHrs+'h × '+R(sRate)+')', standing);
  if (gAmt>0)     html += brkRow('Genset', gAmt);
  html += brkRow('<b>Revenue (excl VAT)</b>', revenueExcl, 'sub');
  html += brkRow('VAT', vat, 'muted');
  if (km>0){
    html += brkRow('Operating cost', -cost, 'cost');
    html += brkRow('<b>Net profit</b>', profit, 'profit');
  }
  html += brkRow('Commission', commission, 'muted');
  html += '</tbody></table></div>';

  if (alts.length){
    html += '<div class="brk"><div class="brk-title">Same route, other carriers</div><table class="data brk-table"><tbody>';
    alts.forEach(function(a){
      html += brkRow(esc(a.carrier), RateEngine.emptyRate(a.rate12m, size));
    });
    html += '</tbody></table></div>';
  }

  document.getElementById('results').innerHTML = html;
}

/* ---- helpers ---- */
function brkRow(label, val, cls){
  var c = cls||'';
  var color = '';
  if (c==='cost')   color = 'color:var(--bad)';
  if (c==='profit') color = 'color:var(--good);font-weight:700';
  if (c==='sub')    color = 'font-weight:700';
  var muted = (c==='muted') ? 'muted' : '';
  return '<tr class="'+(c==='sub'||c==='profit'?'brk-strong':'')+'"><td class="'+muted+'">'+label
       + '</td><td class="num '+muted+'" style="'+color+'">'+R(val)+'</td></tr>';
}
function showEmpty(msg){ document.getElementById('results').innerHTML =
  '<div class="empty"><div class="big">⊞</div>'+msg+'</div>'; }
function esc(s){ return String(s).replace(/[&<>"]/g, function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

function pickClient(c){ selClient = c; recompute(); }
function toggleSur(key, on){ selSurcharges[key] = on; recompute(); }

function recompute(){ if (mode==='full') computeFull(); else computeEmpty(); }

/* ---- Empty carrier/route population ---- */
(function initEmpty(){
  var carriers = [];
  EMPTIES.forEach(function(e){ if (carriers.indexOf(e.carrier)<0) carriers.push(e.carrier); });
  var cs = document.getElementById('e_carrier');
  carriers.forEach(function(c){ var o=document.createElement('option'); o.value=c; o.textContent=c; cs.appendChild(o); });
  cs.addEventListener('change', function(){
    var rs = document.getElementById('e_route');
    rs.innerHTML = '<option value="">— choose route —</option>';
    EMPTIES.forEach(function(e,idx){
      if (e.carrier===cs.value){
        var o=document.createElement('option'); o.value=idx;
        o.textContent = e.from+' → '+e.to+'  ('+R(e.rate12m)+')';
        rs.appendChild(o);
      }
    });
    selClient=null; recompute();
  });
})();

/* ---- Wire all inputs ---- */
['f_dest','f_size','f_genset','f_standing','f_truck'].forEach(function(id){
  document.getElementById(id).addEventListener('change', function(){
    if (id==='f_dest') selClient=null; // reset chosen client when destination changes
    recompute();
  });
});
document.getElementById('f_standing').addEventListener('input', recompute);
['e_route','e_size','e_genset','e_truck','e_km','e_standing','e_standrate'].forEach(function(id){
  var el=document.getElementById(id);
  el.addEventListener('change', recompute); el.addEventListener('input', recompute);
});

recompute();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
