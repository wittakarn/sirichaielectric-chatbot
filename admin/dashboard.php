<?php
/**
 * Admin Dashboard
 *
 * Allows human agents to:
 * - View all paused conversations
 * - Pause/resume chatbot for specific conversations
 * - View LINE user display names
 * - Monitor chatbot status
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../chatbot/ConversationManager.php';
require_once __DIR__ . '/../services/LineProfileService.php';

// Require authentication
requireAdminAuth();

// Initialize configuration
$config = Config::getInstance();
$dbConfig = $config->get('database');
$maxMessages = $config->get('conversation', 'maxMessages', 20);
$lineAccessToken = $config->get('line', 'channelAccessToken');

// Initialize services
$conversationManager = new ConversationManager($maxMessages, 'api', $dbConfig);
$lineProfileService = new LineProfileService($lineAccessToken);

// Get current admin username
$adminUsername = getAdminUsername();

// Handle actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $conversationId = isset($_POST['conversationId']) ? $_POST['conversationId'] : '';

    try {
        switch ($action) {
            case 'pause':
                if ($conversationManager->pauseChatbot($conversationId)) {
                    error_log("[Admin Dashboard] Agent {$adminUsername} paused chatbot for: {$conversationId}");
                    $message = "Chatbot paused successfully for conversation: {$conversationId}";
                    $messageType = 'success';
                } else {
                    $message = "Failed to pause chatbot. The conversation might already be paused.";
                    $messageType = 'error';
                }
                break;

            case 'resume':
                if ($conversationManager->resumeChatbot($conversationId)) {
                    error_log("[Admin Dashboard] Agent {$adminUsername} resumed chatbot for: {$conversationId}");
                    $message = "Chatbot resumed successfully for conversation: {$conversationId}";
                    $messageType = 'success';
                } else {
                    $message = "Failed to resume chatbot. The conversation might already be active.";
                    $messageType = 'error';
                }
                break;

            case 'auto-resume':
                $timeout = $config->get('conversation', 'autoResumeTimeoutMinutes', 30);
                $count = $conversationManager->autoResumeChatbot($timeout);
                $message = "Auto-resumed {$count} conversation(s) that were paused for more than {$timeout} minutes";
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Get paused conversations
$pausedConversations = $conversationManager->getPausedConversations(100);

// Get active conversations from last 2 days
$activeConversations = $conversationManager->getActiveConversations(2, 100);

// Fetch LINE display names for paused conversations
foreach ($pausedConversations as &$conv) {
    if ($conv['platform'] === 'line' && $conv['user_id']) {
        $userId = LineProfileService::extractUserIdFromConversationId($conv['conversation_id']);
        if ($userId) {
            $displayName = $lineProfileService->getDisplayName($userId);
            $conv['display_name'] = $displayName ?: 'Unknown';
        } else {
            $conv['display_name'] = 'N/A';
        }
    } else {
        $conv['display_name'] = 'N/A';
    }
}
unset($conv); // Break reference

// Fetch LINE display names for active conversations
foreach ($activeConversations as &$conv) {
    if ($conv['platform'] === 'line' && $conv['user_id']) {
        $userId = LineProfileService::extractUserIdFromConversationId($conv['conversation_id']);
        if ($userId) {
            $displayName = $lineProfileService->getDisplayName($userId);
            $conv['display_name'] = $displayName ?: 'Unknown';
        } else {
            $conv['display_name'] = 'N/A';
        }
    } else {
        $conv['display_name'] = 'N/A';
    }
}
unset($conv); // Break reference
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Sirichai Electric Chatbot</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f7fa;
            color: #333;
        }

        .header {
            background: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 20px;
            color: #333;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .user-info {
            color: #666;
            font-size: 14px;
        }

        .btn-logout {
            padding: 8px 16px;
            background: #f44;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            transition: background 0.2s;
        }

        .btn-logout:hover {
            background: #d33;
        }

        .container {
            max-width: 1400px;
            margin: 24px auto;
            padding: 0 24px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }

        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            padding: 24px;
            margin-bottom: 24px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .card-header h2 {
            font-size: 18px;
            color: #333;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5568d3;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f8f9fa;
        }

        th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            color: #666;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
            font-size: 14px;
        }

        tbody tr:hover {
            background: #f8f9fa;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-line {
            background: #06c755;
            color: white;
        }

        .badge-api {
            background: #667eea;
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }

        .stat-label {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #333;
        }

        .user-cell {
            max-width: 200px;
        }

        .user-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 2px;
        }

        .user-id {
            font-size: 12px;
            color: #999;
            font-family: monospace;
        }

        .badge-active {
            background: #28a745;
            color: white;
        }

        .badge-paused {
            background: #ffc107;
            color: #333;
        }

        .split-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        @media (max-width: 1200px) {
            .split-layout {
                grid-template-columns: 1fr;
            }
        }

        .conv-id-cell {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .copy-btn {
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 4px;
            color: #666;
            transition: color 0.2s;
            font-size: 14px;
        }

        .copy-btn:hover {
            color: #667eea;
        }

        .copy-btn:active {
            color: #28a745;
        }

        .copy-tooltip {
            position: relative;
        }

        .copy-tooltip .tooltip-text {
            visibility: hidden;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 4px;
            padding: 4px 8px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            font-size: 11px;
            white-space: nowrap;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .copy-tooltip .tooltip-text.show {
            visibility: visible;
            opacity: 1;
        }

        @media (max-width: 768px) {
            .stats {
                grid-template-columns: 1fr;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .table-container {
                overflow-x: scroll;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🤖 Chatbot Admin Dashboard</h1>
        <div class="header-right">
            <span class="user-info">Logged in as: <strong><?php echo htmlspecialchars($adminUsername); ?></strong></span>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </div>

    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat-card">
                <div class="stat-label">Paused Conversations</div>
                <div class="stat-value"><?php echo count($pausedConversations); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active Conversations (Last 2 Days)</div>
                <div class="stat-value"><?php echo count($activeConversations); ?></div>
            </div>
        </div>

        <div class="split-layout">
            <!-- Left Column: Paused Conversations -->
            <div class="card">
                <div class="card-header">
                    <h2>Paused Conversations</h2>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="auto-resume">
                        <button type="submit" class="btn btn-primary btn-sm">Auto-Resume</button>
                    </form>
                </div>

                <?php if (empty($pausedConversations)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">✓</div>
                        <p>No paused conversations. All chatbots are active!</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Conversation ID</th>
                                    <th>Platform</th>
                                    <th>User</th>
                                    <th>Duration</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pausedConversations as $conv): ?>
                                    <?php
                                        $pausedTime = intval($conv['paused_at']);
                                        $duration = time() - $pausedTime;
                                        $durationText = '';
                                        if ($duration < 60) {
                                            $durationText = $duration . 's';
                                        } elseif ($duration < 3600) {
                                            $durationText = floor($duration / 60) . 'm';
                                        } else {
                                            $durationText = floor($duration / 3600) . 'h ' . floor(($duration % 3600) / 60) . 'm';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="conv-id-cell">
                                                <span style="font-family: monospace; font-size: 11px;" title="<?php echo htmlspecialchars($conv['conversation_id']); ?>">
                                                    <?php echo htmlspecialchars(substr($conv['conversation_id'], 0, 20)) . '...'; ?>
                                                </span>
                                                <button class="copy-btn copy-tooltip" onclick="copyToClipboard('<?php echo htmlspecialchars($conv['conversation_id']); ?>', this)" title="Copy ID">
                                                    📋
                                                    <span class="tooltip-text">Copied!</span>
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $conv['platform']; ?>">
                                                <?php echo strtoupper($conv['platform']); ?>
                                            </span>
                                        </td>
                                        <td class="user-cell">
                                            <?php if ($conv['display_name'] !== 'N/A'): ?>
                                                <div class="user-name"><?php echo htmlspecialchars($conv['display_name']); ?></div>
                                            <?php else: ?>
                                                <div class="user-id" style="font-size: 11px;"><?php echo htmlspecialchars(substr($conv['user_id'] ?: 'N/A', 0, 15)); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $durationText; ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="resume">
                                                <input type="hidden" name="conversationId" value="<?php echo htmlspecialchars($conv['conversation_id']); ?>">
                                                <button type="submit" class="btn btn-success btn-sm">Resume</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Column: Active Conversations -->
            <div class="card">
                <div class="card-header">
                    <h2>Active Conversations (2d)</h2>
                </div>

                <?php if (empty($activeConversations)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">💤</div>
                        <p>No active conversations in the last 2 days.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Conversation ID</th>
                                    <th>Platform</th>
                                    <th>User</th>
                                    <th>Last Activity</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activeConversations as $conv): ?>
                                    <?php
                                        $lastActivity = intval($conv['last_activity']);
                                        $timeAgo = time() - $lastActivity;
                                        $timeAgoText = '';
                                        if ($timeAgo < 60) {
                                            $timeAgoText = $timeAgo . 's ago';
                                        } elseif ($timeAgo < 3600) {
                                            $timeAgoText = floor($timeAgo / 60) . 'm ago';
                                        } elseif ($timeAgo < 86400) {
                                            $timeAgoText = floor($timeAgo / 3600) . 'h ago';
                                        } else {
                                            $timeAgoText = floor($timeAgo / 86400) . 'd ago';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="conv-id-cell">
                                                <span style="font-family: monospace; font-size: 11px;" title="<?php echo htmlspecialchars($conv['conversation_id']); ?>">
                                                    <?php echo htmlspecialchars(substr($conv['conversation_id'], 0, 20)) . '...'; ?>
                                                </span>
                                                <button class="copy-btn copy-tooltip" onclick="copyToClipboard('<?php echo htmlspecialchars($conv['conversation_id']); ?>', this)" title="Copy ID">
                                                    📋
                                                    <span class="tooltip-text">Copied!</span>
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $conv['platform']; ?>">
                                                <?php echo strtoupper($conv['platform']); ?>
                                            </span>
                                        </td>
                                        <td class="user-cell">
                                            <?php if ($conv['display_name'] !== 'N/A'): ?>
                                                <div class="user-name"><?php echo htmlspecialchars($conv['display_name']); ?></div>
                                            <?php else: ?>
                                                <div class="user-id" style="font-size: 11px;"><?php echo htmlspecialchars(substr($conv['user_id'] ?: 'N/A', 0, 15)); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 12px;"><?php echo $timeAgoText; ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="pause">
                                                <input type="hidden" name="conversationId" value="<?php echo htmlspecialchars($conv['conversation_id']); ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Pause</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Manual Controls</h2>
            </div>
            <p style="margin-bottom: 16px; color: #666; font-size: 14px;">
                Manually pause or resume chatbot for a specific conversation. Use this when you need to take over a conversation.
            </p>
            <form method="POST">
                <div style="display: flex; gap: 12px; align-items: flex-end;">
                    <div style="flex: 1;">
                        <label for="conversationId" style="display: block; margin-bottom: 8px; font-size: 14px; font-weight: 500;">Conversation ID</label>
                        <input
                            type="text"
                            id="conversationId"
                            name="conversationId"
                            placeholder="e.g., line_U1234567890abcdef"
                            style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-family: monospace;"
                            required
                        >
                    </div>
                    <button type="submit" name="action" value="pause" class="btn btn-danger">Pause Bot</button>
                    <button type="submit" name="action" value="resume" class="btn btn-success">Resume Bot</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Copy to clipboard function
        function copyToClipboard(text, button) {
            // Use modern clipboard API if available
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    showCopyTooltip(button);
                }).catch(function(err) {
                    // Fallback for older browsers
                    fallbackCopyToClipboard(text, button);
                });
            } else {
                // Fallback for older browsers
                fallbackCopyToClipboard(text, button);
            }
        }

        // Fallback copy method for older browsers
        function fallbackCopyToClipboard(text, button) {
            var textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed";
            textArea.style.top = "-9999px";
            textArea.style.left = "-9999px";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                var successful = document.execCommand('copy');
                if (successful) {
                    showCopyTooltip(button);
                }
            } catch (err) {
                console.error('Failed to copy:', err);
            }

            document.body.removeChild(textArea);
        }

        // Show tooltip feedback
        function showCopyTooltip(button) {
            var tooltip = button.querySelector('.tooltip-text');
            if (tooltip) {
                tooltip.classList.add('show');
                setTimeout(function() {
                    tooltip.classList.remove('show');
                }, 1500);
            }
        }

        // Auto-refresh every 30 seconds to show latest paused conversations
        setTimeout(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>
