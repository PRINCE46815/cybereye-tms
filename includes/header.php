<?php
/**
 * TMS — includes/header.php
 * Shared chrome: <head>, sidebar nav, top bar, flash messages.
 *
 * Set $pageTitle (and optionally $pageSubtitle) BEFORE including this.
 * The active nav item is matched on the current script filename.
 */
if (!isset($pageTitle)) { $pageTitle = 'Dashboard'; }
$me      = current_user();
$role    = current_role();
$script  = basename($_SERVER['SCRIPT_NAME']);
$initial = $me ? strtoupper(substr($me['name'], 0, 1)) : '?';

/* Helper: mark nav item active */
function nav_active($file)
{
    global $script;
    return $script === $file ? ' active' : '';
}

/* Inline gold hexagon brand mark (works offline) */
$HEX = '<svg class="hexsvg" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">'
     . '<polygon points="50,4 91,27 91,73 50,96 9,73 9,27" fill="none" stroke="' . brand_color() . '" stroke-width="6"/>'
     . '<polygon points="50,20 77,35 77,65 50,80 23,65 23,35" fill="' . brand_color() . '" opacity="0.16"/>'
     . '<rect x="34" y="44" width="24" height="13" rx="1.5" fill="' . brand_color() . '"/>'
     . '<rect x="58" y="48" width="9" height="9" rx="1" fill="' . brand_color() . '"/>'
     . '<circle cx="40" cy="59" r="3.4" fill="#15181D"/><circle cx="61" cy="59" r="3.4" fill="#15181D"/>'
     . '</svg>';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · <?= e(company_name()) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<?= brand_style() ?>
</head>
<body>
<div class="app">

  <!-- Sidebar -->
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <?php if (is_file(__DIR__ . '/../assets/img/logo.png')): ?>
      <div class="brand-logo"><img src="<?= BASE_URL ?>/assets/img/logo.png" alt="<?= e(company_name()) ?>"></div>
      <?php else: ?>
      <div class="hex"><?= $HEX ?></div>
      <div class="wordmark"><b><?= e(brand_word()) ?></b><span><?= e(setting('company_tagline', 'Logistics & Transport')) ?></span></div>
      <?php endif; ?>
    </div>

    <nav class="nav">
    <?php if ($role === 'driver'): ?>
      <a href="<?= BASE_URL ?>/my_dashboard.php" class="<?= nav_active('my_dashboard.php') ?>"><span class="ico">▦</span> My Dashboard</a>
      <div class="group">Driver</div>
      <a href="<?= BASE_URL ?>/my_trip_new.php" class="<?= nav_active('my_trip_new.php') ?>"><span class="ico">＋</span> Log a Trip</a>
      <a href="<?= BASE_URL ?>/my_trips.php" class="<?= nav_active('my_trips.php') . nav_active('my_trip.php') ?>"><span class="ico">▤</span> My Trips</a>
      <a href="<?= BASE_URL ?>/my_pay.php" class="<?= nav_active('my_pay.php') ?>"><span class="ico">₪</span> My Pay</a>
      <a href="<?= BASE_URL ?>/my_expenses.php" class="<?= nav_active('my_expenses.php') ?>"><span class="ico">⛽</span> My Expenses</a>
    <?php elseif ($role === 'subcontractor'): ?>
      <a href="<?= BASE_URL ?>/my_fleet.php" class="<?= nav_active('my_fleet.php') ?>"><span class="ico">▦</span> My Fleet</a>
      <a href="<?= BASE_URL ?>/my_trips.php" class="<?= nav_active('my_trips.php') ?>"><span class="ico">▤</span> My Trips</a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/dashboard.php" class="<?= nav_active('dashboard.php') ?>">
        <span class="ico">▦</span> Dashboard</a>

      <div class="group">Operations</div>
      <a href="<?= BASE_URL ?>/quote.php" class="<?= nav_active('quote.php') ?>"><span class="ico">⊞</span> Quote &amp; Price</a>
      <a href="<?= BASE_URL ?>/trips.php" class="<?= nav_active('trips.php') . nav_active('trip_form.php') . nav_active('trip_view.php') ?>"><span class="ico">▤</span> Loads / Trips</a>
      <a href="<?= BASE_URL ?>/search.php" class="<?= nav_active('search.php') ?>"><span class="ico">⌕</span> Search Trips</a>

      <div class="group">Fleet &amp; People</div>
      <a href="<?= BASE_URL ?>/trucks.php" class="<?= nav_active('trucks.php') ?>"><span class="ico">⛟</span> Trucks</a>
      <?php if (in_array($role, array('master','admin','finance'), true)): ?>
      <a href="<?= BASE_URL ?>/crane.php" class="<?= nav_active('crane.php') . nav_active('crane_invoice.php') ?>"><span class="ico">⚓</span> &nbsp;↳ Crane Truck</a>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>/drivers.php" class="<?= nav_active('drivers.php') ?>"><span class="ico">☺</span> Drivers</a>
      <a href="<?= BASE_URL ?>/subcontractors.php" class="<?= nav_active('subcontractors.php') ?>"><span class="ico">⚇</span> Subcontractors</a>

      <div class="group">Money</div>
      <a href="<?= BASE_URL ?>/invoices.php" class="<?= nav_active('invoices.php') . nav_active('invoice_new.php') . nav_active('invoice_view.php') ?>"><span class="ico">🧾</span> Invoices</a>
      <a href="<?= BASE_URL ?>/statements.php" class="<?= nav_active('statements.php') ?>"><span class="ico">▦</span> Statements</a>
      <a href="<?= BASE_URL ?>/finance.php" class="<?= nav_active('finance.php') ?>"><span class="ico">₪</span> Finance &amp; Debtors</a>
      <a href="<?= BASE_URL ?>/expenses.php" class="<?= nav_active('expenses.php') ?>"><span class="ico">⛽</span> Expenses</a>
      <?php if ($role === 'master'): ?>
      <a href="<?= BASE_URL ?>/commission.php" class="<?= nav_active('commission.php') ?>"><span class="ico">%</span> Commission</a>
      <?php endif; ?>

      <div class="group">Insight</div>
      <a href="<?= BASE_URL ?>/analytics.php" class="<?= nav_active('analytics.php') ?>"><span class="ico">◷</span> Analytics</a>

      <div class="group">Admin</div>
      <?php if (in_array($role, array('master','admin'), true)): ?>
      <a href="<?= BASE_URL ?>/rates.php" class="<?= nav_active('rates.php') ?>"><span class="ico">⚙</span> Rates &amp; Costs</a>
      <a href="<?= BASE_URL ?>/clients.php" class="<?= nav_active('clients.php') ?>"><span class="ico">⚑</span> Clients</a>
      <a href="<?= BASE_URL ?>/destinations.php" class="<?= nav_active('destinations.php') ?>"><span class="ico">◎</span> Destinations</a>
      <a href="<?= BASE_URL ?>/empty_rates.php" class="<?= nav_active('empty_rates.php') ?>"><span class="ico">◳</span> Empty Rates</a>
      <a href="<?= BASE_URL ?>/driver_rates.php" class="<?= nav_active('driver_rates.php') ?>"><span class="ico">⛁</span> Driver Pay Rates</a>
      <a href="<?= BASE_URL ?>/settings.php" class="<?= nav_active('settings.php') ?>"><span class="ico">⚙</span> Settings</a>
      <?php if ($role === 'master'): ?>
      <a href="<?= BASE_URL ?>/users.php" class="<?= nav_active('users.php') ?>"><span class="ico">☻</span> Users</a>
      <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
    </nav>

    <div class="foot">
      <div class="draweracts">
        <a class="btn ghost sm" href="<?= BASE_URL ?>/account.php">My Account</a>
        <a class="btn ghost sm" href="<?= BASE_URL ?>/logout.php">Sign out</a>
      </div>
      <?php $mo = trim((string) setting('company_motto', '')); if ($mo !== ''): ?><b><?= e($mo) ?></b><?php endif; ?>
    </div>
  </aside>
  <div class="backdrop" id="backdrop" onclick="toggleNav(false)"></div>

  <!-- Main -->
  <div class="main">
    <header class="topbar">
      <div class="flex">
        <button class="menu-btn" onclick="toggleNav(true)" aria-label="Menu">☰</button>
        <div class="title"><?= e($pageTitle) ?>
          <?php if (!empty($pageSubtitle)): ?><small><?= e($pageSubtitle) ?></small><?php endif; ?>
        </div>
      </div>
      <div class="userchip">
        <div class="who"><b><?= e($me['name']) ?></b><span><?= e($role) ?></span></div>
        <div class="av"><?= e($initial) ?></div>
        <span class="topbar-acc">
          <a class="btn ghost sm" href="<?= BASE_URL ?>/account.php" title="My account &amp; security">Account</a>
          <a class="btn ghost sm" href="<?= BASE_URL ?>/logout.php">Sign out</a>
        </span>
      </div>
    </header>

    <main class="content">
      <?php $flashes = get_flashes(); if ($flashes): ?>
        <div class="flashes">
          <?php foreach ($flashes as $f): ?>
            <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
