<?php
/**
 * Admin Logout
 *
 * Logs out the current admin user and redirects to login page
 */

require_once __DIR__ . '/auth.php';

// Logout the admin
adminLogout();

// Redirect to login page
header('Location: login.php?logout=1');
exit;
