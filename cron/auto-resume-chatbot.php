<?php
/**
 * Auto-Resume Chatbot Cron Job
 *
 * Automatically resumes chatbots that have been paused for longer than the configured timeout
 * Should be run periodically via cron (e.g., every 5-15 minutes)
 *
 * Usage:
 * php cron/auto-resume-chatbot.php
 *
 * Crontab example (runs every 15 minutes):
 * *//* * * * * /usr/bin/php /path/to/sirichaielectric-chatbot/cron/auto-resume-chatbot.php >> /path/to/logs/auto-resume.log 2>&1
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ConversationManager.php';

echo "[" . date('Y-m-d H:i:s') . "] Auto-Resume Cron Job Started\n";

try {
    // Initialize configuration
    $config = Config::getInstance();
    $dbConfig = $config->get('database');
    $maxMessages = $config->get('conversation', 'maxMessages', 20);
    $timeout = $config->get('conversation', 'autoResumeTimeoutMinutes', 30);

    echo "[" . date('Y-m-d H:i:s') . "] Configuration loaded. Timeout: {$timeout} minutes\n";

    // Initialize ConversationManager
    $conversationManager = new ConversationManager($maxMessages, 'api', $dbConfig);

    // Auto-resume conversations
    $count = $conversationManager->autoResumeChatbot($timeout);

    if ($count > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] SUCCESS: Auto-resumed {$count} conversation(s)\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] INFO: No conversations to auto-resume\n";
    }

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Auto-Resume Cron Job Completed\n";
exit(0);
