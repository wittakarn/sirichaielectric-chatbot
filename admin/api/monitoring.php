<?php
/**
 * API Endpoint: /admin/api/monitoring.php
 * GET - Fetch recent conversations for monitoring grid
 */

require_once __DIR__ . '/../../controllers/DashboardController.php';

$controller = new DashboardController();
$controller->getMonitoringConversations();
