<?php
/**
 * TMS — config.local.example.php
 *
 * TO GO LIVE: copy this file to  config.local.php  on the server and fill in
 * your real values. config.php loads it automatically. Never commit the real
 * config.local.php (it's already in .gitignore) and keep your secret private.
 *
 * Generate a secret: php -r "echo bin2hex(random_bytes(24));"
 */

putenv('TMS_ENV=production');

// Database (from your hosting control panel — names are usually prefixed)
putenv('TMS_DB_HOST=localhost');
putenv('TMS_DB_PORT=3306');          // standard on shared hosting; leave as-is
putenv('TMS_DB_NAME=yourcpaneluser_tms');
putenv('TMS_DB_USER=yourcpaneluser_app');
putenv('TMS_DB_PASS=put-the-database-password-here');

// Leave EMPTY if the app is served at the domain root (e.g. https://app.yourdomain.com/).
// Set to e.g. '/system' only if it lives in a subfolder.
putenv('TMS_BASE_URL=');

// A long random string — used to salt sessions/CSRF. Keep it secret.
putenv('TMS_SECRET=replace-with-40-plus-random-characters');
