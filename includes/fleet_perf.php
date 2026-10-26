<?php
/**
 * TMS — includes/fleet_perf.php
 * Shared helpers for the Step 5 profile screens: period windows,
 * performance aggregates from the trips table, and expiry status.
 */

/** Date bounds for a named period. */
function period_bounds($key)
{
    $today = date('Y-m-d');
    switch ($key) {
        case 'last':
            $from = date('Y-m-01', strtotime('first day of last month'));
            $to   = date('Y-m-t',  strtotime('last day of last month'));
            $label = 'Last month'; break;
        case 'ytd':
            $from = date('Y-01-01'); $to = $today; $label = 'This year'; break;
        case 'all':
            $from = '2000-01-01'; $to = $today; $label = 'All time'; break;
        case 'mtd':
        default:
            $from = date('Y-m-01'); $to = $today; $label = 'This month'; $key = 'mtd'; break;
    }
    return array('from' => $from, 'to' => $to, 'label' => $label, 'key' => $key);
}

/** Render a period selector (GET ?period=). $base e.g. 'truck_view.php?id=5'. */
function period_selector($base, $current)
{
    $opts = array('mtd' => 'This month', 'last' => 'Last month', 'ytd' => 'This year', 'all' => 'All time');
    $sep  = strpos($base, '?') === false ? '?' : '&';
    $html = '<div class="period-pick">';
    foreach ($opts as $k => $label) {
        $cls = $k === $current ? 'on' : '';
        $html .= '<a class="' . $cls . '" href="' . BASE_URL . '/' . e($base) . $sep . 'period=' . $k . '">' . e($label) . '</a>';
    }
    return $html . '</div>';
}

/**
 * Performance aggregate from trips, filtered by a fixed column.
 * $col MUST be a trusted literal ('truck_id','driver_id','subcontractor_id').
 */
function trip_perf($col, $id, $from, $to)
{
    return trip_perf_f("$col = ? AND collection_date BETWEEN ? AND ?", array($id, $from, $to));
}

/** Same aggregate as trip_perf but with a caller-supplied WHERE + params (for filters). */
function trip_perf_f($where, $params)
{
    $sql = "SELECT COUNT(*) AS trips,
                   COALESCE(SUM(km),0) AS km,
                   COALESCE(SUM(gross_revenue),0) AS revenue,
                   COALESCE(SUM(net_profit),0) AS profit,
                   COALESCE(SUM(commission),0) AS commission,
                   COALESCE(SUM(subbie_payout),0) AS payout,
                   COALESCE(SUM(driver_pay),0) AS driver_pay,
                   SUM(CASE WHEN has_genset=1 THEN 1 ELSE 0 END) AS genset_jobs,
                   SUM(CASE WHEN pod_collected='collected' THEN 1 ELSE 0 END) AS pods_collected,
                   SUM(CASE WHEN status IN ('Delivered','Invoiced','Paid') THEN 1 ELSE 0 END) AS delivered,
                   MAX(collection_date) AS last_trip
            FROM trips WHERE $where";
    $row = db_row($sql, $params);
    return $row ? $row : array('trips'=>0,'km'=>0,'revenue'=>0,'profit'=>0,'commission'=>0,'payout'=>0,
                               'driver_pay'=>0,'genset_jobs'=>0,'pods_collected'=>0,'delivered'=>0,'last_trip'=>null);
}

/** Recent trips for a profile. */
function recent_trips($col, $id, $limit = 10)
{
    return recent_trips_f("t.$col = ?", array($id), $limit);
}

/** Recent trips with a caller-supplied WHERE (use alias 't') + params — for filtered profiles. */
function recent_trips_f($where, $params, $limit = 15)
{
    $limit = (int) $limit;
    return db_all(
        "SELECT t.id, t.trip_ref, t.collection_date, t.route, t.container_number, t.container_size, t.load_state,
                t.gross_revenue, t.net_profit, t.driver_pay, t.subbie_payout, t.status, t.pod_collected, c.name AS client_name
         FROM trips t LEFT JOIN clients c ON c.id = t.billing_client_id
         WHERE $where ORDER BY COALESCE(t.collection_date,t.created_at) DESC, t.id DESC LIMIT $limit",
        $params);
}

/** One label/value row in a definition list. */
function dl_row($label, $value)
{
    echo '<div class="dl-row"><span class="dl-l">' . e($label) . '</span><span class="dl-v">' . $value . '</span></div>';
}

/** Expiry status -> array(label, css-class). */
function expiry_status($date, $alertDays)
{
    if (!$date) return array('—', 'muted');
    $ts = strtotime($date);
    if ($ts < time()) return array('Expired', 'bad');
    if ($ts < strtotime("+$alertDays days")) return array('Due soon', 'warn');
    return array('Valid', 'good');
}

/** Small status badge for a trip row. */
function trip_status_badge($s)
{
    $map = array('Draft'=>'muted','Scheduled'=>'info','In Transit'=>'gold','Delivered'=>'good',
                 'Invoiced'=>'info','Paid'=>'good','On Hold'=>'warn','Cancelled'=>'bad');
    $cls = isset($map[$s]) ? $map[$s] : 'muted';
    return '<span class="badge ' . $cls . '">' . e($s) . '</span>';
}

/** Render a recent-trips table (shared by all three profiles). */
function render_recent_trips($trips)
{
    if (!$trips) { echo '<div class="empty"><div class="big">▤</div>No trips match these filters.</div>'; return; }
    $tr = 0; $tp = 0;
    echo '<div class="table-wrap"><table class="data"><thead><tr>'
       . '<th>Ref</th><th>Date</th><th>Client</th><th>Route</th><th>Container</th><th>Box</th>'
       . '<th class="num">Revenue</th><th class="num">Profit</th><th>Status</th><th>POD</th></tr></thead><tbody>';
    foreach ($trips as $t) {
        $tr += (float) $t['gross_revenue']; $tp += (float) $t['net_profit'];
        echo '<tr>'
           . '<td><a href="' . BASE_URL . '/trip_view.php?id=' . (int)$t['id'] . '"><b>' . e($t['trip_ref']) . '</b></a></td>'
           . '<td class="muted">' . ($t['collection_date'] ? fdate($t['collection_date']) : '—') . '</td>'
           . '<td>' . e($t['client_name'] ? $t['client_name'] : '—') . '</td>'
           . '<td>' . e($t['route'] ? $t['route'] : '—') . '</td>'
           . '<td>' . e(!empty($t['container_number']) ? $t['container_number'] : '—') . '</td>'
           . '<td><span class="badge muted">' . e(strtoupper($t['container_size'])) . ' ' . e($t['load_state']) . '</span></td>'
           . '<td class="num">' . money($t['gross_revenue']) . '</td>'
           . '<td class="num"><b>' . money($t['net_profit']) . '</b></td>'
           . '<td>' . trip_status_badge($t['status']) . '</td>'
           . '<td>' . ($t['pod_collected']==='collected' ? '<span class="badge good">✓</span>' : '<span class="badge warn">—</span>') . '</td>'
           . '</tr>';
    }
    echo '<tr><td colspan="6" class="num" style="font-weight:700">Totals · ' . count($trips) . ' trip' . (count($trips)===1?'':'s') . '</td>'
       . '<td class="num"><b>' . money($tr) . '</b></td><td class="num"><b>' . money($tp) . '</b></td><td colspan="2"></td></tr>';
    echo '</tbody></table></div>';
}

/**
 * Read profile filters from GET: a custom date range (from/to) overrides the
 * period preset; plus client + job type. Returns WHERE fragments (bare and
 * 't.'-aliased, sharing one params list) and the current UI state.
 */
function profile_filters($fixedCol, $fixedId)
{
    if (get('from') !== null || get('to') !== null) {
        $from = get('from', date('Y-m-01'));
        $to   = get('to', date('Y-m-d'));
        $label = fdate($from) . ' – ' . fdate($to);
        $periodKey = 'custom';
    } else {
        $pb = period_bounds(get('period', 'mtd'));
        $from = $pb['from']; $to = $pb['to']; $label = $pb['label']; $periodKey = $pb['key'];
    }
    $client = (int) get('client', 0);
    $job    = get('job', '');

    $build = function ($p) use ($fixedCol, $client, $job) {
        $x = $p ? $p . '.' : '';
        $w = "{$x}{$fixedCol} = ? AND {$x}collection_date BETWEEN ? AND ?";
        if ($client)     $w .= " AND {$x}billing_client_id = ?";
        if ($job !== '') $w .= " AND {$x}job_type = ?";
        return $w;
    };
    $params = array($fixedId, $from, $to);
    if ($client)     $params[] = $client;
    if ($job !== '') $params[] = $job;

    return array('where' => $build(''), 'where_t' => $build('t'), 'params' => $params,
                 'from' => $from, 'to' => $to, 'label' => $label, 'period' => $periodKey,
                 'client' => $client, 'job' => $job, 'filtered' => ($client || $job !== ''));
}

/** Render the shared profile filter bar (date range + client + job + period presets). */
function profile_filter_bar($script, $id, $f, $clients, $jobOpts)
{
    $keep = '';
    if ($f['client'])     $keep .= '&client=' . $f['client'];
    if ($f['job'] !== '') $keep .= '&job=' . urlencode($f['job']);
    ob_start(); ?>
    <form method="get" action="<?= BASE_URL ?>/<?= e($script) ?>" class="flex" style="gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <div class="form-row" style="margin:0"><label class="lbl">From</label><input type="date" name="from" value="<?= e($f['from']) ?>"></div>
      <div class="form-row" style="margin:0"><label class="lbl">To</label><input type="date" name="to" value="<?= e($f['to']) ?>"></div>
      <div class="form-row" style="margin:0"><label class="lbl">Client</label><select name="client"><option value="0">All</option>
        <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $f['client']===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-row" style="margin:0"><label class="lbl">Job</label><select name="job"><option value="">All</option>
        <?php foreach ($jobOpts as $j): ?><option value="<?= $j ?>" <?= $f['job']===$j?'selected':'' ?>><?= ucfirst($j) ?></option><?php endforeach; ?></select></div>
      <button class="btn dark">Apply</button>
    </form>
    <div class="flex" style="justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">
      <span class="muted" style="font-size:13px">Showing — <b><?= e($f['label']) ?></b><?= $f['filtered'] ? ' · filtered' : '' ?></span>
      <div class="period-pick">
        <?php foreach (array('mtd'=>'This month','last'=>'Last month','ytd'=>'This year','all'=>'All time') as $kk => $lbl):
          $on = ($f['period'] === $kk && !$f['filtered']) ? 'on' : ''; ?>
          <a class="<?= $on ?>" href="<?= BASE_URL ?>/<?= e($script) ?>?id=<?= (int)$id ?>&period=<?= $kk ?><?= $keep ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php return ob_get_clean();
}
