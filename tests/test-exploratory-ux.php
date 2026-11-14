#!/usr/bin/env php
<?php

use ChatbotCore\DatabaseManager;
use ChatbotCore\ConversationManager;

/**
 * Exploratory UX Testing — finds weak spots that regression tests miss.
 * Each query is INDEPENDENT (no history) and stresses a different failure mode.
 *
 * Usage: php tests/test-exploratory-ux.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('error_log', __DIR__ . '/../logs.log');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../AppConfig.php';
require_once __DIR__ . '/../services/SearchService.php';
require_once __DIR__ . '/../services/ProductAPIService.php';
require_once __DIR__ . '/../chatbot/SirichaiElectricChatbot.php';

function hr() { echo str_repeat('-', 70) . "\n"; }
function header_line($s) { echo "\n" . str_repeat('=', 70) . "\n" . $s . "\n" . str_repeat('=', 70) . "\n"; }

$queries = array(
    array(
        'id' => 'Q1',
        'label' => 'ORIGINAL BUG (KBSA9003 contradiction)',
        'question' => 'ตู้เหล็ก กันน้ำ ไม่มีหลังคา ฝา1ชั้น กระจก เบอร์3 400x570x200 KBSA9003 KJL',
        'check' => 'Must NOT contradict itself ("ไม่พบ" + showing the product)',
    ),
    array(
        'id' => 'Q2a',
        'label' => 'Exact model lookup',
        'question' => 'ขอราคา KBSA9003',
        'check' => 'Should return KBSA9003 price quickly via Path A',
    ),
    array(
        'id' => 'Q2b',
        'label' => 'Typo in model number (KBSA900 — missing digit)',
        'question' => 'ขอราคา KBSA900',
        'check' => 'Should gracefully handle near-miss (offer KBSA9003?) — not just "not found"',
    ),
    array(
        'id' => 'Q3',
        'label' => 'Mixed Thai/English casual',
        'question' => 'breaker 32A abb มีไหม',
        'check' => 'Should parse mixed-language and search ABB 32A breaker',
    ),
    array(
        'id' => 'Q4',
        'label' => 'Vague/ambiguous',
        'question' => 'ราคาสายไฟ',
        'check' => 'Should ask for type/size/brand or list common types — not search wildly',
    ),
    array(
        'id' => 'Q5',
        'label' => 'Aggressive customer (no context)',
        'question' => 'ทำไมแพง',
        'check' => 'Should deflect politely — not apologize for nothing or try to search',
    ),
    array(
        'id' => 'Q6',
        'label' => 'Out-of-scope identity',
        'question' => 'คุณคือใคร',
        'check' => 'Should introduce as Sirichai Electric assistant and redirect to products',
    ),
    array(
        'id' => 'Q7',
        'label' => 'Duplicated model robustness',
        'question' => 'KBSA9003 KBSA9003 KBSA9003',
        'check' => 'Should treat as single KBSA9003 lookup — no triple results, no confusion',
    ),
    array(
        'id' => 'Q8',
        'label' => 'Pure garbage input',
        'question' => 'asdfqwer',
        'check' => 'Should NOT search wildly. Politely ask for product info',
    ),
);

$results = array();

try {
    header_line('EXPLORATORY UX TEST');

    $config = AppConfig::getInstance();
    $config->validate();
    $dbConfig = $config->get('database');
    $geminiConfig = $config->get('gemini');
    $productAPIConfig = $config->get('productAPI');

    $db = DatabaseManager::getInstance($dbConfig);
    $pdo = $db->getConnection();
    $pdo->exec("DELETE FROM messages");
    $pdo->exec("DELETE FROM conversations");

    // Reset log
    $logsFile = __DIR__ . '/../logs.log';
    if (file_exists($logsFile)) file_put_contents($logsFile, '');

    $conversationManager = new ConversationManager(
        $config->get('conversation', 'maxMessages', 20),
        'test',
        $dbConfig
    );
    $supabaseConfig = $config->get('supabase');
    $searchService = new SearchService($geminiConfig['apiKey'], $supabaseConfig['restUrl'], $supabaseConfig['key']);
    $productAPI = new ProductAPIService($productAPIConfig, $searchService);
    $chatbot = new SirichaiElectricChatbot($geminiConfig, $productAPI);
    $chatbot->setAuthorized(true);

    $convId = 'test_explore_' . time();

    foreach ($queries as $idx => $q) {
        hr();
        echo "[{$q['id']}] {$q['label']}\n";
        echo "Q: {$q['question']}\n";
        echo "Check: {$q['check']}\n";
        hr();

        $start = microtime(true);
        $response = $chatbot->chat($q['question'], array());
        $elapsed = microtime(true) - $start;

        $text = isset($response['response']) ? $response['response'] : '';
        $tokens = isset($response['tokensUsed']) ? $response['tokensUsed'] : 0;
        $criteria = isset($response['searchCriteria']) ? $response['searchCriteria'] : null;
        $success = isset($response['success']) ? $response['success'] : false;

        echo "SUCCESS: " . ($success ? 'true' : 'false') . "\n";
        echo "TOKENS: $tokens | TIME: " . number_format($elapsed, 2) . "s\n";
        if ($criteria) echo "FUNC CALLS: $criteria\n";
        echo "\nRESPONSE:\n$text\n\n";

        $results[] = array(
            'id' => $q['id'],
            'label' => $q['label'],
            'question' => $q['question'],
            'response' => $text,
            'tokens' => $tokens,
            'criteria' => $criteria,
            'time' => $elapsed,
        );

        if ($idx < count($queries) - 1) {
            sleep(6);
        }
    }

    header_line('SUMMARY (RAW)');
    foreach ($results as $r) {
        echo "[{$r['id']}] {$r['label']} | tokens={$r['tokens']} time=" . number_format($r['time'], 1) . "s\n";
        echo "  Q: {$r['question']}\n";
        $snippet = mb_substr(str_replace(array("\n", "\r"), ' / ', $r['response']), 0, 200, 'UTF-8');
        echo "  A: {$snippet}\n";
        if ($r['criteria']) echo "  Calls: {$r['criteria']}\n";
        echo "\n";
    }

    exit(0);

} catch (Exception $e) {
    echo "CRASH: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
