<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in production
ini_set('error_log', dirname(__FILE__) . '/../../logs.log');
/**
 * API Endpoint: /admin/api/monitoring.php
 * GET - Fetch recent conversations for monitoring grid
 */

require_once __DIR__ . '/../../controllers/DashboardController.php';

$controller = new DashboardController();
$controller->getMonitoringConversations();
