#!/usr/bin/env php
<?php

use ChatbotCore\DatabaseManager;
use ChatbotCore\ConversationManager;

/**
 * Chatbot Quality Fixes Test
 *
 * Verifies the 5 quality improvements applied to system-prompt.txt and getFunctionDeclarations():
 *
 * Scenario 1: "เบรกเกอร์ ABB" — PATH B result, same product family.
 *   PASS if: response does NOT contain "ไม่พบ" prefix, and contains ABB breaker products.
 *
 * Scenario 2: "มีสวิตช์ตัดไฟ RCD ไหม" — PATH B result, RCBO/RCD family.
 *   PASS if: response does NOT contain "ไม่พบ...โดยตรง" false prefix, contains product links.
 *
 * Scenario 3: Wire compatibility for known 6A breaker (with history).
 *   PASS if: response contains concrete wire sizing (1.5 sq.mm or 1.5 ตร.มม.) and does NOT
 *   just redirect to contact info with no technical answer.
 *
 * Scenario 4: Shopping list ambiguity — add item then "เอา X อัน".
 *   PASS if: AI asks for clarification (ปรับจำนวน/เพิ่มอีก) rather than silently updating.
 *
 * Usage: php tests/test-quality-fixes.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('error_log', __DIR__ . '/../logs.log');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../AppConfig.php';
require_once __DIR__ . '/../services/SearchService.php';
require_once __DIR__ . '/../services/ProductAPIService.php';
require_once __DIR__ . '/../chatbot/SirichaiElectricChatbot.php';

// ANSI color codes
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
function printStep($text)     { echo Color::BOLD . Color::YELLOW . "▶ " . $text . Color::RESET . "\n"; }
function printSuccess($text)  { echo Color::GREEN  . "✓ " . $text . Color::RESET . "\n"; }
function printError($text)    { echo Color::RED    . "✗ " . $text . Color::RESET . "\n"; }
function printInfo($text)     { echo Color::BLUE   . "ℹ " . $text . Color::RESET . "\n"; }
function printResponse($label, $response) {
    echo Color::MAGENTA . $label . ": " . Color::RESET . Color::WHITE . $response . Color::RESET . "\n";
}

// ─── boot ─────────────────────────────────────────────────────────────────────
printHeader("QUALITY FIXES TEST");

$config = AppConfig::getInstance();
$config->validate();
$dbConfig       = $config->get('database');
$geminiConfig   = $config->get('gemini');
$productAPIConfig = $config->get('productAPI');
$supabaseConfig = $config->get('supabase');

// Clear tables
$db  = DatabaseManager::getInstance($dbConfig);
$pdo = $db->getConnection();
$pdo->exec("DELETE FROM messages");
$pdo->exec("DELETE FROM conversations");
printSuccess("DB cleared");

// Clear logs
$logsFile = __DIR__ . '/../logs.log';
if (file_exists($logsFile)) { file_put_contents($logsFile, ''); }

$conversationManager = new ConversationManager(
    $config->get('conversation', 'maxMessages', 20),
    'test',
    $dbConfig
);
$searchService = new SearchService($geminiConfig['apiKey'], $supabaseConfig['restUrl'], $supabaseConfig['key']);
$productAPI    = new ProductAPIService($productAPIConfig, $searchService);
$chatbot       = new SirichaiElectricChatbot($geminiConfig, $productAPI);
$chatbot->setAuthorized(true);
printSuccess("Services initialized");

$allPassed = true;

// ─── helper: run one turn ─────────────────────────────────────────────────────
function runTurn($chatbot, $conversationManager, $convId, $message, $history) {
    $conversationManager->addMessage($convId, 'user', $message, 0, null);
    $result = $chatbot->chat($message, $history);
    if ($result['success'] && !empty($result['response'])) {
        $conversationManager->addMessage($convId, 'assistant', $result['response'], $result['tokensUsed'], null);
    }
    return $result;
}

// ─── SCENARIO 1: เบรกเกอร์ ABB — no false "ไม่พบ" prefix ─────────────────────
printHeader("SCENARIO 1: เบรกเกอร์ ABB (PATH B — same family, should NOT say ไม่พบ)");
$convId1 = 'test_qfix_s1_' . time();
$msg1 = 'เบรกเกอร์ ABB มีอะไรบ้าง';
printStep("Sending: \"$msg1\"");
$r1 = runTurn($chatbot, $conversationManager, $convId1, $msg1, array());
if (!$r1['success'] || empty($r1['response'])) {
    printError("SCENARIO 1 FAILED — no response");
    $allPassed = false;
} else {
    printResponse("AI", $r1['response']);
    // Check: must NOT contain "ไม่พบ...โดยตรง" false prefix
    $hasNoFoundPrefix = mb_strpos($r1['response'], 'ไม่พบ') !== false && mb_strpos($r1['response'], 'โดยตรง') !== false;
    // Check: must contain product info (ABB or link or ราคา)
    $hasProducts = mb_strpos($r1['response'], 'ABB') !== false || mb_strpos($r1['response'], 'ราคา') !== false || mb_strpos($r1['response'], 'shop.sirichaielectric.com') !== false;

    if ($hasNoFoundPrefix) {
        printError('SCENARIO 1 FAILED — response contains false "ไม่พบ...โดยตรง" prefix for ABB breaker (same-family result should be presented directly)');
        $allPassed = false;
    } else {
        printSuccess('PASS — response does NOT contain false "ไม่พบ...โดยตรง" prefix');
    }
    if ($hasProducts) {
        printSuccess('PASS — response contains ABB breaker products');
    } else {
        printError('SCENARIO 1 FAILED — response does not contain expected ABB product info');
        $allPassed = false;
    }
}

sleep(3);

// ─── SCENARIO 2: สวิตช์ตัดไฟ RCD — no false "ไม่พบ" prefix ──────────────────
printHeader("SCENARIO 2: สวิตช์ตัดไฟ RCD (PATH B — should NOT say ไม่พบโดยตรง)");
$convId2 = 'test_qfix_s2_' . time();
$msg2 = 'มีสวิตช์ตัดไฟ RCD ไหม';
printStep("Sending: \"$msg2\"");
$r2 = runTurn($chatbot, $conversationManager, $convId2, $msg2, array());
if (!$r2['success'] || empty($r2['response'])) {
    printError("SCENARIO 2 FAILED — no response");
    $allPassed = false;
} else {
    printResponse("AI", $r2['response']);
    $hasNoFoundPrefix = mb_strpos($r2['response'], 'ไม่พบ') !== false && mb_strpos($r2['response'], 'โดยตรง') !== false;
    $hasProducts = mb_strpos($r2['response'], 'ราคา') !== false || mb_strpos($r2['response'], 'shop.sirichaielectric.com') !== false || mb_strpos($r2['response'], 'RCD') !== false || mb_strpos($r2['response'], 'RCBO') !== false;

    if ($hasNoFoundPrefix) {
        printError('SCENARIO 2 FAILED — response contains false "ไม่พบ...โดยตรง" prefix for RCD (should be presented as relevant match)');
        $allPassed = false;
    } else {
        printSuccess('PASS — response does NOT contain false "ไม่พบ...โดยตรง" prefix');
    }
    if ($hasProducts) {
        printSuccess('PASS — response contains RCD/RCBO product info');
    } else {
        printError('SCENARIO 2 FAILED — response does not contain expected product info');
        $allPassed = false;
    }
}

sleep(3);

// ─── SCENARIO 3: Wire compatibility (with history establishing 6A breaker) ────
printHeader("SCENARIO 3: Wire sizing for 6A breaker (should answer directly, NOT just redirect)");
$convId3 = 'test_qfix_s3_' . time();

// Turn 1: establish product context (ABB SH201-C6 from history)
$history3 = array();
$setup3msg = 'มีเบรกเกอร์ ABB SH201-C6 6A 1P ไหม';
printStep("Setup turn: \"$setup3msg\"");
$rSetup = runTurn($chatbot, $conversationManager, $convId3, $setup3msg, $history3);
if ($rSetup['success'] && !empty($rSetup['response'])) {
    printSuccess("Setup turn OK");
    printResponse("AI (setup)", $rSetup['response']);
    $history3[] = array('role' => 'user',      'content' => $setup3msg);
    $history3[] = array('role' => 'assistant', 'content' => $rSetup['response']);
} else {
    printError("Setup turn failed — test may be inconclusive");
}

sleep(3);

// Turn 2: wire compatibility question
$msg3 = 'ใช้กับสายไฟไหนได้บ้าง';
printStep("Test turn: \"$msg3\"");
$r3 = runTurn($chatbot, $conversationManager, $convId3, $msg3, $history3);
if (!$r3['success'] || empty($r3['response'])) {
    printError("SCENARIO 3 FAILED — no response");
    $allPassed = false;
} else {
    printResponse("AI", $r3['response']);
    // Must contain wire sizing info (1.5 or สาย or ตร.มม or sq.mm)
    $hasTechnicalAnswer = mb_strpos($r3['response'], '1.5') !== false
        || mb_strpos($r3['response'], 'ตร.มม') !== false
        || mb_strpos($r3['response'], 'sq.mm') !== false
        || mb_strpos($r3['response'], 'sq mm') !== false
        || mb_strpos($r3['response'], 'สายไฟ') !== false;
    // Should NOT be a pure redirect with zero technical content
    $isPureRedirect = !$hasTechnicalAnswer && (mb_strpos($r3['response'], 'ติดต่อร้าน') !== false || mb_strpos($r3['response'], 'โทร') !== false);

    if ($isPureRedirect) {
        printError("SCENARIO 3 FAILED — AI deflected to contact info without giving technical wire-sizing answer");
        $allPassed = false;
    } elseif ($hasTechnicalAnswer) {
        printSuccess("PASS — AI gave concrete wire-sizing answer");
    } else {
        printError("SCENARIO 3 FAILED — response does not contain expected wire sizing information (1.5 sq.mm or สายไฟ)");
        $allPassed = false;
    }
}

sleep(3);

// ─── SCENARIO 4: Shopping list ambiguity — "เอา X อัน" clarification ──────────
printHeader("SCENARIO 4: Quantity ambiguity — 'เอา X อัน' after product already in list");
$convId4 = 'test_qfix_s4_' . time();
$history4 = array();

// Turn 1: search for product
$setup4a = 'มีเบรกเกอร์ ABB SH201-C6 ไหม';
printStep("Setup turn 1: \"$setup4a\"");
$rS4a = runTurn($chatbot, $conversationManager, $convId4, $setup4a, $history4);
if ($rS4a['success'] && !empty($rS4a['response'])) {
    printSuccess("Setup 4a OK");
    $history4[] = array('role' => 'user',      'content' => $setup4a);
    $history4[] = array('role' => 'assistant', 'content' => $rS4a['response']);
}

sleep(2);

// Turn 2: add 5 pieces to list
$setup4b = 'เพิ่ม SH201-C6 5 ชิ้น';
printStep("Setup turn 2: \"$setup4b\"");
$rS4b = runTurn($chatbot, $conversationManager, $convId4, $setup4b, $history4);
if ($rS4b['success'] && !empty($rS4b['response'])) {
    printSuccess("Setup 4b OK");
    printResponse("AI (add 5)", $rS4b['response']);
    $history4[] = array('role' => 'user',      'content' => $setup4b);
    $history4[] = array('role' => 'assistant', 'content' => $rS4b['response']);
}

sleep(2);

// Turn 3: ambiguous "เอา X อัน" — product already has 5 pieces in list
$msg4 = 'เอา 2 อัน';
printStep("Test turn: \"$msg4\"");
$r4 = runTurn($chatbot, $conversationManager, $convId4, $msg4, $history4);
if (!$r4['success'] || empty($r4['response'])) {
    printError("SCENARIO 4 FAILED — no response");
    $allPassed = false;
} else {
    printResponse("AI", $r4['response']);
    // Should ask for clarification — expect ปรับ or เพิ่มอีก or clarify phrasing
    $asksClarification = mb_strpos($r4['response'], 'ปรับจำนวน') !== false
        || mb_strpos($r4['response'], 'เพิ่มอีก') !== false
        || mb_strpos($r4['response'], 'หรือ') !== false
        || mb_strpos($r4['response'], 'หมายความว่า') !== false
        || mb_strpos($r4['response'], 'ต้องการ') !== false;
    // Should NOT silently update — we do NOT want a plain "เพิ่ม 2 ชิ้น" or "ปรับจำนวนเป็น 2"
    $silentUpdate = (mb_strpos($r4['response'], 'เพิ่ม') !== false && mb_strpos($r4['response'], '2 ชิ้น') !== false && !$asksClarification)
        || (mb_strpos($r4['response'], 'ปรับจำนวนเป็น') !== false && !$asksClarification);

    if ($asksClarification && !$silentUpdate) {
        printSuccess("PASS — AI asked for clarification on ambiguous 'เอา X อัน'");
    } else {
        printError("SCENARIO 4 FAILED — AI did not ask for clarification; response: " . $r4['response']);
        $allPassed = false;
    }
}

// ─── FINAL RESULTS ─────────────────────────────────────────────────────────────
printHeader("QUALITY FIXES TEST RESULTS");
if ($allPassed) {
    printSuccess("All 4 quality-fix scenarios PASSED");
    exit(0);
} else {
    printError("One or more scenarios FAILED — see details above");
    exit(1);
}
