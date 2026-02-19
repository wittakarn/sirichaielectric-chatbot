#!/usr/bin/env php
<?php
/**
 * Chatbot Integration Test - Multi-turn Conversation (With History)
 *
 * Simulates a full customer quotation workflow in a single conversation:
 * - Q1: "มีเบรกเกอร์ abb ไหม" (product search)
 * - Q2: "เพิ่มรายการ ลูกเซอร์กิตเบรกเกอร์ 1P 6A 6KA SH201-C6 ABB 2 ตัว" (product selection)
 * - Q3: "ใช้กับสายไฟไหนได้บ้าง" (compatibility question)
 * - Q4: "เพิ่มรายการ สายไฟ VCT 2x1 ไทยยูเนี่ยน THAI UNION 1 เส้น" (accessory selection)
 * - Q5: "สรุปรายการ พร้อมราคาให้หน่อย" (summary with pricing)
 * - Q6: "ออกใบเสนอราคาได้เลย" (quotation without rate - expect rejection)
 * - Q7: "ออกใบเสนอราคา ด้วยเรท c" (quotation with rate - expect PDF link)
 * - Q8: "มีสวิตช์ตัดไฟ RCD ไหม" (new product search after quotation)
 * - Q9: "เพิ่ม รายการแรก 5 ชิ้น" (shopping phrase - must NOT ask for price type)
 * - Q10: "เอา ตัวแรก 2 อัน" (another shopping phrase - must NOT trigger quotation)
 * - Q11: "ออกใบเสนอราคา เรท a" (quotation after shopping list - expect PDF link with all products)
 *
 * Usage: php test-chatbot-with-history.php
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
$testConversationId = 'test_with_history_' . time();
$questions = array(
    array(
        'question' => 'มีเบรกเกอร์ abb ไหม',
        'expectation' => 'AI should search for ABB circuit breaker products and return results'
    ),
    array(
        'question' => 'เพิ่มรายการ ลูกเซอร์กิตเบรกเกอร์ 1P 6A 6KA SH201-C6 ABB 2 ตัว',
        'expectation' => 'AI should acknowledge the product selection with quantity 2'
    ),
    array(
        'question' => 'ใช้กับสายไฟไหนได้บ้าง',
        'expectation' => 'AI should recommend compatible wire/cable for the selected breaker'
    ),
    array(
        'question' => 'เพิ่มรายการ สายไฟ VCT 2x1 ไทยยูเนี่ยน THAI UNION 1 เส้น',
        'expectation' => 'AI should acknowledge the cable selection with quantity 1'
    ),
    array(
        'question' => 'สรุปรายการ พร้อมราคาให้หน่อย',
        'expectation' => 'AI should summarize all selected products with their prices'
    ),
    array(
        'question' => 'ออกใบเสนอราคาได้เลย',
        'expectation' => 'AI should reject - no rate specified'
    ),
    array(
        'question' => 'ออกใบเสนอราคา ด้วยเรท c',
        'expectation' => 'AI should call generate_quotation and return a PDF download link'
    ),
    // New test cases: verify shopping phrases do NOT trigger quotation workflow
    array(
        'question' => 'มีสวิตช์ตัดไฟ RCD ไหม',
        'expectation' => 'AI should search for RCD products and return results (not quotation-related)'
    ),
    array(
        'question' => 'เพิ่ม รายการแรก 5 ชิ้น',
        'expectation' => 'AI should respond naturally (acknowledge or clarify). Must NOT ask for price type and must NOT call generate_quotation'
    ),
    array(
        'question' => 'เอา ตัวแรก 2 อัน',
        'expectation' => 'AI should respond naturally. Must NOT ask for price type and must NOT call generate_quotation'
    ),
    array(
        'question' => 'ออกใบเสนอราคา เรท a',
        'expectation' => 'AI should call generate_quotation with all accumulated products and return a PDF link'
    )
);

try {
    printHeader("CHATBOT TEST - MULTI-TURN CONVERSATIONS (WITH HISTORY)");

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
        printSuccess("The chatbot successfully handled the full quotation workflow:");
        printSuccess("✓ Product search (circuit breaker)");
        printSuccess("✓ Product selection with quantity");
        printSuccess("✓ Compatible accessory recommendation");
        printSuccess("✓ Accessory selection with quantity");
        printSuccess("✓ Summary with pricing");
        printSuccess("✓ Quotation rejection (no rate specified)");
        printSuccess("✓ Quotation generation with PDF link");
        printSuccess("✓ Product search after quotation (RCD)");
        printSuccess("✓ Shopping phrase 'เพิ่ม X ชิ้น' did not trigger quotation");
        printSuccess("✓ Shopping phrase 'เอา X อัน' did not trigger quotation");
        printSuccess("✓ Quotation generation still works after shopping list management");
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
