#!/usr/bin/env php
<?php
/**
 * One-time setup and cron-safe catalog embedding sync.
 *
 * Run once after deploying the RAG feature to populate catalog_embeddings.
 * Re-run whenever the product catalog changes (or schedule as a daily cron
 * after the 24 h catalog cache has been refreshed).
 *
 * Usage:
 *   php scripts/sync-catalog-embeddings.php            # normal sync
 *   php scripts/sync-catalog-embeddings.php --force    # re-embed everything
 *
 * Exit codes: 0 = success, 1 = error
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('error_log', __DIR__ . '/../logs.log');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../AppConfig.php';
require_once __DIR__ . '/../services/ProductAPIService.php';
require_once __DIR__ . '/../services/CatalogEmbeddingService.php';

use ChatbotCore\DatabaseManager;

$forceRebuild = in_array('--force', $argv ?? array());

// ── Configuration ─────────────────────────────────────────────────────────────

try {
    $config = AppConfig::getInstance();
    $config->validate();
} catch (Exception $e) {
    fwrite(STDERR, 'Config error: ' . $e->getMessage() . "\n");
    exit(1);
}

$geminiConfig     = $config->get('gemini');
$dbConfig         = $config->get('database');
$productAPIConfig = $config->get('productAPI');
$embeddingConfig  = $config->get('embedding');

$apiKey = isset($geminiConfig['apiKey']) ? $geminiConfig['apiKey'] : '';
$topK   = isset($embeddingConfig['topK']) ? (int) $embeddingConfig['topK'] : 5;

if (empty($apiKey)) {
    fwrite(STDERR, "GEMINI_API_KEY is not set.\n");
    exit(1);
}

// ── Fetch catalog ─────────────────────────────────────────────────────────────

echo "Fetching catalog summary...\n";
$productAPI   = new ProductAPIService($productAPIConfig);
$catalogText  = $productAPI->getCatalogSummary();

if (empty($catalogText)) {
    fwrite(STDERR, "Failed to fetch catalog summary.\n");
    exit(1);
}

$lineCount = count(array_filter(array_map('trim', explode("\n", $catalogText))));
echo "Catalog loaded: " . strlen($catalogText) . " bytes, ~{$lineCount} lines\n";

// ── Database ──────────────────────────────────────────────────────────────────

$db  = DatabaseManager::getInstance($dbConfig);
$pdo = $db->getConnection();

// If --force, wipe existing embeddings so every row is re-embedded
if ($forceRebuild) {
    $pdo->exec('DELETE FROM catalog_embeddings');
    echo "--force: cleared catalog_embeddings table\n";
}

// ── Sync ──────────────────────────────────────────────────────────────────────

$service = new CatalogEmbeddingService($pdo, $apiKey, $topK);

echo "Syncing embeddings (this may take a few minutes on first run)...\n";
$start   = microtime(true);
$synced  = $service->syncCatalog($catalogText);
$elapsed = round(microtime(true) - $start, 1);

$total = $pdo->query('SELECT COUNT(*) FROM catalog_embeddings')->fetchColumn();

echo "Done in {$elapsed}s: {$synced} categories embedded/updated, {$total} total in table.\n";
exit(0);
