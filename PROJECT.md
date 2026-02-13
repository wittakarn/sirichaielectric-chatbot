# Sirichai Electric Chatbot - Complete Project Documentation

**Last Updated:** January 28, 2026
**Database:** chatbotdb (MySQL)
**PHP Version:** 5.6+
**Architecture:** Repository Pattern + File API + LINE Integration + Image Recognition

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
- **Image recognition and product identification**
- Multi-turn conversations with context
- Token usage tracking and optimization
- LINE Official Account integration
- Persistent conversation history
- 95%+ token reduction via File API
- **Chatbot pause/resume for human agent takeover**

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
    └── PROJECT.md                   # This file (comprehensive guide)
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
- 20-message context window (up from 10)
- Token usage tracking per message
- Platform separation (LINE vs API analytics)

**Database Tables:**

#### `conversations` Table
- conversation_id (VARCHAR 100, UNIQUE)
- platform ('api' or 'line')
- user_id (LINE user ID if applicable)
- max_messages_limit (default 20)
- created_at, last_activity timestamps

#### `messages` Table
- conversation_id (foreign key)
- role ('user' or 'assistant')
- content (TEXT)
- tokens_used (INT)
- sequence_number (message order)
- timestamp

### 3. Image Recognition & Product Identification

**Feature:** Users can send images of electrical products and get product recommendations

**How It Works:**
1. **Image Upload**: User sends image via LINE or API
2. **AI Analysis**: Gemini analyzes the image using multimodal capabilities
3. **Product Identification**: AI identifies electrical products in the image
4. **Catalog Search**: Automatically searches catalog for matching products
5. **Smart Response**: Provides product details, prices, and recommendations

**Supported Sources:**
- LINE Official Account (JPEG images from chat)
- API endpoint (base64 encoded images)

**Technical Implementation:**
- Uses Gemini's native vision capabilities (inline_data)
- Supports JPEG, PNG, and other common image formats
- Automatic fallback for non-product images
- Context-aware responses in Thai or English

**Example Flow:**
```
User sends image of circuit breaker
    ↓
Gemini analyzes: "This is a circuit breaker"
    ↓
Calls search_products(['เบรกเกอร์'])
    ↓
Returns matching products with prices
```

**LINE Integration:**
```php
// Download image from LINE Content API
$imageData = downloadLineContent($messageId, $accessToken);

// Process with chatbot
$response = $chatbot->chatWithImage($imageData, 'image/jpeg', '', $history);
```

**API Integration:**
```php
// Receive base64 image
$imageData = base64_decode($base64Image);

// Process with optional text message
$response = $chatbot->chatWithImage($imageData, $mimeType, $textMessage, $history);
```

**Smart Features:**
- Detects if image is product-related or not
- Asks clarifying questions if needed
- Handles images with text descriptions
- Falls back gracefully for non-electrical products

### 4. Repository Pattern

**Security & Maintainability:**
- All queries use PDO prepared statements (SQL injection prevention)
- Clean separation of data access logic
- Reusable query methods
- Transaction support for atomic operations

**Repository Classes:**
- `BaseRepository`: Common operations (query, fetchOne, fetchAll, transactions)
- `ConversationRepository`: Conversation CRUD, cleanup, analytics
- `MessageRepository`: Message CRUD, token tracking, history retrieval

### 5. LINE Messaging Integration

**Features:**
- Async processing (responds within 2 seconds)
- Loading animation during AI processing
- Push API for reliable message delivery
- Automatic message splitting (5000 char limit)
- Error handling with Thai+English messages
- **Image message support (JPEG/PNG)**
- **Automatic image download from LINE Content API**

**LINE-Specific Handling:**
- User ID → conversation ID mapping (`line_{userId}`)
- Separate platform tracking for analytics
- Connection closing via `litespeed_finish_request()` or `fastcgi_finish_request()`
- Image message download via LINE Content API
- Image placeholder storage in conversation history (`[ผู้ใช้ส่งรูปภาพ]`)

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
    max_messages_limit INT NOT NULL DEFAULT 20,
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
LINE User Sends Message (Text or Image)
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
  3. If image: Download from LINE Content API
  4. Call Gemini API (chatWithImage or chat)
  5. Save response to DB
  6. Send via Push API
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

**Image Message Handling:**
```php
// Check message type
if ($messageType === 'image') {
    $messageId = $event['message']['id'];

    // Download image from LINE Content API
    $imageData = downloadLineContent($messageId, $accessToken);

    if ($imageData === false) {
        // Send error message
        sendPushMessage($userId, "ขออภัยครับ ไม่สามารถรับรูปภาพได้...", $accessToken);
        return;
    }

    // Store placeholder in conversation history
    $conversationManager->addMessage($conversationId, 'user', '[ผู้ใช้ส่งรูปภาพ]', 0);

    // Process with chatbot
    $response = $chatbot->chatWithImage($imageData, 'image/jpeg', '', $history);
}
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

### 6. Chatbot Pause/Resume (Human Agent Takeover)

**Feature:** Users can request to talk to a human agent, which pauses the chatbot. Agents can resume the chatbot when done.

**How It Works:**
1. **User Request**: User sends a pause command (e.g., "ติดต่อพนักงาน" or "/human")
2. **Chatbot Paused**: System marks conversation as paused, stops AI responses
3. **Human Agent Handles**: Agent responds via LINE Official Account Manager
4. **Resume Chatbot**: Agent or user sends resume command (e.g., "/bot") to re-enable AI

**User Pause Commands** (shows confirmation to user):
| Command | Description |
|---------|-------------|
| `ติดต่อพนักงาน` | Thai: "Contact staff" |
| `คุยกับพนักงาน` | Thai: "Talk to staff" |
| `ขอคุยกับพนักงาน` | Thai: "Request to talk to staff" |
| `/human` | English command |
| `/agent` | English command |

**Resume Commands** (re-enable chatbot):
| Command | Description |
|---------|-------------|
| `เปิดแชทบอท` | Thai: "Turn on chatbot" |
| `เปิดบอท` | Thai: "Turn on bot" |
| `/bot` | English command |
| `/resume` | English command |
| `/on` | English command |
| `/chatbot` | English command |

**Database Schema:**
```sql
-- Added to conversations table
is_chatbot_active TINYINT(1) NOT NULL DEFAULT 1  -- 1=active, 0=paused
paused_at TIMESTAMP NULL DEFAULT NULL            -- When paused
```

**API Methods:**
```php
// Pause chatbot for conversation
$conversationManager->pauseChatbot($conversationId);

// Resume chatbot
$conversationManager->resumeChatbot($conversationId);

// Check if chatbot is active
$isActive = $conversationManager->isChatbotActive($conversationId);

// Get all paused conversations (for admin dashboard)
$paused = $conversationManager->getPausedConversations();

// Auto-resume after timeout (e.g., 30 minutes)
$resumed = $conversationManager->autoResumeChatbot(30);
```

**User Flow Example (User requests agent):**
```
User:  "ติดต่อพนักงาน"
Bot:   "ได้รับคำขอแล้วค่ะ พนักงานจะติดต่อกลับโดยเร็วที่สุด..."

[Human agent responds via LINE OA Manager]

User:  "/bot"
Bot:   "แชทบอทกลับมาให้บริการแล้วค่ะ 🤖 มีอะไรให้ช่วยไหมคะ?"
```

**Agent Takeover Example (Agent initiates):**
```
[Agent sees customer needs help in LINE OA Manager]

Agent: "/off"
Bot:   "🔔 แชทบอทหยุดทำงานชั่วคราว พนักงานพร้อมให้บริการแล้วค่ะ"

[Agent handles conversation manually]

Agent: "/bot"
Bot:   "แชทบอทกลับมาให้บริการแล้วค่ะ 🤖 มีอะไรให้ช่วยไหมคะ?"
```

**Important Notes:**
- While paused, user messages are still received by webhook but chatbot doesn't respond
- Human agents respond via LINE Official Account Manager (Chat mode must be enabled)
- Consider setting up auto-resume after timeout to prevent forgotten paused conversations

---

## Gemini Function Calling Architecture

### How Function Calling Works

Gemini API uses a **two-step conversational flow** for function calling. The AI doesn't execute functions directly - instead, it requests your code to execute them and returns the results.

#### The Two-Step Process

```
┌─────────────────────────────────────────────────────────────────┐
│ STEP 1: Gemini Decides to Call a Function                      │
└─────────────────────────────────────────────────────────────────┘

User: "มีตู้เหล็ก KJL รุ่นไหนบ้าง" (What KJL metal cabinets do you have?)
     ↓
Your PHP Code → Sends to Gemini API with function declarations
     ↓
Gemini AI analyzes: "I need product data to answer this. I should call search_products()"
     ↓
Gemini Response: {
  "functionCalls": [{
    "name": "search_products",
    "args": {"criterias": ["ตู้เหล็ก KJL KBSA"]}
  }]
}


┌─────────────────────────────────────────────────────────────────┐
│ STEP 2: Execute Function and Send Results Back                 │
└─────────────────────────────────────────────────────────────────┘

Your PHP Code: Executes search_products() → Gets product data from API
     ↓
Your PHP Code → Sends conversation + function results back to Gemini
     ↓
Gemini AI: "Now I have the data! Let me format a nice response."
     ↓
Gemini Response: {
  "text": "ตู้เหล็ก KJL KBSA มี 3 รุ่น:\n\nตู้เหล็ก KJL KBSA (รุ่น 1) ราคา: 1,250 บาท/ชิ้น..."
}
     ↓
Return final response to user
```

### Available Functions

#### 1. search_products()

**Purpose:** Search for products by category names from the catalog

**When to Use:**
- Customer asks about specific products, prices, or availability
- Requests to see products from a category or brand
- Mentions model codes or series (KBSA, KBSW, KJL, etc.)
- Asks follow-up filtering questions ("only metal", "show me X brand")

**Parameters:**
```php
array(
  'criterias' => array(
    'ตู้เหล็ก KJL KBSA { รุ่น KBSA-100 }',
    'สายไไฟ 2.5 ตร.มม.'
  )
)
```

**Returns:** Markdown formatted product list with name, price, and unit

**Example:**
```
# PRODUCTS
## ตู้เหล็ก KJL KBSA
- ตู้เหล็ก KJL KBSA (รุ่น 1) | 1,250 | ชิ้น
- ตู้เหล็ก KJL KBSA (รุ่น 2) | 1,450 | ชิ้น
```

#### 2. search_product_detail()

**Purpose:** Get detailed specifications for a specific product (weight, size, quantity per pack)

**When to Use:**
- Customer asks about product weight, dimensions, or size
- Asks how many pieces come in a pack/box
- Wants detailed specifications beyond name and price
- Thai keywords: "น้ำหนักเท่าไหร่", "ขนาด", "มีกี่ชิ้นต่อแพ็ค"

**Parameters:**
```php
array(
  'productName' => '[422112*1] แมกเนติก LC1D12M7 220V 1NO+1NC SCHNEIDER {MAGNETIC CONTACTOR LC1-D12M7 | LC1D12M7 SCHNEIDER}'
)
```

**Important:** Use the EXACT product name from `search_products()` results

**Returns:** JSON with detailed product specifications

**Example Usage Flow:**
```
Customer: "มีตู้เหล็ก KJL รุ่นไหนบ้าง"
Bot: [Calls search_products(), shows 3 products]

Customer: "รุ่นแรกน้ำหนักเท่าไหร่"
Bot: [Calls search_product_detail() with exact product name]
Bot: "น้ำหนัก: 2.5 กก., ขนาด: 30x40x15 ซม., บรรจุ 1 ชิ้น/กล่อง"
```

### Code Implementation

#### Function Declaration (SirichaiElectricChatbot.php lines 554-589)

```php
$requestBody['tools'] = array(
    array(
        'functionDeclarations' => array(
            array(
                'name' => 'search_products',
                'description' => 'Search for products by exact category names...',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'criterias' => array(
                            'type' => 'array',
                            'items' => array('type' => 'string'),
                            'description' => 'Array of EXACT category names...'
                        )
                    ),
                    'required' => array('criterias')
                )
            ),
            array(
                'name' => 'search_product_detail',
                'description' => 'Get detailed information for a specific product...',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'productName' => array(
                            'type' => 'string',
                            'description' => 'The EXACT product name as it appears in search results.'
                        )
                    ),
                    'required' => array('productName')
                )
            )
        )
    )
);
```

#### Function Execution (SirichaiElectricChatbot.php lines 470-486)

```php
private function executeFunction($functionName, $args) {
    if ($functionName === 'search_products') {
        $criterias = isset($args['criterias']) ? $args['criterias'] : array();
        $result = $this->productAPI->searchProducts($criterias);
        return $result !== null ? $result : "No products found.";
    }

    if ($functionName === 'search_product_detail') {
        $productName = isset($args['productName']) ? $args['productName'] : '';
        $result = $this->productAPI->getProductDetail($productName);
        return $result !== null ? $result : "Product details not found.";
    }

    return "Unknown function: " . $functionName;
}
```

#### Conversation Flow (SirichaiElectricChatbot.php lines 375-451)

```php
private function handleFunctionCalls($response, $originalContents) {
    // Step 1: Build conversation with function call request
    $contents = $originalContents;

    // Add Gemini's function call to conversation
    $contents[] = array(
        'role' => 'model',
        'parts' => [cleanedFunctionCallParts]
    );

    // Step 2: Execute the actual function
    foreach ($response['functionCalls'] as $call) {
        $result = $this->executeFunction($call['name'], $call['args']);

        $functionResponseParts[] = array(
            'functionResponse' => array(
                'name' => $call['name'],
                'response' => array('content' => $result)
            )
        );
    }

    // Step 3: Add function results to conversation
    $contents[] = array(
        'role' => 'user',
        'parts' => $functionResponseParts
    );

    // Step 4: Call Gemini again with results
    $finalResponse = $this->callGeminiWithFunctions($contents, true);

    return $finalResponse;
}
```

### Why This Design?

**Gemini doesn't execute functions** - it's just a language model! It can only:
1. **Understand** that it needs external data
2. **Request** a function call with proper arguments
3. **Use** the results you provide to formulate an answer

Your PHP code acts as the bridge that:
- Declares what functions are available
- Executes the actual function calls against your APIs
- Passes results back to Gemini for final response formatting

### Helper Methods

#### Extract Search Criteria (SirichaiElectricChatbot.php lines 458-468)

```php
/**
 * Extract search criteria from function call for logging purposes
 * Centralized logic to avoid duplication (DRY principle)
 */
private function extractSearchCriteria($functionName, $args) {
    if ($functionName === 'search_products' && isset($args['criterias'])) {
        return json_encode($args['criterias'], JSON_UNESCAPED_UNICODE);
    }

    if ($functionName === 'search_product_detail' && isset($args['productName'])) {
        return json_encode(array('productName' => $args['productName']), JSON_UNESCAPED_UNICODE);
    }

    return null;
}
```

This method is used in the foreach loops (lines 209-212 and 311-314) to capture search criteria for database logging and analytics.

### Adding New Functions

To add a new function (e.g., `check_inventory`):

1. **Add function declaration** in `callGeminiWithFunctions()`:
```php
array(
    'name' => 'check_inventory',
    'description' => 'Check inventory status for a product',
    'parameters' => array(
        'type' => 'object',
        'properties' => array(
            'productId' => array(
                'type' => 'string',
                'description' => 'Product ID to check'
            )
        ),
        'required' => array('productId')
    )
)
```

2. **Add execution handler** in `executeFunction()`:
```php
if ($functionName === 'check_inventory') {
    $productId = isset($args['productId']) ? $args['productId'] : '';
    $result = $this->productAPI->checkInventory($productId);
    return $result !== null ? $result : "Inventory unavailable.";
}
```

3. **Add to extractSearchCriteria()** (if logging needed):
```php
if ($functionName === 'check_inventory' && isset($args['productId'])) {
    return json_encode(array('productId' => $args['productId']), JSON_UNESCAPED_UNICODE);
}
```

4. **Update system-prompt.txt** with usage instructions

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
MAX_MESSAGES_PER_CONVERSATION=20
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
# Test text chat endpoint
curl -X POST https://yourdomain.com/sirichaielectric-chatbot/chat \
  -H "Content-Type: application/json" \
  -d '{
    "message": "หาสายไฟ 2.5 ตร.มม.",
    "conversationId": "test_conv_123"
  }'

# Test image chat endpoint
curl -X POST https://yourdomain.com/sirichaielectric-chatbot/chat-with-image \
  -H "Content-Type: application/json" \
  -d '{
    "imageData": "'"$(base64 -i test-image.jpg)"'",
    "mimeType": "image/jpeg",
    "message": "What product is this?",
    "conversationId": "test_conv_123"
  }'

# Get conversation
curl https://yourdomain.com/sirichaielectric-chatbot/conversation/test_conv_123

# Delete conversation
curl -X DELETE https://yourdomain.com/sirichaielectric-chatbot/conversation/test_conv_123
```

### LINE Image Testing

```bash
# Send image via LINE Official Account
# 1. Open LINE chat with your bot
# 2. Send an image of an electrical product
# 3. Check logs to verify processing
tail -f /path/to/error.log | grep "LINE"

# Expected log flow:
# [LINE] Message type: image
# [LINE] Downloading image: <message_id>
# [Chatbot] Image: Calling: search_products(...)
# [LINE] Sending response via Push API
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

#### 6. AI Not Searching Products Correctly

**Issue:** AI lists categories but doesn't show actual products, or can't find products from follow-up questions

**Common Symptoms:**
- "ขออภัยค่ะ ระบบไม่สามารถดึงข้อมูล..." when user asks follow-up questions
- AI lists category names but shows no products
- Can't find products when user mentions model codes (KBSA, KBSW, etc.)

**Root Causes:**
1. System prompt not clear about when to call search_products()
2. AI not matching user queries to exact category names
3. Follow-up questions not triggering product searches

**Solutions:**
1. **Review system-prompt.txt**: Ensure it has clear guidance:
   - When to call search_products() (including follow-up questions)
   - How to match keywords to category names
   - Examples of common query patterns
   - CRITICAL rule to always show products, not just categories

2. **Check catalog file**: Verify product catalog is uploaded and cached:
   ```bash
   cat file-cache.json | grep catalog
   ```

3. **Test with explicit queries**: Ask specific questions to verify:
   ```
   "หาตู้เหล็ก KJL ทุกรุ่น"  (should trigger search)
   "ขอรายละเอียด KBSA"      (should find exact category)
   "มีรุ่นไหนบ้าง"          (should show actual products)
   ```

4. **Force refresh files**: If catalog changed:
   ```php
   $chatbot->refreshFiles();
   ```

5. **Check logs**: Look for function call logs:
   ```bash
   tail -f error.log | grep "Calling: search_products"
   ```

### Debug Mode

Enable detailed logging:

```php
// In index.php or line-webhook.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/logs.log');
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

**January 2026 - Image Recognition Support**
- Added `chatWithImage()` method to `SirichaiElectricChatbot.php`
- Implemented image download from LINE Content API
- Updated `line-webhook.php` to handle image messages
- Added image analysis instructions to `system-prompt.txt`
- Support for multimodal AI interactions (image + text)
- Automatic product identification from images
- Smart fallback for non-product images

**January 28, 2026 - System Prompt Improvements**
- Enhanced product search accuracy for follow-up questions
- Added explicit guidance for handling model code queries (KBSA, KBSW, etc.)
- Improved keyword matching logic (brand + product type + model code)
- Added CRITICAL rule to always show actual products, not just category lists
- Added matching examples for common query patterns
- Clarified when to call search_products() vs general conversation

**January 28, 2026 - Chatbot Pause/Resume Feature (Human Agent Takeover)**
- Added `is_chatbot_active` and `paused_at` columns to conversations table
- Created migration script: `migrations/001_add_chatbot_active_flag.sql`
- Added pause/resume methods to `ConversationRepository.php`
- Added pause/resume API to `ConversationManager.php`
- Modified `line-webhook.php` to handle pause/resume commands
- Pause commands: "ติดต่อพนักงาน", "/human", "/pause", "/agent"
- Resume commands: "เปิดแชทบอท", "/bot", "/resume", "/chatbot"
- Auto-resume capability after configurable timeout
- Allows human agents to take over conversations via LINE OA Manager

**February 13, 2026 - Product Detail Function & Code Refactoring**
- Added `search_product_detail()` function for detailed product specifications
- Created `getProductDetail()` method in `ProductAPIService.php`
- Integrated with endpoint: `https://shop.sirichaielectric.com/services/get-product-by-name.php`
- Provides weight, size, dimensions, quantity per pack, and other specs
- Refactored duplicate search criteria logging logic into `extractSearchCriteria()` method
- Applied DRY principle to reduce code duplication in function call handling
- Updated `system-prompt.txt` with usage guidelines for product detail queries
- Added comprehensive function calling architecture documentation to PROJECT.md
- Customers can now ask specific questions like "น้ำหนักเท่าไหร่" or "มีกี่ชิ้นต่อแพ็ค"

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

**Why Image Recognition?**
- User Experience: Customers can simply take a photo of a product
- Accuracy: Visual identification reduces miscommunication
- Convenience: No need to describe complex electrical products in text
- Native Support: Gemini's multimodal API handles images natively
- Cost-Effective: Images processed via inline_data (no file upload needed)

**Why Store Image Placeholders?**
- Privacy: Avoid storing customer images in database
- Storage: Images consume significant database space
- Compliance: Reduces data retention concerns
- Context: Placeholder maintains conversation flow
- Performance: Faster database queries without BLOB fields

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
   - Keep max messages limit reasonable (20 recommended)
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

5. **System Prompt Maintenance:**
   - Test with real user queries regularly
   - Add examples for common failure patterns
   - Keep instructions clear and specific
   - Use CRITICAL/IMPORTANT markers for key rules
   - Document any prompt changes in PROJECT.md
   - Force refresh files after updating system-prompt.txt:
     ```php
     $chatbot->refreshFiles();
     ```

---

## API Reference

### REST API Endpoints

#### POST /chat
Send message and get response (text only)

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

#### POST /chat-with-image
Send message with image and get response

**Request:**
```json
{
  "imageData": "base64_encoded_image_data",
  "mimeType": "image/jpeg",
  "message": "What is this product?", // optional
  "conversationId": "conv_123" // optional
}
```

**Response:**
```json
{
  "success": true,
  "response": "This appears to be a circuit breaker. I found these matching products...",
  "conversationId": "conv_123",
  "tokensUsed": 450
}
```

### SirichaiElectricChatbot API

```php
// Initialize
$chatbot = new SirichaiElectricChatbot($geminiConfig, $productAPI);

// Text chat
$response = $chatbot->chat($message, $conversationHistory);

// Image chat
$response = $chatbot->chatWithImage($imageData, $mimeType, $textMessage, $conversationHistory);

// Refresh uploaded files
$chatbot->refreshFiles();
```

**chatWithImage() Parameters:**
- `$imageData` (string): Raw binary image data
- `$mimeType` (string): Image MIME type (e.g., 'image/jpeg', 'image/png')
- `$textMessage` (string, optional): Text message to accompany the image
- `$conversationHistory` (array, optional): Previous conversation messages

**Response Format:**
```php
array(
  'success' => true,
  'response' => 'AI response text',
  'language' => 'th', // or 'en'
  'tokensUsed' => 450
)
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
A: Update `MAX_MESSAGES_PER_CONVERSATION` in `.env` (default 20).

**Q: Can I deploy this on shared hosting?**
A: Yes, if it supports PHP 5.6+, MySQL 5.7+, and curl.

**Q: How do I monitor token usage?**
A: Use the monitoring SQL queries in the Testing section.

**Q: What if database connection is lost?**
A: DatabaseManager auto-reconnects on health check failure.

**Q: How do I add new repository methods?**
A: Add to appropriate repository class, following existing pattern.

**Q: Does the chatbot support image messages?**
A: Yes! Users can send images via LINE, and the AI will analyze them to identify electrical products and search the catalog.

**Q: What image formats are supported?**
A: JPEG and PNG are fully supported. Other formats supported by Gemini's vision API should also work.

**Q: How are images stored in the database?**
A: Images are NOT stored in the database. Only a placeholder text `[ผู้ใช้ส่งรูปภาพ]` is saved to the conversation history for context.

**Q: Can I send images via the REST API?**
A: Yes, you can use the `/chat-with-image` endpoint with base64 encoded image data.

**Q: What happens if someone sends a non-product image?**
A: The AI will describe what it sees and politely ask how it can help, maintaining a natural conversation flow.

**Q: Why does the AI list categories but not show actual products?**
A: This usually means the system prompt needs improvement. The AI should ALWAYS call search_products() when users ask about products. Check that system-prompt.txt has clear instructions about when to search and how to match keywords to category names.

**Q: The AI can't find products when I ask follow-up questions like "only metal ones" or "show me KBSA models". How do I fix this?**
A: The system prompt needs to explicitly handle follow-up questions and model code queries. Ensure system-prompt.txt includes:
- Examples of follow-up filtering patterns
- Instructions for matching model codes (KBSA, KBSW, etc.) to exact category names
- Clear rule that follow-up product questions should trigger search_products()

**Q: How do I pause the chatbot so a human agent can respond?**
A: Users can send "ติดต่อพนักงาน" or "/human" to pause the chatbot. The agent then responds via LINE Official Account Manager. To resume, send "/bot" or "เปิดแชทบอท".

**Q: Can I set up auto-resume for paused conversations?**
A: Yes, call `$conversationManager->autoResumeChatbot(30)` to auto-resume conversations paused for more than 30 minutes. You can set this up as a cron job.

**Q: How do I see which conversations are waiting for human agents?**
A: Use `$conversationManager->getPausedConversations()` to get a list of all paused conversations. You can build an admin dashboard using this API.

**Q: What's the difference between search_products() and search_product_detail()?**
A: `search_products()` returns a list of products with names and prices from categories. `search_product_detail()` provides detailed specifications (weight, size, quantity per pack) for a specific product. Use search_products() for browsing, then search_product_detail() when customers ask for more details.

**Q: How do I add a new function for the AI to call?**
A: See the "Adding New Functions" section under "Gemini Function Calling Architecture". You need to: (1) add function declaration, (2) add execution handler, (3) optionally add logging support, and (4) update system-prompt.txt with usage instructions.

**Q: Why refactor the search criteria extraction?**
A: The original code had duplicate `if/elseif` blocks in two locations (lines 209-213 and 311-315). By extracting into a `extractSearchCriteria()` method, we follow the DRY (Don't Repeat Yourself) principle, making the code easier to maintain and extend when adding new functions.

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

**Last Updated:** January 28, 2026
**Version:** 2.2.0 (Repository Pattern + File API + LINE Integration + Image Recognition + Human Agent Takeover)
