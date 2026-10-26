<?php
/**
 * TMS — includes/charts.php
 * Tiny, dependency-free chart helpers. Pure HTML/CSS so they render
 * offline on WAMP with no external libraries. Clean and readable.
 */

/**
 * Horizontal bar chart.
 * $rows: each ['label'=>string, 'value'=>float, 'meta'=>string?, 'tone'=>'good|bad|gold'?]
 * $opts: ['money'=>bool]
 */
function chart_hbars($rows, $opts = array())
{
    if (!$rows) { return '<div class="empty"><div class="big">◔</div>No data in this period.</div>'; }
    $money = !empty($opts['money']);
    $max = 0; foreach ($rows as $r) { $max = max($max, abs((float) $r['value'])); }
    if ($max <= 0) { $max = 1; }

    $out = '<div class="chart">';
    foreach ($rows as $r) {
        $v = (float) $r['value'];
        $w = min(100, abs($v) / $max * 100);
        $tone = isset($r['tone']) ? $r['tone'] : ($v < 0 ? 'bad' : 'gold');
        $val = $money ? ('R ' . number_format($v, 0)) : number_format($v, 0);
        $meta = isset($r['meta']) && $r['meta'] !== '' ? ' <span class="chart-meta">' . e($r['meta']) . '</span>' : '';
        $out .= '<div class="chart-row">'
              . '<div class="chart-label" title="' . e($r['label']) . '">' . e($r['label']) . '</div>'
              . '<div class="chart-track"><div class="chart-bar tone-' . $tone . '" style="width:' . round($w, 1) . '%"></div></div>'
              . '<div class="chart-val">' . $val . $meta . '</div>'
              . '</div>';
    }
    return $out . '</div>';
}

/**
 * Monthly trend — twin columns (revenue + profit) per month.
 * $rows: each ['label'=>'Jan 26', 'revenue'=>float, 'profit'=>float]
 */
function chart_trend($rows)
{
    if (!$rows) { return '<div class="empty"><div class="big">◔</div>No data in this period.</div>'; }
    $max = 0; foreach ($rows as $r) { $max = max($max, (float) $r['revenue'], (float) $r['profit']); }
    if ($max <= 0) { $max = 1; }

    $out = '<div class="trend">';
    foreach ($rows as $r) {
        $rh = round(max(0, (float) $r['revenue']) / $max * 100, 1);
        $ph = round(max(0, (float) $r['profit'])  / $max * 100, 1);
        $out .= '<div class="trend-col">'
              . '<div class="trend-bars">'
              . '<div class="trend-bar rev" style="height:' . $rh . '%" title="Revenue ' . money($r['revenue']) . '"></div>'
              . '<div class="trend-bar pro" style="height:' . $ph . '%" title="Profit ' . money($r['profit']) . '"></div>'
              . '</div><div class="trend-x">' . e($r['label']) . '</div></div>';
    }
    $out .= '</div><div class="trend-legend"><span class="lg rev">Revenue</span><span class="lg pro">Profit</span></div>';
    return $out;
}

/**
 * Status pipeline — labelled segments with counts.
 * $rows: each ['label'=>string,'count'=>int,'tone'=>class?]
 */
function chart_pipeline($rows)
{
    if (!$rows) { return '<div class="empty"><div class="big">◔</div>No trips in this period.</div>'; }
    $total = 0; foreach ($rows as $r) { $total += (int) $r['count']; }
    if ($total <= 0) { $total = 1; }
    $out = '<div class="pipeline">';
    foreach ($rows as $r) {
        $pct = round((int) $r['count'] / $total * 100, 1);
        $tone = isset($r['tone']) ? $r['tone'] : 'gold';
        $out .= '<div class="pipe-row"><div class="pipe-top"><span>' . e($r['label']) . '</span><b>' . (int) $r['count'] . '</b></div>'
              . '<div class="pipe-track"><div class="pipe-bar tone-' . $tone . '" style="width:' . $pct . '%"></div></div></div>';
    }
    return $out . '</div>';
}
