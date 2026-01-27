<?php
/**
 * Test script for File API integration
 * Run this to verify that files are being uploaded and used correctly
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/GeminiFileManager.php';
require_once __DIR__ . '/ProductAPIService.php';
require_once __DIR__ . '/SirichaiElectricChatbot.php';

// Load configuration from .env file
$configInstance = Config::getInstance();
$geminiConfig = $configInstance->get('gemini');

// Configuration for chatbot
$config = array(
    'apiKey' => $geminiConfig['apiKey'],
    'model' => 'gemini-2.0-flash-exp',
    'temperature' => 0.7,
    'maxTokens' => 8000,
);

echo "=== Testing File API Integration ===\n\n";

// Test 1: File Manager
echo "Test 1: Testing File Manager upload...\n";
$fileManager = new GeminiFileManager($config['apiKey']);

$testContent = "This is a test file for Gemini File API.";
$result = $fileManager->uploadTextFile($testContent, 'Test File');

$testFileName = null;
if ($result['success']) {
    echo "✓ File uploaded successfully!\n";
    echo "  URI: " . $result['fileUri'] . "\n";
    echo "  Name: " . $result['name'] . "\n\n";
    $testFileName = $result['name'];
} else {
    echo "✗ Upload failed: " . $result['error'] . "\n\n";
    exit(1);
}

// Test 2: Initialize Chatbot (this should upload system prompt and catalog)
echo "Test 2: Initializing chatbot (uploads system prompt + catalog)...\n";

// Initialize ProductAPI using config from .env
$productAPIConfig = $configInstance->get('productAPI');
$productAPI = new ProductAPIService($productAPIConfig);

// Initialize Chatbot (this triggers file uploads)
$chatbot = new SirichaiElectricChatbot($config, $productAPI);

echo "✓ Chatbot initialized\n\n";

// Test 3: Check cached files
echo "Test 3: Checking cached files...\n";
$cachedFiles = $fileManager->getCachedFiles();

if (empty($cachedFiles)) {
    echo "✗ No files in cache\n\n";
} else {
    echo "✓ Found " . count($cachedFiles) . " cached files:\n";
    foreach ($cachedFiles as $key => $file) {
        $age = time() - $file['uploadedAt'];
        echo "  - {$file['displayName']} (age: " . round($age / 60, 1) . " minutes)\n";
        echo "    URI: {$file['fileUri']}\n";
    }
    echo "\n";
}

// Test 4: Send a test message
echo "Test 4: Sending test message to chatbot...\n";
$testMessage = "มีสายไฟ NYY ไหมครับ";
echo "Message: {$testMessage}\n";

$response = $chatbot->chat($testMessage);

if ($response['success']) {
    echo "✓ Chat response received!\n";
    echo "Language detected: " . $response['language'] . "\n";
    echo "Response: " . substr($response['response'], 0, 200) . "...\n\n";
} else {
    echo "✗ Chat failed: " . $response['error'] . "\n\n";
}

// Test 5: Test file refresh mechanism
echo "Test 5: Testing file refresh...\n";
$chatbot->refreshFiles();
echo "✓ Files refreshed\n\n";

// Cleanup: Delete test file
echo "Cleanup: Deleting test file...\n";
if ($testFileName) {
    $deleteResult = $fileManager->deleteFile($testFileName);
    if ($deleteResult['success']) {
        echo "✓ Test file deleted: {$testFileName}\n";
    } else {
        echo "✗ Failed to delete test file: " . $deleteResult['error'] . "\n";
    }
} else {
    echo "⊘ No test file to delete\n";
}

echo "\n=== All Tests Completed ===\n";
echo "Note: System prompt and catalog files remain cached for chatbot use.\n";
echo "They will auto-expire after 48 hours.\n";
