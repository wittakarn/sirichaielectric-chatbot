<?php
/**
 * Catalog ingest CLI script
 *
 * Usage:
 *   php ingest.php           -- skip if catalog unchanged
 *   php ingest.php --force   -- re-ingest even if unchanged
 */

ini_set('error_log', __DIR__ . '/logs.log');

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

error_log('[Ingest] Started');

$service = new IngestService($geminiApiKey, $ingestCfg['catalogUrl'], $supabaseCfg['restUrl'], $supabaseCfg['key']);
$result  = $service->run($force);

if ($result['success']) {
    if ($result['status'] === 'unchanged') {
        error_log('[Ingest] Skipped — catalog unchanged');
    } else {
        error_log('[Ingest] Done — ingested ' . $result['documents'] . ' documents');
    }
} else {
    error_log('[Ingest] FAILED — ' . $result['error']);
}

exit($result['success'] ? 0 : 1);
