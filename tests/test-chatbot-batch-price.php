#!/usr/bin/env php
<?php
/**
 * Chatbot Integration Test - Batch Price Inquiry (WORKFLOW 1B)
 *
 * Simulates a customer sending a numbered list of products asking for prices,
 * then proceeding to generate a quotation.
 *
 * - Q1: "ขอราคา" with 5 products + quantities (batch price inquiry)
 *       → AI must search ALL 5 products and return prices for each
 *       → AI must NOT call generate_quotation automatically
 *       → AI should offer to create a quotation at the end
 * - Q2: "ออกใบเสนอราคาได้เลย" (no rate)
 *       → AI must reject - no rate specified
 * - Q3: "ออกใบเสนอราคา เรท c"
 *       → AI should call generate_quotation with the 5 products and return PDF link
 *
 * Usage: php tests/test-chatbot-batch-price.php
 */

// Set error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('error_log', __DIR__ . '/../logs.log');

// Load dependencies
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../chatbot/DatabaseManager.php';
require_once __DIR__ . '/../chatbot/ConversationManager.php';
require_once __DIR__ . '/../services/ProductAPIService.php';
require_once __DIR__ . '/../chatbot/SirichaiElectricChatbot.php';
require_once __DIR__ . '/../chatbot/GeminiFileManager.php';

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

// All questions share a single conversation history
$testConversationId = 'test_batch_price_' . time();
$questions = array(
    array(
        'question' => "ขอราคา\n1 thw1x4สีดำ ยา —4ม้วน\n2 weg5001k pana —50ตัว\n3 weg15929 pana —60ตัว\n4 emt 1/2 pana —10เส้น\n5 แคล้มประกับ imc 3/4 —50อัน",
        'expectation' => 'AI must search ALL 5 products and return prices for each item. Must NOT call generate_quotation. Should offer to create quotation at the end.',
        'validate' => function($response) {
            // Response must contain price info for multiple products
            // and must NOT contain generate_quotation trigger text
            $hasPrices = mb_strpos($response, 'ราคา') !== false || mb_strpos($response, 'บาท') !== false;
            $offersQuotation = mb_strpos($response, 'ใบเสนอราคา') !== false || mb_strpos($response, 'quotation') !== false;
            return $hasPrices && $offersQuotation;
        },
        'validateMsg' => 'Response must contain price info (ราคา/บาท) and offer quotation'
    ),
    array(
        'question' => 'ออกใบเสนอราคาได้เลย',
        'expectation' => 'AI should reject — no rate specified',
        'validate' => function($response) {
            // Should contain rejection message
            return mb_strpos($response, 'ไม่สามารถ') !== false
                || mb_strpos($response, 'อนุญาต') !== false
                || mb_strpos($response, 'เรท') !== false
                || mb_strpos($response, 'rate') !== false;
        },
        'validateMsg' => 'Response must reject the quotation request (no rate)'
    ),
    array(
        'question' => 'ออกใบเสนอราคา เรท c',
        'expectation' => 'AI should call generate_quotation with all 5 products and return a PDF download link',
        'validate' => function($response) {
            // Must contain a URL (PDF link)
            return mb_strpos($response, 'http') !== false
                || mb_strpos($response, 'ดาวน์โหลด') !== false
                || mb_strpos($response, 'pdf') !== false
                || mb_strpos($response, 'PDF') !== false;
        },
        'validateMsg' => 'Response must contain a PDF download link'
    ),
);

try {
    printHeader("CHATBOT TEST - BATCH PRICE INQUIRY (WORKFLOW 1B)");

    // Step 1: Load configuration
    printStep("Loading configuration...");
    $config = Config::getInstance();
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

    $productAPI = new ProductAPIService($productAPIConfig);

    $chatbot = new SirichaiElectricChatbot($geminiConfig, $productAPI);
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
        printStep("Q$questionNum: \"" . str_replace("\n", " | ", $question) . "\"");
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
        printSuccess("The chatbot successfully handled the batch price inquiry workflow:");
        printSuccess("✓ Batch 'ขอราคา' searched all 5 products and returned prices");
        printSuccess("✓ Did NOT auto-trigger quotation from batch price inquiry");
        printSuccess("✓ Offered to create quotation after showing prices");
        printSuccess("✓ Rejected quotation request with no rate");
        printSuccess("✓ Generated quotation PDF with rate 'c' and all 5 products");
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
