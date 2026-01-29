<?php
/**
 * Admin Index
 *
 * Redirects to dashboard if logged in, otherwise to login page
 */

require_once __DIR__ . '/auth.php';

if (isAdminLoggedIn()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
