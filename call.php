<?php
/**
 * Voice call backend (SPIKE). Separate from index.php so the text chat is untouched.
 *   POST call.php?action=token  → single-use Gemini Live token, prompt + tools locked server-side
 *   POST call.php?action=tool   → runs a tool the live model asked for
 * Both need the same X-Chat-Token as /chat. Off unless CALL_ENABLED=1.
 */
ini_set('display_errors', 0);
ini_set('error_log', __DIR__ . '/logs.log');
ini_set('log_errors', '1');
header('Content-Type: application/json');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/AppConfig.php';
require_once __DIR__ . '/services/ProductAPIService.php';
require_once __DIR__ . '/services/SearchService.php';
require_once __DIR__ . '/services/ChatAccess.php';
require_once __DIR__ . '/services/InputGuard.php';

const CALL_MAX_SECONDS = 600; // the Live token expires after this, which ends the call

function out($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$config = AppConfig::getInstance();
// Like Config::getEnv: $_ENV first — some hosts disable putenv(), so getenv() alone sees nothing.
function env($key) {
    return isset($_ENV[$key]) && $_ENV[$key] !== '' ? $_ENV[$key] : (string)getenv($key);
}
if (env('CALL_ENABLED') !== '1') {
    out(404, array('success' => false, 'error' => 'Endpoint not found'));
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    out(200, array('success' => true)); // call.js shows the 📞 button only when this answers 200
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(404, array('success' => false, 'error' => 'Endpoint not found'));
}

// Same session token as /chat (Turnstile-verified), same per-minute limit table.
$now   = time();
$token = isset($_SERVER['HTTP_X_CHAT_TOKEN']) ? $_SERVER['HTTP_X_CHAT_TOKEN'] : '';
$tid   = ChatAccess::verifyToken($token, $config->get('turnstile', 'tokenSecret'), $now);
if ($tid === null) {
    out(401, array('success' => false, 'error' => 'Verification required', 'verify' => true));
}
try {
    $pdo = \ChatbotCore\DatabaseManager::getInstance($config->get('database'))->getConnection();
    $allowed = ChatAccess::withinLimit(function ($id, $window) use ($pdo) {
        $pdo->prepare('INSERT INTO chat_rate_limits (token_id, window_start, hits) VALUES (?, ?, 1)
                       ON DUPLICATE KEY UPDATE hits = hits + 1')->execute(array($id, $window));
        $q = $pdo->prepare('SELECT hits FROM chat_rate_limits WHERE token_id = ? AND window_start = ?');
        $q->execute(array($id, $window));
        return (int)$q->fetchColumn();
    }, $tid, $now, $config->get('turnstile', 'maxPerMinute'));
} catch (\Throwable $e) {
    error_log('[Call] rate limiter failed: ' . $e->getMessage());
    out(503, array('success' => false, 'error' => 'Service temporarily unavailable'));
}
if (!$allowed) {
    out(429, array('success' => false, 'error' => 'Too many requests'));
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$apiKey = $config->get('gemini', 'apiKey');

if ($action === 'token') {
    $model = env('GEMINI_LIVE_MODEL') ?: 'gemini-3.8-live';
    $body = array(
        'uses'                 => 1,
        'expireTime'           => gmdate('Y-m-d\TH:i:s\Z', $now + CALL_MAX_SECONDS),
        'newSessionExpireTime' => gmdate('Y-m-d\TH:i:s\Z', $now + 60),
        // REST name of the SDK's liveConnectConstraints: the setup is locked, the client can't change it.
        'bidiGenerateContentSetup' => array(
            'model'                    => 'models/' . $model,
            'generationConfig'         => array('responseModalities' => array('AUDIO')),
            'systemInstruction'        => array('parts' => array(array('text' => file_get_contents(__DIR__ . '/call-prompt.txt')))),
            'tools'                    => array(array('functionDeclarations' => callTools())),
            'inputAudioTranscription'  => new stdClass(),
            'outputAudioTranscription' => new stdClass(),
        ),
    );
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/auth_tokens');
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'x-goog-api-key: ' . $apiKey),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ));
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($res) ? json_decode($res, true) : null;
    if ($code !== 200 || empty($data['name'])) {
        error_log('[Call] auth_tokens failed: HTTP ' . $code . ' ' . substr((string)$res, 0, 500));
        out(502, array('success' => false, 'error' => 'Could not start call'));
    }
    error_log('[Call] token issued, model ' . $model);
    out(200, array('success' => true, 'token' => $data['name'], 'model' => 'models/' . $model, 'maxSeconds' => CALL_MAX_SECONDS));
}

if ($action === 'tool') {
    $in   = json_decode(file_get_contents('php://input'), true);
    $name = isset($in['name']) ? (string)$in['name'] : '';
    $args = isset($in['args']) && is_array($in['args']) ? $in['args'] : array();
    error_log('[Call] tool ' . $name . ' ' . json_encode($args, JSON_UNESCAPED_UNICODE));
    out(200, array('success' => true, 'result' => runTool($config, $name, $args)));
}

out(404, array('success' => false, 'error' => 'Unknown action'));

/** The live model's args come from a voice transcript: same injection check as typed chat. */
function runTool($config, $name, array $args) {
    $guard = new InputGuard();
    $supa  = $config->get('supabase');
    $api   = new ProductAPIService($config->get('productAPI'),
        new SearchService($config->get('gemini', 'apiKey'), $supa['restUrl'], $supa['key']));

    if ($name === 'search_products') {
        $criterias = array_slice(array_values(array_filter(array_map('trim', (array)(isset($args['criterias']) ? $args['criterias'] : array())), 'strlen')), 0, 10);
        foreach ($criterias as $c) {
            if ($guard->refusalReason((string)$c) !== null) return 'Refused: not a product query.';
        }
        if (!$criterias) return 'No search criteria provided.';
        $r = $api->searchProducts($criterias);
        return $r !== null ? $r : 'No products found.';
    }
    if ($name === 'search_product_detail') {
        $p = isset($args['productName']) ? trim((string)$args['productName']) : '';
        if ($p === '' || $guard->refusalReason($p) !== null) return 'No product name provided.';
        $r = $api->getProductDetail($p);
        return $r !== null ? $r : 'Product details not found.';
    }
    if ($name === 'generate_quotation') {
        $items = isset($args['quotaDetail']) && is_array($args['quotaDetail']) ? $args['quotaDetail'] : array();
        if (!$items) return 'No products provided for quotation.';
        // Web visitors are never authorized: always tier c, whatever the model sends.
        $r = $api->generateFastQuotation($items, 'c');
        return $r !== null ? $r : 'Failed to generate quotation.';
    }
    return 'Unknown function: ' . $name;
}

function callTools() {
    return array(
        array(
            'name'        => 'search_products',
            'description' => 'Search products. One criterias item per product, in the customer\'s own words (type, brand, model, size, colour). Several products → ONE call. Returns lines "Name | Price | Unit | Id" grouped under "## <query>", best match first. Max 10 items.',
            'parameters'  => array('type' => 'object', 'properties' => array(
                'criterias' => array('type' => 'array', 'items' => array('type' => 'string')),
            ), 'required' => array('criterias')),
        ),
        array(
            'name'        => 'search_product_detail',
            'description' => 'Specs of one product (weight, size, thickness, quantity per pack). Use the exact product name from search_products results.',
            'parameters'  => array('type' => 'object', 'properties' => array(
                'productName' => array('type' => 'string'),
            ), 'required' => array('productName')),
        ),
        array(
            'name'        => 'generate_quotation',
            'description' => 'Create the quotation PDF. Call only after the customer confirmed the list and asked for a quotation. Use EXACT product names from search_products results.',
            'parameters'  => array('type' => 'object', 'properties' => array(
                'quotaDetail' => array('type' => 'array', 'items' => array(
                    'type' => 'object',
                    'properties' => array(
                        'productName' => array('type' => 'string'),
                        'amount'      => array('type' => 'number'),
                    ),
                    'required' => array('productName', 'amount'),
                )),
            ), 'required' => array('quotaDetail')),
        ),
    );
}
