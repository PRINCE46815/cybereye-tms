<?php
/**
 * TMS — includes/driver_pay.php
 * Driver pay is a rate per route + container size. These helpers look up
 * the pay for a trip and list the rate book for pickers.
 */

/** Pay for a route + size. Exact size first, then any size. Null if no match. */
function driver_pay_for($route, $size)
{
    $route = trim((string) $route);
    if ($route === '') return null;
    $row = db_row("SELECT amount FROM driver_pay_rates WHERE active=1 AND UPPER(route)=UPPER(?) AND container_size=? LIMIT 1", array($route, $size));
    if ($row) return (float) $row['amount'];
    $row = db_row("SELECT amount FROM driver_pay_rates WHERE active=1 AND UPPER(route)=UPPER(?) ORDER BY container_size LIMIT 1", array($route));
    return $row ? (float) $row['amount'] : null;
}

/** All active rates (for pickers / JS matching). */
function driver_pay_options()
{
    try {
        return db_all("SELECT id, route, container_size, amount FROM driver_pay_rates WHERE active=1 ORDER BY route, container_size");
    } catch (Exception $e) { return array(); }
}
