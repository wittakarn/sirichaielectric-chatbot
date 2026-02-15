#!/Applications/MAMP/bin/php/php7.4.33/bin/php
<?php
/**
 * Chatbot Integration Test
 *
 * This test script:
 * 1. Clears all test data (messages table, logs, file cache)
 * 2. Tests the chatbot with a five-question conversation:
 *    - Q1: "มี รางวายเวย์ KWSS2038 KJL ไหม" (product search)
 *    - Q2: "หนาเท่าไหร่ ใช้ทำอะไร" (follow-up detail question)
 *    - Q3: "มอเตอร์ 2kw 380v กินกระแสเท่าไหร่" (general electrical engineering question)
 *    - Q4: "โคมไฟกันน้ำกันฝุ่น มียี่ห้ออะไรบ้าง" (multiple brands query)
 *    - Q5: "ขอราคา thw 1x2.5 yazaka หน่อย" (specific product price query)
 * 3. Verifies AI can answer all questions successfully
 *
 * Usage: php test-chatbot.php
 */

// Set error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('error_log', __DIR__ . '/logs.log');

// Load dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/DatabaseManager.php';
require_once __DIR__ . '/ConversationManager.php';
require_once __DIR__ . '/ProductAPIService.php';
require_once __DIR__ . '/SirichaiElectricChatbot.php';
require_once __DIR__ . '/GeminiFileManager.php';

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

// Test configuration
$testConversationId = 'test_' . time();
$questions = array(
    array(
        'question' => 'มี รางวายเวย์ KWSS2038 KJL ไหม',
        'expectation' => 'AI should search for products and return product details with price'
    ),
    array(
        'question' => 'หนาเท่าไหร่ ใช้ทำอะไร',
        'expectation' => 'AI should provide thickness and usage information'
    ),
    array(
        'question' => 'มอเตอร์ 2kw 380v กินกระแสเท่าไหร่',
        'expectation' => 'AI should calculate current using P=√3×V×I×cosφ formula and provide answer'
    ),
    array(
        'question' => 'โคมไฟกันน้ำกันฝุ่น มียี่ห้ออะไรบ้าง',
        'expectation' => 'AI should be able to provide multiple brands of waterproof dustproof lamps'
    ),
    array(
        'question' => 'ขอราคา thw 1x2.5 yazaka หน่อย',
        'expectation' => 'AI should be able to provide product price for specific THW cable'
    )
);

try {
    printHeader("CHATBOT INTEGRATION TEST");

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
    $logsFile = __DIR__ . '/logs.log';
    if (file_exists($logsFile)) {
        file_put_contents($logsFile, '');
        printSuccess("logs.log cleared");
    } else {
        printInfo("logs.log does not exist (will be created on first log)");
    }

    // Step 5: Remove file-cache.json
    printStep("Removing file-cache.json...");
    $cacheFile = __DIR__ . '/file-cache.json';
    if (file_exists($cacheFile)) {
        unlink($cacheFile);
        printSuccess("file-cache.json removed");
    } else {
        printInfo("file-cache.json does not exist");
    }

    // Step 6: Initialize services
    printStep("Initializing chatbot services...");
    $conversationManager = new ConversationManager(
        $config->get('conversation', 'maxMessages', 20),
        'test',
        $dbConfig
    );

    $productAPI = new ProductAPIService($productAPIConfig);

    $chatbot = new SirichaiElectricChatbot($geminiConfig, $productAPI);
    printSuccess("Chatbot initialized");

    // Step 7: Run conversation test
    printHeader("RUNNING CONVERSATION TEST");
    printInfo("Conversation ID: $testConversationId");

    $allTestsPassed = true;
    $conversationHistory = array();

    foreach ($questions as $index => $testCase) {
        $questionNum = $index + 1;
        $question = $testCase['question'];
        $expectation = $testCase['expectation'];

        echo "\n" . Color::BOLD . "─────────────────────────────────────" . Color::RESET . "\n";
        printStep("Question $questionNum: \"$question\"");
        printInfo("Expected: $expectation");

        // Add user message to conversation
        $conversationManager->addMessage(
            $testConversationId,
            'user',
            $question,
            0,
            null
        );

        // Get AI response
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

        // Update conversation history for next question
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

    // Step 8: Final results
    printHeader("TEST RESULTS");

    if ($allTestsPassed) {
        printSuccess("All tests PASSED!");
        printSuccess("The chatbot successfully answered all questions:");
        printSuccess("✓ Initial product search");
        printSuccess("✓ Follow-up detail question");
        printSuccess("✓ General electrical engineering question");
        printSuccess("✓ Multiple brands query");
        printSuccess("✓ Specific product price query");
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
