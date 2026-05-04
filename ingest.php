<?php
/**
 * Catalog ingest CLI script
 *
 * Usage:
 *   php ingest.php           -- skip if catalog unchanged
 *   php ingest.php --force   -- re-ingest even if unchanged
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

set_time_limit(0);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/AppConfig.php';
require_once __DIR__ . '/services/IngestService.php';

$config       = AppConfig::getInstance();
$force        = in_array('--force', $argv);
$ingestCfg    = $config->get('ingest');
$supabaseCfg  = $config->get('supabase');
$geminiApiKey = $config->get('gemini', 'apiKey');

if (empty($ingestCfg['catalogUrl'])) {
    fwrite(STDERR, "Error: CATALOG_INGEST_URL not set in .env\n");
    exit(1);
}
if (empty($supabaseCfg['restUrl'])) {
    fwrite(STDERR, "Error: SUPABASE_REST_URL not set in .env\n");
    exit(1);
}
if (empty($supabaseCfg['key'])) {
    fwrite(STDERR, "Error: SUPABASE_KEY not set in .env\n");
    exit(1);
}

$service = new IngestService($geminiApiKey, $ingestCfg['catalogUrl'], $supabaseCfg['restUrl'], $supabaseCfg['key']);
$result  = $service->run($force);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($result['success'] ? 0 : 1);
