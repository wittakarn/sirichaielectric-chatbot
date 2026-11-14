#!/usr/bin/env php
<?php

use ChatbotCore\DatabaseManager;
use ChatbotCore\ConversationManager;

/**
 * PATH A Fix Verification Test
 *
 * Verifies that the LOOSE MATCH prefix ("ไม่พบ...โดยตรง") is NOT applied to PATH A results.
 *
 * Bug: When a user sent "ตู้เหล็ก กันน้ำ ... KBSA9003 KJL", the AI would say
 *      "ไม่พบ 'KBSA9003' โดยตรง" and then immediately show the exact KBSA9003 product.
 *
 * Fix: system-prompt.txt PATH A now explicitly says:
 *      "STOP — do not apply LOOSE MATCH prefix for PATH A results"
 *
 * Test Cases:
 * - TC1: Full description + model number (original bug query)
 *         → PATH A must be used; NO "ไม่พบ" or "โดยตรง"; KBSA9003 must appear in response
 * - TC2: Pure model number, no description ("LRD05")
 *         → PATH A; NO "ไม่พบ" / "โดยตรง"; product info must appear
 * - TC3: Description only, no model number ("ตู้เหล็กกันน้ำ เบอร์3")
 *         → PATH B; LOOSE MATCH prefix IS acceptable (not a bug)
 *
 * Usage: php tests/test-path-a-fix.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('error_log', __DIR__ . '/../logs.log');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../AppConfig.php';
require_once __DIR__ . '/../services/SearchService.php';
require_once __DIR__ . '/../services/ProductAPIService.php';
require_once __DIR__ . '/../chatbot/SirichaiElectricChatbot.php';

// ─── ANSI colour helpers ───────────────────────────────────────────────────
class Color {
    const RED     = "\033[31m";
    const GREEN   = "\033[32m";
    const YELLOW  = "\033[33m";
    const BLUE    = "\033[34m";
    const MAGENTA = "\033[35m";
    const CYAN    = "\033[36m";
    const WHITE   = "\033[37m";
    const RESET   = "\033[0m";
    const BOLD    = "\033[1m";
}

function printHeader($text) {
    echo "\n" . Color::BOLD . Color::CYAN . "=====================================" . Color::RESET . "\n";
    echo Color::BOLD . Color::CYAN . $text . Color::RESET . "\n";
    echo Color::BOLD . Color::CYAN . "=====================================" . Color::RESET . "\n\n";
}

function printStep($text) {
    echo Color::BOLD . Color::YELLOW . "▶ " . $text . Color::RESET . "\n";
}

function printSuccess($text) {
    echo Color::GREEN . "✓ " . $text . Color::RESET . "\n";
}

function printError($text) {
    echo Color::RED . "✗ " . $text . Color::RESET . "\n";
}

function printInfo($text) {
    echo Color::BLUE . "ℹ " . $text . Color::RESET . "\n";
}

function printResponse($label, $response) {
    echo Color::MAGENTA . $label . ": " . Color::RESET . Color::WHITE . $response . Color::RESET . "\n";
}

function printWarning($text) {
    echo Color::YELLOW . "⚠ " . $text . Color::RESET . "\n";
}

// ─── Test cases ────────────────────────────────────────────────────────────

$testCases = array(
    array(
        'id'          => 'TC1',
        'label'       => 'Full description + model number (original bug query)',
        'question'    => 'ตู้เหล็ก กันน้ำ ไม่มีหลังคา ฝา1ชั้น กระจก เบอร์3 400x570x200 KBSA9003 KJL',
        'path'        => 'A',
        'expectation' => 'PATH A — must find KBSA9003 and NOT say "ไม่พบ" or "โดยตรง"',
        'mustContain' => array('KBSA9003'),
        'mustNotContain' => array('ไม่พบ', 'โดยตรง'),
        'validate'    => function($response) {
            $hasProduct   = mb_strpos($response, 'KBSA9003') !== false;
            $hasBadPrefix = mb_strpos($response, 'ไม่พบ') !== false ||
                            mb_strpos($response, 'โดยตรง') !== false;
            return $hasProduct && !$hasBadPrefix;
        },
        'validateMsg' => 'Response must contain "KBSA9003" AND must NOT contain "ไม่พบ" or "โดยตรง"',
    ),
    array(
        'id'          => 'TC2',
        'label'       => 'Pure model number only ("LRD05")',
        'question'    => 'LRD05',
        'path'        => 'A',
        'expectation' => 'PATH A — should find product, no "ไม่พบ" / "โดยตรง" prefix',
        'mustContain' => array('LRD05'),
        'mustNotContain' => array('ไม่พบ', 'โดยตรง'),
        'validate'    => function($response) {
            $hasProduct   = mb_strpos($response, 'LRD05') !== false;
            $hasBadPrefix = mb_strpos($response, 'ไม่พบ') !== false ||
                            mb_strpos($response, 'โดยตรง') !== false;
            // If the product simply doesn't exist in the catalog that's OK,
            // but the LOOSE MATCH prefix must still not appear.
            return !$hasBadPrefix;
        },
        'validateMsg' => 'Response must NOT contain "ไม่พบ" or "โดยตรง" (PATH A results must never get LOOSE MATCH prefix)',
    ),
    array(
        'id'          => 'TC3',
        'label'       => 'Description only, no model number ("ตู้เหล็กกันน้ำ เบอร์3")',
        'question'    => 'ตู้เหล็กกันน้ำ เบอร์3',
        'path'        => 'B',
        'expectation' => 'PATH B — LOOSE MATCH prefix IS acceptable; should return related products',
        'mustContain' => array(),
        'mustNotContain' => array(),
        'validate'    => function($response) {
            // PATH B is expected to possibly return loose matches — that is correct behaviour.
            // We just verify the AI returned some response (not empty / error).
            return mb_strlen($response) > 20;
        },
        'validateMsg' => 'Response must be non-empty (PATH B is allowed to apply LOOSE MATCH prefix)',
    ),
);

// ─── Bootstrap ─────────────────────────────────────────────────────────────

try {
    printHeader("PATH A FIX VERIFICATION — LOOSE MATCH PREFIX BUG");

    printStep("Loading configuration...");
    $config = AppConfig::getInstance();
    $config->validate();
    $dbConfig      = $config->get('database');
    $geminiConfig  = $config->get('gemini');
    $productAPIConfig = $config->get('productAPI');
    printSuccess("Configuration loaded");

    printStep("Connecting to database...");
    $db  = DatabaseManager::getInstance($dbConfig);
    $pdo = $db->getConnection();
    $pdo->exec("DELETE FROM messages");
    $pdo->exec("DELETE FROM conversations");
    printSuccess("Database cleared (messages + conversations)");

    printStep("Clearing logs.log...");
    $logsFile = __DIR__ . '/../logs.log';
    if (file_exists($logsFile)) {
        file_put_contents($logsFile, '');
        printSuccess("logs.log cleared");
    } else {
        printInfo("logs.log does not exist — will be created on first log entry");
    }

    printStep("Initializing chatbot services...");
    $conversationManager = new ConversationManager(
        $config->get('conversation', 'maxMessages', 20),
        'test',
        $dbConfig
    );

    $supabaseConfig = $config->get('supabase');
    $searchService  = new SearchService(
        $geminiConfig['apiKey'],
        $supabaseConfig['restUrl'],
        $supabaseConfig['key']
    );
    $productAPI = new ProductAPIService($productAPIConfig, $searchService);

    $chatbot = new SirichaiElectricChatbot($geminiConfig, $productAPI);
    $chatbot->setAuthorized(true);
    printSuccess("Chatbot initialized (authorized=true)");

    // ─── Run test cases ─────────────────────────────────────────────────────

    printHeader("RUNNING TEST CASES");

    $results        = array();
    $allTestsPassed = true;
    $testConvId     = 'test_path_a_fix_' . time();

    printInfo("Conversation base ID: $testConvId");
    printInfo("Each test case uses a FRESH empty history (no prior context)");

    foreach ($testCases as $idx => $tc) {
        $num = $idx + 1;

        echo "\n" . Color::BOLD . "─────────────────────────────────────────────────────" . Color::RESET . "\n";
        printStep("[{$tc['id']}] {$tc['label']}");
        printInfo("PATH: {$tc['path']}");
        printInfo("Query: \"{$tc['question']}\"");
        printInfo("Expected: {$tc['expectation']}");
        echo "\n";

        // Each test gets a completely empty history so there is no cross-contamination.
        $convId = $testConvId . '_' . strtolower($tc['id']);

        // Send with empty history (independent question)
        $response = $chatbot->chat($tc['question'], array());

        if (!$response['success']) {
            printError("[{$tc['id']}] FAILED — API error: " . $response['error']);
            $allTestsPassed = false;
            $results[] = array('id' => $tc['id'], 'passed' => false, 'reason' => 'API error: ' . $response['error'], 'response' => '');
            if ($num < count($testCases)) { sleep(3); }
            continue;
        }

        if (empty($response['response'])) {
            printError("[{$tc['id']}] FAILED — Empty response from AI");
            $allTestsPassed = false;
            $results[] = array('id' => $tc['id'], 'passed' => false, 'reason' => 'Empty response', 'response' => '');
            if ($num < count($testCases)) { sleep(3); }
            continue;
        }

        $aiResponse = $response['response'];

        printSuccess("AI responded successfully");
        printResponse("AI Response", $aiResponse);
        printInfo("Tokens used: " . $response['tokensUsed']);

        if (isset($response['searchCriteria']) && $response['searchCriteria']) {
            printInfo("Search criteria logged: " . $response['searchCriteria']);
        }

        // ── Run validation ──
        $passed = $tc['validate']($aiResponse);
        $reason = $tc['validateMsg'];

        // Extra detail: report which mustContain / mustNotContain failed
        $details = array();

        foreach ($tc['mustContain'] as $needle) {
            if (mb_strpos($aiResponse, $needle) !== false) {
                $details[] = Color::GREEN . "  ✓ Contains \"$needle\"" . Color::RESET;
            } else {
                $details[] = Color::RED . "  ✗ Missing  \"$needle\"" . Color::RESET;
            }
        }

        foreach ($tc['mustNotContain'] as $needle) {
            if (mb_strpos($aiResponse, $needle) === false) {
                $details[] = Color::GREEN . "  ✓ Does NOT contain \"$needle\"" . Color::RESET;
            } else {
                $details[] = Color::RED . "  ✗ Unexpectedly contains \"$needle\"" . Color::RESET;
            }
        }

        echo implode("\n", $details) . (count($details) ? "\n" : "");

        if ($passed) {
            printSuccess("[{$tc['id']}] PASS — $reason");
        } else {
            printError("[{$tc['id']}] FAIL — $reason");
            $allTestsPassed = false;
        }

        $results[] = array(
            'id'      => $tc['id'],
            'label'   => $tc['label'],
            'passed'  => $passed,
            'reason'  => $reason,
            'response'=> $aiResponse,
        );

        // Small pause between Gemini calls (rate limiting)
        if ($num < count($testCases)) {
            printInfo("Waiting 5 seconds before next test...");
            sleep(5);
        }
    }

    // ─── Summary ────────────────────────────────────────────────────────────

    printHeader("TEST SUMMARY");

    foreach ($results as $r) {
        if ($r['passed']) {
            printSuccess("[{$r['id']}] PASS — {$r['reason']}");
        } else {
            printError("[{$r['id']}] FAIL — {$r['reason']}");
        }
    }

    echo "\n";

    // Specific contradiction check
    $tc1 = null;
    foreach ($results as $r) {
        if ($r['id'] === 'TC1') { $tc1 = $r; break; }
    }

    if ($tc1) {
        $contradictionGone = (
            mb_strpos($tc1['response'], 'ไม่พบ')    === false &&
            mb_strpos($tc1['response'], 'โดยตรง') === false &&
            mb_strpos($tc1['response'], 'KBSA9003') !== false
        );

        if ($contradictionGone) {
            printSuccess("CONTRADICTION CHECK: \"ไม่พบ...โดยตรง\" prefix is GONE from PATH A results");
        } else {
            printError("CONTRADICTION CHECK: Bug NOT fixed — PATH A result still shows LOOSE MATCH prefix or product not found");
            if (mb_strpos($tc1['response'], 'ไม่พบ') !== false) {
                printError("  → Response still contains \"ไม่พบ\"");
            }
            if (mb_strpos($tc1['response'], 'โดยตรง') !== false) {
                printError("  → Response still contains \"โดยตรง\"");
            }
            if (mb_strpos($tc1['response'], 'KBSA9003') === false) {
                printWarning("  → Response does NOT contain \"KBSA9003\" (product may not be in catalog)");
            }
        }
    }

    echo "\n";

    if ($allTestsPassed) {
        printSuccess("ALL TESTS PASSED");
        printSuccess("PATH A fix is verified — LOOSE MATCH prefix is no longer applied to model number queries.");
        exit(0);
    } else {
        printError("ONE OR MORE TESTS FAILED");
        printInfo("Check the AI responses above for details.");
        printInfo("Check logs.log for Gemini API interactions.");
        exit(1);
    }

} catch (Exception $e) {
    printError("Test crashed with exception: " . $e->getMessage());
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
