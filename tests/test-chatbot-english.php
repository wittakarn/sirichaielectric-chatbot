#!/usr/bin/env php
<?php

use ChatbotCore\DatabaseManager;
use ChatbotCore\ConversationManager;

/**
 * Chatbot Integration Test - English Conversation
 *
 * Simulates an English-speaking customer. Thai reply templates in system-prompt.txt
 * must be translated, and English quotation requests must trigger generate_quotation.
 *
 * - Q1: "hi" → English "please tell me the product" fallback, not Thai, not the security refusal
 * - Q2: English price question → product links, answered in English (no Thai template text)
 * - Q3: "Please create a quotation ..." → generate_quotation called, PDF link returned
 *
 * Usage: php tests/test-chatbot-english.php
 */

// Set error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('error_log', __DIR__ . '/../logs.log');

// Load dependencies
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../AppConfig.php';
require_once __DIR__ . '/../services/SearchService.php';
require_once __DIR__ . '/../services/ProductAPIService.php';
require_once __DIR__ . '/../chatbot/SirichaiElectricChatbot.php';

// ANSI color codes for terminal output
class Color {
    const RED = "\033[31m";
    const GREEN = "\033[32m";
    const YELLOW = "\033[33m";
    const BLUE = "\033[34m";
    const MAGENTA = "\033[35m";
    const CYAN = "\033[36m";
    const WHITE = "\033[37m";
    const RESET = "\033[0m";
    const BOLD = "\033[1m";
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

// Thai polite particles / template words only appear when the AI answers in Thai.
// Product names may legitimately be Thai, so we check these markers instead of "any Thai char".
function hasThaiReplyMarkers($response) {
    foreach (array('ค่ะ', 'คะ', 'ราคา:', 'ขออภัย') as $marker) {
        if (mb_strpos($response, $marker) !== false) {
            return true;
        }
    }
    return false;
}

// All questions share a single conversation history
$testConversationId = 'test_english_' . time();
$questions = array(
    array(
        'question' => 'hi',
        'expectation' => 'AI replies in English asking for product name/brand/model. Must NOT reply in Thai or with the security refusal.',
        'validate' => function($response) {
            $noThai = !preg_match('/\p{Thai}/u', $response);
            $asksForProduct = preg_match('/product|brand|model/i', $response) === 1;
            return $noThai && $asksForProduct;
        },
        'validateMsg' => 'Response must be English only and ask for product/brand/model'
    ),
    array(
        'question' => 'How much is the ABB 3P 32A circuit breaker?',
        'expectation' => 'AI searches products and answers in English with product links. No Thai template text.',
        'validate' => function($response) {
            $hasLink = mb_strpos($response, 'https://shop.sirichaielectric.com/product/') !== false;
            return $hasLink && !hasThaiReplyMarkers($response);
        },
        'validateMsg' => 'Response must contain product links and no Thai reply markers (ค่ะ/คะ/ราคา:/ขออภัย)'
    ),
    array(
        'question' => 'Please create a quotation for 2 pcs of the first product.',
        'expectation' => 'English quotation request triggers generate_quotation (default rate c) and returns a PDF link in English',
        'validate' => function($response) {
            $hasPdf = mb_strpos($response, 'http') !== false
                && (mb_stripos($response, 'pdf') !== false || mb_stripos($response, 'quotation') !== false);
            return $hasPdf && !hasThaiReplyMarkers($response);
        },
        'validateMsg' => 'Response must contain a PDF/quotation link and no Thai reply markers'
    ),
);

try {
    printHeader("CHATBOT TEST - ENGLISH CONVERSATION");

    // Step 1: Load configuration
    printStep("Loading configuration...");
    $config = AppConfig::getInstance();
    $config->validate();
    $dbConfig = $config->get('database');
    $geminiConfig = $config->get('gemini');
    $productAPIConfig = $config->get('productAPI');
    printSuccess("Configuration loaded");

    // Step 2: Clear messages table
    printStep("Clearing messages table...");
    $db = DatabaseManager::getInstance($dbConfig);
    $pdo = $db->getConnection();
    $pdo->exec("DELETE FROM messages");
    $messagesCount = $pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();
    printSuccess("Messages table cleared (count: $messagesCount)");

    // Step 3: Clear conversations table
    printStep("Clearing conversations table...");
    $pdo->exec("DELETE FROM conversations");
    $conversationsCount = $pdo->query("SELECT COUNT(*) FROM conversations")->fetchColumn();
    printSuccess("Conversations table cleared (count: $conversationsCount)");

    // Step 4: Clear logs.log
    printStep("Clearing logs.log...");
    $logsFile = __DIR__ . '/../logs.log';
    if (file_exists($logsFile)) {
        file_put_contents($logsFile, '');
        printSuccess("logs.log cleared");
    } else {
        printInfo("logs.log does not exist (will be created on first log)");
    }

    // Step 5: Initialize services
    printStep("Initializing chatbot services...");
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
    printSuccess("Chatbot initialized");

    // Step 6: Run tests
    printHeader("RUNNING TESTS");
    printInfo("Conversation ID: $testConversationId");
    printInfo("All questions share the same conversation history");

    $allTestsPassed = true;
    $conversationHistory = array();

    foreach ($questions as $index => $testCase) {
        $questionNum = $index + 1;
        $question = $testCase['question'];
        $expectation = $testCase['expectation'];

        echo "\n" . Color::BOLD . "─────────────────────────────────────" . Color::RESET . "\n";
        printStep("Q$questionNum: \"$question\"");
        printInfo("Expected: $expectation");

        // Add user message to conversation
        $conversationManager->addMessage(
            $testConversationId,
            'user',
            $question,
            0,
            null
        );

        // Get AI response with accumulated history
        $response = $chatbot->chat($question, $conversationHistory);

        // Check response
        if (!$response['success']) {
            printError("Test FAILED - Error: " . $response['error']);
            $allTestsPassed = false;
            break;
        }

        if (empty($response['response'])) {
            printError("Test FAILED - Empty response from AI");
            $allTestsPassed = false;
            break;
        }

        printSuccess("AI responded successfully");
        printResponse("Answer", $response['response']);
        printInfo("Tokens used: " . $response['tokensUsed']);

        if (isset($response['searchCriteria']) && $response['searchCriteria']) {
            printInfo("Search criteria: " . $response['searchCriteria']);
        }

        // Run validation if defined
        if (isset($testCase['validate'])) {
            $passed = $testCase['validate']($response['response']);
            if ($passed) {
                printSuccess("Validation PASSED: " . $testCase['validateMsg']);
            } else {
                printError("Validation FAILED: " . $testCase['validateMsg']);
                $allTestsPassed = false;
            }
        }

        // Save assistant message to conversation
        $conversationManager->addMessage(
            $testConversationId,
            'assistant',
            $response['response'],
            $response['tokensUsed'],
            isset($response['searchCriteria']) ? $response['searchCriteria'] : null
        );

        // Accumulate conversation history
        $conversationHistory[] = array(
            'role' => 'user',
            'content' => $question
        );
        $conversationHistory[] = array(
            'role' => 'assistant',
            'content' => $response['response']
        );

        // Small delay between questions
        if ($questionNum < count($questions)) {
            sleep(2);
        }
    }

    // Step 7: Final results
    printHeader("TEST RESULTS");

    if ($allTestsPassed) {
        printSuccess("All tests PASSED!");
        printSuccess("✓ Greeting answered in English (no Thai, no security refusal)");
        printSuccess("✓ English price question answered in English with product links");
        printSuccess("✓ English quotation request generated a PDF");
        echo "\n";
        printInfo("Test conversation saved with ID: $testConversationId");
        printInfo("Check logs.log for detailed API interactions");
        exit(0);
    } else {
        printError("Some tests FAILED");
        printError("Please check the error messages above");
        printInfo("Test conversation ID: $testConversationId");
        printInfo("Check logs.log for debugging information");
        exit(1);
    }

} catch (Exception $e) {
    printError("Test crashed with exception: " . $e->getMessage());
    printError("Stack trace:");
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
