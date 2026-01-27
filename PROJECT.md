# Sirichai Electric Chatbot - Complete Project Documentation

**Last Updated:** January 16, 2026
**Database:** chatbotdb (MySQL)
**PHP Version:** 5.6+
**Architecture:** Repository Pattern + File API + LINE Integration

---

## Table of Contents

1. [Project Overview](#project-overview)
2. [Architecture](#architecture)
3. [Key Features](#key-features)
4. [Database Setup](#database-setup)
5. [File API Integration](#file-api-integration)
6. [Repository Pattern](#repository-pattern)
7. [LINE Integration](#line-integration)
8. [Configuration](#configuration)
9. [Testing & Monitoring](#testing--monitoring)
10. [Maintenance & Cleanup](#maintenance--cleanup)
11. [Troubleshooting](#troubleshooting)
12. [Development History](#development-history)

---

## Project Overview

Sirichai Electric Chatbot is a conversational AI system powered by Google Gemini API, designed to assist customers with product inquiries about electrical products. The system supports both API and LINE messaging platforms with persistent conversation history.

### Tech Stack
- **Backend:** PHP 5.6+
- **Database:** MySQL 5.7+ (chatbotdb)
- **AI:** Google Gemini 2.5 Flash
- **APIs:** LINE Messaging API, Product Catalog API
- **Architecture:** Repository Pattern, Singleton Pattern, File Caching

### Key Capabilities
- Natural language product search
- Multi-turn conversations with context
- Token usage tracking and optimization
- LINE Official Account integration
- Persistent conversation history
- 95%+ token reduction via File API

---

## Architecture

### System Layers

```
┌─────────────────────────────────────────────────────────────────┐
│                        Entry Points                              │
│           index.php (API)      line-webhook.php (LINE)          │
└──────────────────────────┬──────────────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────────────┐
│                   SirichaiElectricChatbot                             │
│         (AI Logic + File API + Product Search)                   │
└──────────────────────────┬──────────────────────────────────────┘
                           │
           ┌───────────────┼───────────────┐
           ▼               ▼               ▼
┌────────────────┐  ┌──────────────┐  ┌─────────────────┐
│ GeminiFileAPI  │  │ProductAPI    │  │ConversationMgr  │
│ (Token Saving) │  │ (Catalog)    │  │ (Service Layer) │
└────────────────┘  └──────────────┘  └────────┬────────┘
                                               │
                           ┌───────────────────┼──────────────┐
                           ▼                   ▼              ▼
                  ┌──────────────────┐  ┌──────────────────┐
                  │ConversationRepo  │  │ MessageRepo      │
                  │ (conversations)  │  │ (messages)       │
                  └────────┬─────────┘  └────────┬─────────┘
                           │                     │
                           └──────────┬──────────┘
                                      ▼
                          ┌────────────────────────┐
                          │   BaseRepository       │
                          │   (PDO Operations)     │
                          └───────────┬────────────┘
                                      ▼
                          ┌────────────────────────┐
                          │   DatabaseManager      │
                          │   (Singleton + PDO)    │
                          └────────────────────────┘
```

### File Structure

```
sirichaielectric-chatbot/
├── Core Application
│   ├── index.php                    # REST API entry point
│   ├── line-webhook.php             # LINE webhook handler
│   ├── SirichaiElectricChatbot.php       # Main chatbot logic
│   ├── ConversationManager.php      # Conversation service layer
│   └── DatabaseManager.php          # Database singleton
│
├── Repository Layer
│   ├── repository/
│   │   ├── BaseRepository.php          # Abstract base class
│   │   ├── ConversationRepository.php  # Conversation queries
│   │   └── MessageRepository.php       # Message queries
│
├── API Integration
│   ├── GeminiFileManager.php        # File API (token optimization)
│   ├── ProductAPIService.php        # Product catalog API
│   └── cleanup-files.php            # File management utility
│
├── Configuration
│   ├── config.php                   # Config loader (singleton)
│   ├── .env                         # Environment variables
│   └── system-prompt.txt            # AI system instructions
│
├── Database
│   └── schema.sql                   # Database schema
│
├── Cache
│   ├── cache/                       # Catalog cache directory
│   └── file-cache.json             # File API cache (excluded from git)
│
├── Testing & Utilities
│   ├── test-file-api.php           # File API test script
│   └── view-cache-stats.php        # Cache statistics viewer
│
└── Documentation
    ├── PROJECT.md                   # This file (comprehensive guide)
    ├── REPOSITORY_REFACTOR_SUMMARY.md
    ├── FILE-API-INTEGRATION.md
    ├── MIGRATION_GUIDE.md
    ├── IMPLEMENTATION-SUMMARY.md
    └── CLEANUP-GUIDE.md
```

---

## Key Features

### 1. Token Optimization via File API

**Problem:** Sending full system prompt + catalog with every request consumed ~3,000 tokens

**Solution:** Upload files once, reference by URI

#### Before & After Comparison

| Metric | Before (Inline) | After (File API) | Savings |
|--------|----------------|------------------|---------|
| System Instruction | 3,000 tokens | 10-50 tokens | 95%+ |
| First Message | 3,000+ tokens | ~50 tokens | 98% |
| Subsequent Messages | 3,000+ tokens | ~10 tokens | 99%+ |
| Cost per 1000 requests | Higher | 95% lower | Massive |

#### How It Works

1. **Initialization**: Upload `system-prompt.txt` and product catalog as files
2. **Caching**: Store file URIs in `file-cache.json` for 46 hours
3. **First Message**: Include file URIs with user message
4. **Context Maintained**: Gemini remembers files for entire conversation
5. **Auto-Refresh**: Files re-uploaded before 48-hour expiry

**Important:** File API is completely FREE - no storage or retrieval charges!

### 2. Persistent Conversations (Database-Backed)

**Benefits:**
- Conversations survive server restarts
- Cross-session continuity for LINE users
- 50-message context window (up from 10)
- Token usage tracking per message
- Platform separation (LINE vs API analytics)

**Database Tables:**

#### `conversations` Table
- conversation_id (VARCHAR 100, UNIQUE)
- platform ('api' or 'line')
- user_id (LINE user ID if applicable)
- max_messages_limit (default 50)
- created_at, last_activity timestamps

#### `messages` Table
- conversation_id (foreign key)
- role ('user' or 'assistant')
- content (TEXT)
- tokens_used (INT)
- sequence_number (message order)
- timestamp

### 3. Repository Pattern

**Security & Maintainability:**
- All queries use PDO prepared statements (SQL injection prevention)
- Clean separation of data access logic
- Reusable query methods
- Transaction support for atomic operations

**Repository Classes:**
- `BaseRepository`: Common operations (query, fetchOne, fetchAll, transactions)
- `ConversationRepository`: Conversation CRUD, cleanup, analytics
- `MessageRepository`: Message CRUD, token tracking, history retrieval

### 4. LINE Messaging Integration

**Features:**
- Async processing (responds within 2 seconds)
- Loading animation during AI processing
- Push API for reliable message delivery
- Automatic message splitting (5000 char limit)
- Error handling with Thai+English messages

**LINE-Specific Handling:**
- User ID → conversation ID mapping (`line_{userId}`)
- Separate platform tracking for analytics
- Connection closing via `litespeed_finish_request()` or `fastcgi_finish_request()`

---

## Database Setup

### Initial Setup

```bash
# 1. Create database
mysql -u root -p -e "CREATE DATABASE chatbotdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Create dedicated user (recommended for production)
mysql -u root -p -e "CREATE USER 'chatbot_user'@'localhost' IDENTIFIED BY 'your_secure_password';"
mysql -u root -p -e "GRANT ALL PRIVILEGES ON chatbotdb.* TO 'chatbot_user'@'localhost';"
mysql -u root -p -e "FLUSH PRIVILEGES;"

# 3. Run schema
mysql -u root -p chatbotdb < schema.sql

# 4. Verify tables
mysql -u root -p chatbotdb -e "SHOW TABLES;"
mysql -u root -p chatbotdb -e "DESCRIBE conversations;"
mysql -u root -p chatbotdb -e "DESCRIBE messages;"
```

### Migrating from Old Database

If you have existing `sirichaielectric_chatbot` database:

```bash
# Option 1: Rename database (if supported)
mysql -u root -p -e "RENAME DATABASE sirichaielectric_chatbot TO chatbotdb;"

# Option 2: Dump and restore
mysqldump -u root -p sirichaielectric_chatbot > backup.sql
mysql -u root -p -e "CREATE DATABASE chatbotdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p chatbotdb < backup.sql

# Verify data
mysql -u root -p chatbotdb -e "SELECT COUNT(*) FROM conversations;"
mysql -u root -p chatbotdb -e "SELECT COUNT(*) FROM messages;"
```

### Database Schema Details

```sql
-- Conversations table
CREATE TABLE conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id VARCHAR(100) NOT NULL UNIQUE,
    platform VARCHAR(20) NOT NULL DEFAULT 'api',
    user_id VARCHAR(100) NULL,
    max_messages_limit INT NOT NULL DEFAULT 50,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_conversation_id (conversation_id),
    INDEX idx_platform (platform),
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages table
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id VARCHAR(100) NOT NULL,
    role ENUM('user', 'assistant') NOT NULL,
    content TEXT NOT NULL,
    timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tokens_used INT NOT NULL DEFAULT 0,
    sequence_number INT NOT NULL,
    CONSTRAINT fk_conversation FOREIGN KEY (conversation_id)
        REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    INDEX idx_conversation_id (conversation_id),
    INDEX idx_timestamp (timestamp),
    INDEX idx_sequence (conversation_id, sequence_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## File API Integration

### Overview

System prompt and product catalog are uploaded as files to Gemini File API, reducing token usage by 95%+.

### File Manager (`GeminiFileManager.php`)

**Key Methods:**
- `uploadFile($filePath, $displayName, $mimeType)` - Upload file, return URI
- `getCachedFileUri($fileType)` - Get cached URI or upload if expired
- `refreshFiles()` - Force refresh all cached files
- `listAllFiles()` - List all uploaded files
- `deleteFile($fileName)` - Delete specific file
- `deleteAllFiles()` - Delete all files (with confirmation)

### Cache Structure (`file-cache.json`)

```json
{
  "systemPrompt": {
    "uri": "https://generativelanguage.googleapis.com/v1beta/files/abc123",
    "name": "files/abc123",
    "uploadedAt": 1705449600,
    "expiresAt": 1705622400
  },
  "catalog": {
    "uri": "https://generativelanguage.googleapis.com/v1beta/files/xyz789",
    "name": "files/xyz789",
    "uploadedAt": 1705449600,
    "expiresAt": 1705622400
  }
}
```

### File Lifecycle

```
Upload → Cached (46 hours) → Auto-refresh → Cached (46 hours) → ...
         ↓
     Expires (48 hours) → Auto-deleted by Gemini
```

### Testing File API

```bash
# Set your API key
export GEMINI_API_KEY='your-api-key-here'

# Run test script
php test-file-api.php
```

Expected output:
```
=== Testing File API Integration ===

Test 1: Testing File Manager upload...
✓ File uploaded successfully!

Test 2: Initializing chatbot (uploads system prompt + catalog)...
✓ Chatbot initialized

Test 3: Checking cached files...
✓ Found 2 cached files:
  - System Prompt (age: 0.0 minutes)
  - Product Catalog (age: 0.0 minutes)

Test 4: Sending test message to chatbot...
✓ Chat response received!

Test 5: Testing file refresh...
✓ Files refreshed

Cleanup: Deleting test file...
✓ Test file deleted

=== All Tests Completed ===
```

### Managing Uploaded Files

#### CLI Commands

```bash
# List all uploaded files
php cleanup-files.php list

# Delete all files (requires confirmation)
php cleanup-files.php delete-all

# Delete specific file
php cleanup-files.php delete files/abc123xyz

# Clear local cache only
php cleanup-files.php clear-cache

# Show help
php cleanup-files.php help
```

#### Programmatic Access

```php
require_once __DIR__ . '/GeminiFileManager.php';

$apiKey = getenv('GEMINI_API_KEY');
$fileManager = new GeminiFileManager($apiKey);

// List files
$result = $fileManager->listAllFiles();
if ($result['success']) {
    foreach ($result['files'] as $file) {
        echo "File: {$file['displayName']}\n";
        echo "Name: {$file['name']}\n";
        echo "Size: {$file['sizeBytes']} bytes\n";
    }
}

// Delete all files
$result = $fileManager->deleteAllFiles();
```

### Important Notes

**Free Service:** Gemini File API charges are:
- ✅ Storage: FREE
- ✅ Retrieval: FREE
- ✅ Quota: 20GB per project (plenty)
- ✅ Auto-deletion: After 48 hours (no cleanup needed)

**Best Practices:**
- Files auto-expire, cleanup is optional
- Use cleanup for testing/development cleanup
- Cache refresh happens automatically at 46 hours
- Manual refresh with `$chatbot->refreshFiles()` if content changes

---

## Repository Pattern

### Overview

All database operations abstracted into repository classes using PDO prepared statements for security.

### BaseRepository (`repository/BaseRepository.php`)

Abstract base class providing common operations:

```php
abstract class BaseRepository {
    protected $db; // PDO instance

    // Query execution
    protected function query($sql, $params = array());
    protected function fetchOne($sql, $params = array());
    protected function fetchAll($sql, $params = array());
    protected function fetchColumn($sql, $params = array());
    protected function execute($sql, $params = array());

    // Transactions
    public function beginTransaction();
    public function commit();
    public function rollback();

    // Utility
    protected function lastInsertId();
}
```

### ConversationRepository

**Key Methods:**
- `findById($conversationId)` - Get conversation by ID
- `upsert($conversationId, $platform, $userId, $maxMessagesLimit)` - Insert or update
- `delete($conversationId)` - Delete single conversation
- `deleteAll()` - Delete all conversations
- `deleteOlderThan($maxAgeHours)` - Cleanup old conversations
- `findByPlatform($platform, $limit)` - List by platform
- `findByUserId($userId)` - List by user
- `exists($conversationId)` - Check existence
- `countAll()`, `countByPlatform($platform)` - Analytics

### MessageRepository

**Key Methods:**
- `getHistory($conversationId)` - Get messages for AI context
- `create($conversationId, $role, $content, $tokensUsed, $sequenceNumber)` - Add message
- `getNextSequenceNumber($conversationId)` - Get next sequence
- `countByConversationId($conversationId)` - Count messages
- `deleteOldest($conversationId, $keepCount)` - Trim old messages
- `getTotalTokens($conversationId)` - Sum tokens used
- `getLastMessage($conversationId)` - Get most recent
- `findByRole($conversationId, $role)` - Filter by role

### Security Features

**SQL Injection Prevention:**
```php
// ❌ NEVER DO THIS (vulnerable)
$sql = "SELECT * FROM messages WHERE conversation_id = '$id'";

// ✅ ALWAYS USE PREPARED STATEMENTS
$sql = "SELECT * FROM messages WHERE conversation_id = ?";
$result = $this->fetchAll($sql, array($id));
```

**Transaction Support:**
```php
try {
    $this->conversationRepository->beginTransaction();

    // Multiple operations
    $this->conversationRepository->upsert(...);
    $this->messageRepository->create(...);
    $this->messageRepository->deleteOldest(...);

    $this->conversationRepository->commit();
} catch (PDOException $e) {
    $this->conversationRepository->rollback();
    throw new Exception('Transaction failed');
}
```

---

## LINE Integration

### Webhook Configuration

**LINE Developers Console:**
1. Create LINE Official Account
2. Get Channel Secret and Access Token
3. Set Webhook URL: `https://yourdomain.com/line-webhook.php`
4. Enable webhooks, disable auto-reply

### Environment Variables

```bash
LINE_CHANNEL_SECRET=your_channel_secret
LINE_CHANNEL_ACCESS_TOKEN=your_access_token
VERIFY_LINE_SIGNATURE=true  # Set to false for testing only
```

### Webhook Flow

```
LINE User Sends Message
    ↓
LINE Platform → Webhook (line-webhook.php)
    ↓
Signature Verification (if enabled)
    ↓
Respond HTTP 200 within 2 seconds
    ↓
Close Connection (litespeed_finish_request)
    ↓
Process Asynchronously:
  1. Show loading animation (60 seconds)
  2. Get conversation history from DB
  3. Call Gemini API
  4. Save response to DB
  5. Send via Push API
```

### Key Implementation Details

**Async Processing:**
```php
// Respond to LINE immediately
http_response_code(200);

// Close connection
if (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
} elseif (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Now process (LINE has received 200 OK)
processMessageAsync();
```

**Push API (Not Reply API):**
- Reply tokens expire after ~60 seconds
- Gemini processing can take 10+ seconds
- Push API is more reliable for async processing
- Push API allows loading animation + actual response

**Message Splitting:**
```php
function splitMessage($text, $maxLength = 4900) {
    // Split by paragraphs
    // If paragraph too long, split by sentences
    // Respect 5000 char LINE limit
    return $messages;
}
```

**Error Handling:**
```php
$errorMsg = "ขออภัยครับ ขณะนี้ระบบมีปัญหา กรุณาลองใหม่อีกครั้ง\n\n"
          . "Sorry, the system is experiencing issues. Please try again.";
sendPushMessage($userId, $errorMsg, $accessToken);
```

### LINE-Specific Features

**Loading Animation:**
```php
showLoadingAnimation($userId, 60, $accessToken);
// Shows typing indicator for 60 seconds
```

**Conversation ID Mapping:**
```php
$conversationId = 'line_' . $userId;
// Allows tracking conversations by LINE user
```

**Platform Separation:**
```php
$conversationManager = new ConversationManager($maxMessages, 'line', $dbConfig);
// Analytics can separate LINE vs API usage
```

---

## Configuration

### Environment Variables (`.env`)

```bash
# Gemini API
GEMINI_API_KEY=your_api_key_here
GEMINI_MODEL=gemini-2.5-flash
GEMINI_TEMPERATURE=0.7
GEMINI_MAX_OUTPUT_TOKENS=2048

# Server
PORT=3000
NODE_ENV=development
API_BASE_PATH=/sirichaielectric-chatbot

# Rate Limiting
MAX_REQUESTS_PER_MINUTE=1000  # Paid tier

# Product API
WEBSITE_URL=https://shop.sirichaielectric.com/
CATALOG_SUMMARY_URL=https://shop.sirichaielectric.com/services/category-products-prompt.php
PRODUCT_SEARCH_URL=https://shop.sirichaielectric.com/services/products-by-categories-prompt.php

# LINE
LINE_CHANNEL_SECRET=your_channel_secret
LINE_CHANNEL_ACCESS_TOKEN=your_access_token
VERIFY_LINE_SIGNATURE=false  # Set to true in production

# Database
DB_HOST=localhost
DB_PORT=3306
DB_NAME=chatbotdb
DB_USER=your_db_user
DB_PASSWORD=your_db_password

# Conversation
MAX_MESSAGES_PER_CONVERSATION=50
```

### Config Loader (`config.php`)

Singleton pattern loading .env variables:

```php
$config = Config::getInstance();

// Access configuration
$geminiConfig = $config->get('gemini');
$dbConfig = $config->get('database');

// Validation (throws Exception if required vars missing)
$config->validate();
```

### System Prompt (`system-prompt.txt`)

Contains AI behavior instructions, role definition, and workflow. Referenced as file via File API.

---

## Testing & Monitoring

### API Testing

```bash
# Test chat endpoint
curl -X POST https://yourdomain.com/sirichaielectric-chatbot/chat \
  -H "Content-Type: application/json" \
  -d '{
    "message": "หาสายไฟ 2.5 ตร.มม.",
    "conversationId": "test_conv_123"
  }'

# Get conversation
curl https://yourdomain.com/sirichaielectric-chatbot/conversation/test_conv_123

# Delete conversation
curl -X DELETE https://yourdomain.com/sirichaielectric-chatbot/conversation/test_conv_123
```

### Database Monitoring Queries

```sql
-- Total conversations by platform
SELECT platform, COUNT(*) as total
FROM conversations
GROUP BY platform;

-- Total messages and tokens
SELECT
    COUNT(*) as total_messages,
    SUM(tokens_used) as total_tokens,
    AVG(tokens_used) as avg_tokens_per_message
FROM messages;

-- Most active conversations (last 7 days)
SELECT
    c.conversation_id,
    c.platform,
    COUNT(m.id) as message_count,
    SUM(m.tokens_used) as total_tokens,
    MAX(m.timestamp) as last_message_at
FROM conversations c
LEFT JOIN messages m ON c.conversation_id = m.conversation_id
WHERE c.last_activity > DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY c.conversation_id
ORDER BY message_count DESC
LIMIT 10;

-- Token usage by platform (last 30 days)
SELECT
    c.platform,
    COUNT(DISTINCT c.conversation_id) as conversations,
    COUNT(m.id) as messages,
    SUM(m.tokens_used) as total_tokens,
    AVG(m.tokens_used) as avg_tokens_per_message
FROM conversations c
LEFT JOIN messages m ON c.conversation_id = m.conversation_id
WHERE c.last_activity > DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY c.platform;

-- Conversation length distribution
SELECT
    message_count_bucket,
    COUNT(*) as conversations
FROM (
    SELECT
        CASE
            WHEN COUNT(m.id) <= 5 THEN '1-5 messages'
            WHEN COUNT(m.id) <= 10 THEN '6-10 messages'
            WHEN COUNT(m.id) <= 20 THEN '11-20 messages'
            WHEN COUNT(m.id) <= 50 THEN '21-50 messages'
            ELSE '50+ messages'
        END as message_count_bucket
    FROM conversations c
    LEFT JOIN messages m ON c.conversation_id = m.conversation_id
    GROUP BY c.conversation_id
) as buckets
GROUP BY message_count_bucket
ORDER BY
    CASE message_count_bucket
        WHEN '1-5 messages' THEN 1
        WHEN '6-10 messages' THEN 2
        WHEN '11-20 messages' THEN 3
        WHEN '21-50 messages' THEN 4
        ELSE 5
    END;
```

### File API Monitoring

```bash
# Check cached files
php cleanup-files.php list

# View cache file directly
cat file-cache.json | php -r 'echo json_encode(json_decode(file_get_contents("php://stdin")), JSON_PRETTY_PRINT);'

# Check cache age
php -r '
$cache = json_decode(file_get_contents("file-cache.json"), true);
foreach ($cache as $type => $data) {
    $age = time() - $data["uploadedAt"];
    echo "$type: " . round($age / 3600, 1) . " hours old\n";
}
'
```

---

## Maintenance & Cleanup

### Database Maintenance

**Cleanup Old Conversations:**
```php
$conversationManager = new ConversationManager($maxMessages, 'api', $dbConfig);
$cleaned = $conversationManager->cleanupOldConversations(24); // 24 hours
echo "Cleaned up $cleaned old conversations\n";
```

**Optimize Tables:**
```sql
OPTIMIZE TABLE conversations;
OPTIMIZE TABLE messages;
```

**Backup Database:**
```bash
# Full backup
mysqldump -u chatbot_user -p chatbotdb > backup_$(date +%Y%m%d_%H%M%S).sql

# Compress backup
mysqldump -u chatbot_user -p chatbotdb | gzip > backup_$(date +%Y%m%d).sql.gz

# Restore from backup
mysql -u chatbot_user -p chatbotdb < backup_20260116_120000.sql
```

### File API Cleanup

**List Uploaded Files:**
```bash
php cleanup-files.php list
```

**Delete All Files:**
```bash
php cleanup-files.php delete-all
# Prompts for confirmation before deletion
```

**Clear Local Cache Only:**
```bash
php cleanup-files.php clear-cache
```

**Why Cleanup?**
- **NOT for cost** (File API is FREE)
- Quota management (20GB limit)
- Testing/development cleanup
- Privacy concerns
- Organization

**Remember:** Files auto-expire after 48 hours, cleanup is optional!

### Cron Jobs (Optional)

```bash
# Add to crontab
crontab -e

# Cleanup old conversations daily at 2 AM
0 2 * * * /usr/bin/php /path/to/sirichaielectric-chatbot/cleanup-old-conversations.php

# Backup database daily at 3 AM
0 3 * * * /usr/bin/mysqldump -u chatbot_user -p'password' chatbotdb | gzip > /backups/chatbot_$(date +\%Y\%m\%d).sql.gz
```

---

## Troubleshooting

### Common Issues

#### 1. Database Connection Failed

**Error:** `Database connection failed: Access denied for user`

**Solutions:**
```bash
# Check credentials in .env
cat .env | grep DB_

# Test connection manually
mysql -u your_db_user -p -h localhost chatbotdb

# Verify user permissions
mysql -u root -p -e "SHOW GRANTS FOR 'chatbot_user'@'localhost';"

# Grant permissions if needed
mysql -u root -p -e "GRANT ALL PRIVILEGES ON chatbotdb.* TO 'chatbot_user'@'localhost';"
mysql -u root -p -e "FLUSH PRIVILEGES;"
```

#### 2. File Upload Failed

**Error:** `Failed to upload file to Gemini API`

**Solutions:**
```bash
# Check API key
echo $GEMINI_API_KEY

# Test API key manually
curl -H "x-goog-api-key: $GEMINI_API_KEY" \
  https://generativelanguage.googleapis.com/v1beta/files

# Check file permissions
ls -la system-prompt.txt
chmod 644 system-prompt.txt

# Check disk space
df -h

# Clear old cache
rm file-cache.json
```

#### 3. LINE Webhook Not Working

**Error:** LINE messages not received

**Solutions:**
```bash
# Check webhook URL in LINE console
# Verify SSL certificate is valid

# Test webhook manually
curl -X POST https://yourdomain.com/line-webhook.php \
  -H "Content-Type: application/json" \
  -d '{}'

# Check error logs
tail -f /path/to/error.log

# Verify signature (if enabled)
# Set VERIFY_LINE_SIGNATURE=false for testing
```

#### 4. High Token Usage

**Issue:** Token costs still high

**Check:**
```bash
# Verify files are cached
cat file-cache.json

# Check file upload in logs
tail -f error.log | grep "File uploaded"

# Test file API
php test-file-api.php

# Force refresh
php -r '
require_once "SirichaiElectricChatbot.php";
$chatbot = new SirichaiElectricChatbot($geminiConfig, $productAPI);
$chatbot->refreshFiles();
'
```

#### 5. Conversation Not Persisting

**Issue:** Conversation history lost

**Check:**
```sql
-- Verify conversation exists
SELECT * FROM conversations WHERE conversation_id = 'your_conv_id';

-- Check messages
SELECT * FROM messages WHERE conversation_id = 'your_conv_id';

-- Check foreign key constraint
SHOW CREATE TABLE messages;
```

### Debug Mode

Enable detailed logging:

```php
// In index.php or line-webhook.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/path/to/debug.log');
```

### Performance Optimization

**Slow Queries:**
```sql
-- Enable slow query log
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 1;

-- Check indexes
SHOW INDEX FROM conversations;
SHOW INDEX FROM messages;

-- Add missing indexes if needed
CREATE INDEX idx_custom ON messages (conversation_id, timestamp);
```

**Memory Issues:**
```php
// Increase PHP memory limit
ini_set('memory_limit', '256M');

// Optimize message retrieval
$messages = $messageRepository->getHistory($conversationId);
// Instead of loading all fields
```

---

## Development History

### Timeline

**January 13, 2026 - Session to Database Migration**
- Created `DatabaseManager.php` (Singleton PDO wrapper)
- Migrated `ConversationManager.php` from `$_SESSION` to MySQL
- Created database schema (`schema.sql`)
- Added token tracking per message
- Increased max messages from 10 to 50
- Created `MIGRATION_GUIDE.md`

**January 14, 2026 - File API Integration**
- Created `GeminiFileManager.php`
- Modified `SirichaiElectricChatbot.php` to use File API
- Created `cleanup-files.php` utility
- Created `test-file-api.php` test script
- Achieved 95%+ token reduction
- Created `FILE-API-INTEGRATION.md` and `IMPLEMENTATION-SUMMARY.md`

**January 16, 2026 - Repository Pattern Refactoring**
- Created `repository/` folder structure
- Created `BaseRepository.php` abstract class
- Created `ConversationRepository.php`
- Created `MessageRepository.php`
- Refactored `ConversationManager.php` to use repositories
- Changed database name: `sirichaielectric_chatbot` → `chatbotdb`
- Created `REPOSITORY_REFACTOR_SUMMARY.md`

**January 16, 2026 - Code Cleanup**
- Removed `phpinfo.php` (security risk)
- Removed unused `sendReply()` function from `line-webhook.php`
- Fixed commented code in `ProductAPIService.php`
- Updated documentation with cleanup notes

**January 16, 2026 - Documentation Consolidation**
- Created `PROJECT.md` (this file)
- Combined all documentation into single comprehensive guide

### Key Architectural Decisions

**Why Repository Pattern?**
- Security: PDO prepared statements prevent SQL injection
- Maintainability: Centralized data access logic
- Testability: Easy to mock repositories for unit tests
- Reusability: Common operations in base class

**Why File API?**
- Cost: 95%+ token reduction (though File API is FREE anyway)
- Performance: Faster API responses (less data transmitted)
- Scalability: Better for large system prompts and catalogs

**Why MySQL over Sessions?**
- Persistence: Survive server restarts
- Scalability: Multiple servers can share data
- Analytics: Query conversation history and token usage
- LINE Integration: Cross-session continuity for users

**Why LINE Push API over Reply API?**
- Reliability: Reply tokens expire after ~60 seconds
- Async: Gemini processing can take 10+ seconds
- Features: Loading animation + actual response

---

## Best Practices

### Security

1. **Never commit sensitive data:**
   - `.env` should be in `.gitignore`
   - Use environment variables for secrets

2. **Always use prepared statements:**
   - NEVER concatenate user input into SQL
   - Use `?` placeholders and parameter binding

3. **Validate input:**
   - Check conversation IDs match expected format
   - Sanitize user messages before storage

4. **LINE signature verification:**
   - Enable in production: `VERIFY_LINE_SIGNATURE=true`
   - Only disable for local testing

### Performance

1. **Use file caching:**
   - Let File API cache handle refresh automatically
   - Only call `refreshFiles()` when content changes

2. **Trim conversations:**
   - Keep max messages limit reasonable (50 recommended)
   - Run cleanup for old conversations

3. **Index optimization:**
   - Ensure indexes exist on frequently queried columns
   - Monitor slow queries

4. **Connection pooling:**
   - DatabaseManager singleton reuses connections
   - Don't create new connections unnecessarily

### Maintainability

1. **Follow existing patterns:**
   - Use repositories for data access
   - Use singleton for config and database
   - Use service layer for business logic

2. **Log errors properly:**
   - Use `error_log()` with clear prefixes
   - Include context in error messages

3. **Document changes:**
   - Update relevant `.md` files
   - Add comments for complex logic

4. **Test thoroughly:**
   - Run `test-file-api.php` after File API changes
   - Test database queries before deployment
   - Verify LINE integration with test account

---

## API Reference

### REST API Endpoints

#### POST /chat
Send message and get response

**Request:**
```json
{
  "message": "หาสายไฟ 2.5 ตร.มม.",
  "conversationId": "conv_123" // optional
}
```

**Response:**
```json
{
  "success": true,
  "response": "ผมพบสายไฟที่ตรงกับความต้องการของคุณ...",
  "conversationId": "conv_123",
  "tokensUsed": 250
}
```

#### GET /conversation/{id}
Get full conversation history

**Response:**
```json
{
  "success": true,
  "conversation": {
    "conversationId": "conv_123",
    "platform": "api",
    "createdAt": 1705449600,
    "lastActivity": 1705449700,
    "messages": [
      {
        "role": "user",
        "content": "หาสายไฟ",
        "timestamp": 1705449600,
        "tokens_used": 0
      },
      {
        "role": "assistant",
        "content": "ผมพบสายไฟ...",
        "timestamp": 1705449650,
        "tokens_used": 250
      }
    ]
  }
}
```

#### DELETE /conversation/{id}
Delete conversation

**Response:**
```json
{
  "success": true,
  "message": "Conversation deleted successfully"
}
```

### ConversationManager API

```php
// Initialize
$conversationManager = new ConversationManager($maxMessages, 'api', $dbConfig);

// Get history
$messages = $conversationManager->getConversationHistory($conversationId);

// Add message
$conversationManager->addMessage($conversationId, 'user', $message, 0);
$conversationManager->addMessage($conversationId, 'assistant', $response, $tokens);

// Get full conversation
$conversation = $conversationManager->getConversation($conversationId);

// Delete conversation
$conversationManager->clearConversation($conversationId);

// Cleanup old conversations
$cleaned = $conversationManager->cleanupOldConversations(24); // 24 hours

// Analytics
$totalTokens = $conversationManager->getTotalTokens($conversationId);
$lineConvos = $conversationManager->getConversationsByPlatform('line', 100);
$userConvos = $conversationManager->getConversationsByUserId($userId);
```

### GeminiFileManager API

```php
// Initialize
$fileManager = new GeminiFileManager($apiKey);

// Upload file
$result = $fileManager->uploadFile($filePath, $displayName, $mimeType);

// Get cached URI
$uri = $fileManager->getCachedFileUri('systemPrompt');

// Refresh files
$result = $fileManager->refreshFiles();

// List files
$result = $fileManager->listAllFiles();

// Delete file
$result = $fileManager->deleteFile($fileName);

// Delete all files
$result = $fileManager->deleteAllFiles();
```

---

## Contributing

### Code Style

- PHP 5.6+ compatible
- Use type hints in docblocks (PHP 5.6 doesn't support native type hints)
- Follow existing naming conventions
- Comment complex logic
- Use meaningful variable names

### Git Workflow

```bash
# Create feature branch
git checkout -b feature/your-feature

# Make changes and commit
git add .
git commit -m "Brief description of changes"

# Push and create PR
git push origin feature/your-feature
```

### Testing Checklist

- [ ] Database queries use prepared statements
- [ ] Error handling in place
- [ ] Logging added for debugging
- [ ] Documentation updated
- [ ] Manual testing completed
- [ ] No sensitive data in code

---

## FAQ

**Q: Is File API really free?**
A: Yes! No storage or retrieval charges. 20GB quota per project.

**Q: How often should I run cleanup?**
A: Files auto-expire after 48 hours. Cleanup is optional, mainly for testing.

**Q: Can I use this with other LINE accounts?**
A: Yes! Just update LINE credentials in `.env`.

**Q: How do I change the max message limit?**
A: Update `MAX_MESSAGES_PER_CONVERSATION` in `.env` (default 50).

**Q: Can I deploy this on shared hosting?**
A: Yes, if it supports PHP 5.6+, MySQL 5.7+, and curl.

**Q: How do I monitor token usage?**
A: Use the monitoring SQL queries in the Testing section.

**Q: What if database connection is lost?**
A: DatabaseManager auto-reconnects on health check failure.

**Q: How do I add new repository methods?**
A: Add to appropriate repository class, following existing pattern.

---

## License & Credits

**Developed by:** Sirichai Electric Chatbot Team
**AI Model:** Google Gemini 2.5 Flash
**Database:** MySQL with InnoDB engine
**PHP Version:** 5.6+ compatible

---

## Support & Contact

For issues, questions, or contributions:
- Review existing documentation in this file
- Check troubleshooting section
- Review inline code comments
- Contact development team

---

**Last Updated:** January 16, 2026
**Version:** 2.0.0 (Repository Pattern + File API + LINE Integration)
