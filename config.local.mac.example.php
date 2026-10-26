<?php
/**
 * TMS — LOCAL MAC CONFIG (example)
 * ===========================================
 * TO USE: rename this file to  config.local.php
 * Then edit the values below to match your Mac setup.
 *
 * This file is for LOCAL DEVELOPMENT on your MacBook only.
 * Never upload it to the live server (the live server has its own
 * config.local.php with the Afrihost database details).
 */

// Leave as 'development' locally — it shows errors on screen so you can fix them.
putenv('TMS_ENV=development');

/* ----------------------------------------------------------
   DATABASE — pick the block that matches how you run MySQL
   ---------------------------------------------------------- */

// ---- OPTION A: MAMP (the usual choice on Mac) ----
putenv('TMS_DB_HOST=127.0.0.1');
putenv('TMS_DB_PORT=8889');          // MAMP's MySQL port (NOT 3306)
putenv('TMS_DB_NAME=tms');
putenv('TMS_DB_USER=root');
putenv('TMS_DB_PASS=root');          // MAMP's default password is "root"

// ---- OPTION B: XAMPP / Homebrew MySQL ----
// Delete Option A above and un-comment these instead:
// putenv('TMS_DB_HOST=127.0.0.1');
// putenv('TMS_DB_PORT=3306');
// putenv('TMS_DB_NAME=tms');
// putenv('TMS_DB_USER=root');
// putenv('TMS_DB_PASS=');           // XAMPP/Homebrew often has NO password

/* ----------------------------------------------------------
   BASE URL — must match how you open the site in the browser
   ---------------------------------------------------------- */

// If the folder sits in MAMP's htdocs and you open
//   http://localhost:8888/tms      -> use '/tms'
putenv('TMS_BASE_URL=/tms');

// If you run PHP's built-in server from inside the folder
//   php -S localhost:8000              -> use '' (empty)
// putenv('TMS_BASE_URL=');

// Local-only secret. Any random text is fine for your laptop.
putenv('TMS_SECRET=local-dev-secret-change-on-live-server');
