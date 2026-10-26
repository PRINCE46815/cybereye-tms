<?php
/**
 * TMS — logout.php
 */
require_once __DIR__ . '/includes/auth.php';
logout();
session_start();
flash('info', 'You have been signed out.');
redirect('login.php');
