<?php
/**
 * TMS — index.php
 * Entry point. Sends you to the dashboard if signed in, else login.
 */
require_once __DIR__ . '/includes/auth.php';
redirect(is_logged_in() ? role_home() : 'login.php');
