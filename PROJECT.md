# Sirichai Electric Chatbot - Project Documentation

**Last Updated:** March 6, 2026
**Version:** 3.0.0
**PHP:** 7.4+ (MAMP: `/Applications/MAMP/bin/php/php7.4.33/bin/php`)
**Architecture:** chatbot-core Composer library + Sirichai application layer

> **Quick AI Context:** See [claude.md](claude.md) for condensed AI-assistant context

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Architecture](#2-architecture)
3. [File Structure](#3-file-structure)
4. [Database Setup](#4-database-setup)
5. [Environment Configuration](#5-environment-configuration)
6. [Key Features](#6-key-features)
7. [Gemini Function Calling](#7-gemini-function-calling)
8. [LINE Integration](#8-line-integration)
9. [Admin & Dashboard](#9-admin--dashboard)
10. [Testing](#10-testing)
11. [File Cache Management](#11-file-cache-management)
12. [Common Tasks](#12-common-tasks)
13. [Troubleshooting](#13-troubleshooting)
14. [Development History](#14-development-history)

---

## 1. Project Overview

Sirichai Electric Chatbot is a conversational AI system for customer service on electrical product inquiries. It supports LINE Official Account and a REST API, with persistent conversation history backed by MySQL.

### Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 7.4+ |
| AI | Google Gemini 2.5 Flash |
| Database | MySQL 5.7+ (chatbotdb) |
| LINE | LINE Messaging API (Push API) |
| Admin UI | PHP session auth + HTML |
| Monitoring | React + TypeScript + Vite + TanStack Query |
| Library | `wittakarn/chatbot-core` (local Composer package) |

### Key Capabilities

- Natural language product search (Thai + English)
- Image recognition — send photo of product, get catalog matches
- Multi-turn conversation with persistent history (20 messages)
- Quotation PDF generation with price tier control
- Authorized user system (rate/pricing tiers)
- Chatbot pause/resume for human agent handoff
- Real-time admin monitoring dashboard
- 95%+ token reduction via Gemini File API

---

## 2. Architecture

### Two-Layer Design

```
┌─────────────────────────────────────────────────┐
│           Sirichai Application Layer             │
│                                                  │
│  index.php         line-webhook.php              │
│       │                   │                      │
│  SirichaiElectricChatbot  SirichaiLineWebhook    │
│  (extends GeminiChatbot)  (extends Webhook)      │
│       │                   │                      │
│  AppConfig (extends Config)                      │
│  ProductAPIService                               │
└──────────────────┬──────────────────────────────┘
                   │ uses
┌──────────────────▼──────────────────────────────┐
│         wittakarn/chatbot-core (library)         │
│                                                  │
│  GeminiChatbot       LineWebhookHandler          │
│  ConversationManager GeminiFileManager           │
│  Config              DatabaseManager             │
│  Repository/*        Utils/LineWebhookUtils      │
└──────────────────────────────────────────────────┘
                   │
┌──────────────────▼──────────────────────────────┐
│  MySQL (chatbotdb)    Gemini File API            │
│  conversations        catalog-summary (46h)      │
│  messages             system-prompt (inline)     │
│  authorized_users                                │
└──────────────────────────────────────────────────┘
```

### Admin / Monitoring Layer

```
admin/dashboard.php → ConversationManager → DB
admin/api/monitoring.php → DashboardController → DashboardService → DB
dashboard/ (React) → admin/api/monitoring.php
```

### Gemini Call Flow

```
User message
    │
    ▼
GeminiChatbot::chat()
    │
    ▼
callGeminiWithFunctions()  ← catalog file URI prepended to first message
    │
    ├─ Text response → return
    │
    └─ Function call → handleFunctionCalls()
            │
            ├─ executeFunction() [PHP]
            │
            └─ callGeminiWithFunctions() [loop, max 6 additional rounds]
                    │
                    └─ Text response → return
```

---

## 3. File Structure

```
sirichaielectric-chatbot/
│
├── index.php                        # REST API — /health, /chat, /conversation/:id
├── line-webhook.php                 # LINE webhook entry point
├── AppConfig.php                    # Extends Config: adds productAPI, website, admin sections
├── SirichaiLineWebhook.php          # Extends LineWebhookHandler: wires chatbot + DB
├── system-prompt.txt                # AI behavior instructions (inline, NOT File API)
│
├── chatbot/
│   └── SirichaiElectricChatbot.php  # Extends GeminiChatbot: 3 functions, auth enforcement
│
├── services/
│   ├── ProductAPIService.php        # HTTP client: catalog, search, detail, quotation APIs
│   ├── DashboardService.php         # Data aggregation for monitoring dashboard
│   ├── LineProfileService.php       # Fetch LINE display name/avatar for admin
│   └── CacheClearService.php        # HTTP endpoint to clear catalog cache
│
├── controllers/
│   └── DashboardController.php      # REST endpoints for React monitoring dashboard
│
├── admin/
│   ├── index.php                    # Redirect to dashboard
│   ├── login.php                    # Session auth login form
│   ├── logout.php                   # Session logout
│   ├── auth.php                     # Auth guard (requireAdminAuth())
│   ├── dashboard.php                # Admin UI: view/pause/resume conversations
│   ├── generate-password-hash.php   # CLI: generate bcrypt hash for ADMIN_PASSWORD_HASH
│   └── api/
│       ├── monitoring.php           # REST: recent conversations for React dashboard
│       └── conversations.php        # REST: conversation management
│
├── dashboard/                       # React monitoring dashboard (TypeScript + Vite)
│   ├── src/
│   │   ├── App.tsx
│   │   ├── main.tsx
│   │   ├── components/ui/
│   │   │   ├── ChatDashboard.tsx    # Main dashboard layout
│   │   │   ├── ConversationList.tsx # List of recent conversations
│   │   │   └── MessageList.tsx      # Message thread view
│   │   ├── services/
│   │   │   └── monitoringService.ts # API client for monitoring endpoint
│   │   └── lib/utils.ts
│   ├── package.json
│   ├── vite.config.ts
│   ├── tsconfig.json
│   ├── tailwind.config.js
│   └── config.php                   # PHP bridge: injects WEBSITE_URL global
│
├── tests/
│   ├── test-chatbot-without-history.php  # 5 independent questions
│   ├── test-chatbot-with-history.php     # 12-turn quotation workflow
│   ├── test-chatbot-batch-price.php      # Batch price + quotation workflow
│   └── test-chatbot-unauthorized.php     # Rate enforcement for unauthorized users
│
├── vendor/
│   └── wittakarn/chatbot-core/src/
│       ├── GeminiChatbot.php             # Abstract AI engine
│       ├── LineWebhookHandler.php        # Abstract LINE webhook runner
│       ├── ConversationManager.php       # Conversation state + authorization
│       ├── GeminiFileManager.php         # File API upload + URI cache
│       ├── Config.php                    # .env loader singleton (extensible)
│       ├── DatabaseManager.php           # PDO singleton
│       ├── Repository/
│       │   ├── BaseRepository.php        # PDO helpers, transaction support
│       │   ├── ConversationRepository.php
│       │   ├── MessageRepository.php
│       │   └── AuthorizedUserRepository.php
│       └── Utils/
│           └── LineWebhookUtils.php      # Signature verify, push, split, animation
│
├── cache/
│   └── catalog-summary-cache.md    # Product catalog (24h local cache)
│
├── cron/
│   └── auto-resume-chatbot.php     # Cron job: auto-resume paused conversations
│
├── migrations/                     # Schema migration scripts
├── schema.sql                      # Full database schema
├── file-cache.json                 # Gemini File API URI cache (gitignored)
├── logs.log                        # Application error log (gitignored)
├── composer.json                   # Declares wittakarn/chatbot-core as path dependency
└── .env                            # Environment variables (gitignored)
```

---

## 4. Database Setup

### Create Database

```bash
mysql -u root -p -e "CREATE DATABASE chatbotdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p chatbotdb < schema.sql
```

### Schema

```sql
CREATE TABLE conversations (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id    VARCHAR(100) NOT NULL UNIQUE,
    platform           VARCHAR(20)  NOT NULL DEFAULT 'api',    -- 'api' or 'line'
    user_id            VARCHAR(100) NULL,                      -- LINE user ID
    max_messages_limit INT          NOT NULL DEFAULT 20,
    is_chatbot_active  TINYINT      NOT NULL DEFAULT 1,        -- 1=active, 0=paused
    paused_at          TIMESTAMP    NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_conversation_id (conversation_id),
    INDEX idx_platform (platform),
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id VARCHAR(100)           NOT NULL,
    role            ENUM('user','assistant') NOT NULL,
    content         TEXT                   NOT NULL,
    tokens_used     INT                    NOT NULL DEFAULT 0,
    sequence_number INT                    NOT NULL,
    search_criteria TEXT                   NULL,               -- JSON of function call args
    is_active       TINYINT                NOT NULL DEFAULT 1, -- soft delete
    timestamp       TIMESTAMP              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    INDEX idx_conversation_id (conversation_id),
    INDEX idx_timestamp (timestamp),
    INDEX idx_sequence (conversation_id, sequence_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE authorized_users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    VARCHAR(100) NOT NULL,                          -- LINE user ID (substring match)
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Useful Queries

```bash
# Clear all conversations
mysql -u root -p chatbotdb -e "DELETE FROM conversations;"

# Recent messages
mysql -u root -p chatbotdb -e "SELECT * FROM messages ORDER BY timestamp DESC LIMIT 10;"

# Token usage by platform
mysql -u root -p chatbotdb -e "
  SELECT c.platform, SUM(m.tokens_used) as total_tokens
  FROM conversations c
  JOIN messages m ON c.conversation_id = m.conversation_id
  GROUP BY c.platform;"

# Add authorized user
mysql -u root -p chatbotdb -e "INSERT INTO authorized_users (user_id) VALUES ('U1234abcd');"
```

---

## 5. Environment Configuration

```bash
# Gemini
GEMINI_API_KEY=xxx
GEMINI_MODEL=gemini-2.5-flash
GEMINI_TEMPERATURE=0.7
GEMINI_MAX_OUTPUT_TOKENS=2048

# Database
DB_HOST=localhost
DB_PORT=3306
DB_NAME=chatbotdb
DB_USER=xxx
DB_PASSWORD=xxx

# LINE
LINE_CHANNEL_SECRET=xxx
LINE_CHANNEL_ACCESS_TOKEN=xxx
VERIFY_LINE_SIGNATURE=true

# Product API (all 4 required, validated by AppConfig::validate())
CATALOG_SUMMARY_URL=https://shop.sirichaielectric.com/services/category-products-prompt.php
PRODUCT_SEARCH_URL=https://shop.sirichaielectric.com/services/products-by-categories-prompt.php
PRODUCT_DETAIL_URL=https://shop.sirichaielectric.com/services/product-detail-prompt.php
QUOTATION_URL=https://shop.sirichaielectric.com/services/fast-quotation.php

WEBSITE_URL=https://shop.sirichaielectric.com/

# Conversation
MAX_MESSAGES_PER_CONVERSATION=20
AUTO_RESUME_TIMEOUT_MINUTES=30
MAX_REQUESTS_PER_MINUTE=15

# Server (optional)
API_BASE_PATH=                     # e.g. /chatbot for subdirectory installs

# Admin (bcrypt hash, generate with admin/generate-password-hash.php)
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH=$2y$10$...
```

---

## 6. Key Features

### 6.1 Token Optimization via File API (Hybrid Approach)

**System prompt** is sent inline in `systemInstruction` (~5KB text, every request).
**Product catalog** (~101KB) is uploaded once to Gemini File API, URI cached locally.

| Metric | Before (inline) | After (File API) | Savings |
|--------|----------------|------------------|---------|
| Catalog tokens | ~3,000/request | ~10-50 (cached) | 95%+ |
| File API cost | — | FREE | — |
| Cache TTL | — | 46 hours | — |

The catalog file URI is prepended to the first message part of every Gemini request:

```php
// In GeminiChatbot::callGeminiWithFunctions()
if ($this->catalogFileUri && !empty($contents) && $contents[0]['role'] === 'user') {
    $contents[0]['parts'] = array_merge(
        [['file_data' => ['file_uri' => $this->catalogFileUri, 'mime_type' => 'text/plain']]],
        $contents[0]['parts']
    );
}
```

### 6.2 Persistent Conversation History

- All messages stored in MySQL `messages` table
- Max 20 messages per conversation (configurable via `MAX_MESSAGES_PER_CONVERSATION`)
- Soft delete via `is_active` flag — `resetConversationHistory()` deactivates, doesn't delete
- LINE users get persistent conversation across sessions (`line_{userId}` ID)
- API users generate a new `conv_{timestamp}_{9chars}` ID per session (or pass their own)

### 6.3 Image Recognition

Users send a product photo; Gemini analyzes it and searches the catalog.

```
User sends image (LINE or API)
    │
    ▼
LineWebhookHandler downloads from LINE Content API
    │
    ▼
GeminiChatbot::chatWithImage(imageData, mimeType, text, history)
    │
    ▼
Gemini analyzes → identifies product → calls search_products()
    │
    ▼
Returns product matches with prices
```

Image placeholder `[ผู้ใช้ส่งรูปภาพ]` is stored in conversation history.

### 6.4 Authorized User System

- `authorized_users` table stores LINE user IDs (substring match via `INSTR`)
- `ConversationManager::isUserAuthorized(userId)` checked per message in `SirichaiLineWebhook`
- `chatbot->setAuthorized(bool)` gates price type access
- Unauthorized: `priceType` always forced to `"c"` in `SirichaiElectricChatbot::executeFunction()`
- Authorized: all rate types allowed (`ss|s|a|b|c|vb|vc|d|e|f`)

### 6.5 Chatbot Pause/Resume (Human Agent Handoff)

Pause commands (exact match, case-insensitive):
- Thai: `ติดต่อพนักงาน`, `คุยกับพนักงาน`, `ขอคุยกับพนักงาน`, `ต้องการคุยกับพนักงาน`
- English: `/human`, `/agent`

Resume commands:
- Thai: `เปิดแชทบอท`, `เปิดบอท`
- English: `/bot`, `/resume`, `/on`, `/chatbot`

Reset command: `/reset` (deactivates message history, bot stays active)

Auto-resume: cron job in `cron/auto-resume-chatbot.php` resumes after `AUTO_RESUME_TIMEOUT_MINUTES`.

---

## 7. Gemini Function Calling

### Available Functions

#### `search_products(criterias[])`
- Search by exact category names from the catalog file
- Max 3 categories per call
- Returns: product name, price, unit — grouped by category
- Critical: category names must include ALL special chars: `{}`, `[]`, `()`

#### `search_product_detail(productName)`
- Get specs: weight, size, thickness, quantity per pack
- Must use EXACT product name from previous `search_products()` result
- Fuzzy matching on backend: splits name into keywords, uses `LIKE %keyword%`
- NEVER say "information not available" without calling this first

#### `generate_quotation(quotaDetail[], priceType)`
- Generate PDF quotation
- `quotaDetail`: array of `{productName, amount}`
- `priceType`: one of `ss|s|a|b|c|vb|vc|d|e|f` (forced to `c` for unauthorized)
- Returns: PDF download URL string

### Chained Call Limit

`handleFunctionCalls()` allows up to **6 additional** chained calls (7 total rounds).
This supports batch quotation with 5 products (5 searches + 1 quotation = 6 calls).

### Retry Logic

`executeWithRetry()` (3 attempts):
1. Attempt 1: normal call
2. Attempt 2+: add `tool_config.function_calling_config.mode = "ANY"` to force function calling (prevents empty STOP from cached catalog)
3. After 3 failures: `chat()` calls `refreshFiles()` and retries once more

---

## 8. LINE Integration

### Webhook Flow

```
LINE server → POST /line-webhook.php
    │
    ▼
SirichaiLineWebhook::run()
    │
    ├─ Verify HMAC-SHA256 signature
    ├─ HTTP 200 immediately (closeConnection)
    ├─ initialize() — boot chatbot + ConversationManager
    │
    └─ handleEvent() per event
            │
            ├─ Text message → handleCommand() → getAIResponse()
            ├─ Image message → download → getAIResponseWithImage()
            ├─ follow event → onFollow()
            └─ join event → onJoin()
```

### Async Processing

`closeConnection()` supports three server types:
1. LiteSpeed: `litespeed_finish_request()`
2. PHP-FPM: `fastcgi_finish_request()`
3. Standard Apache: `Content-Length: 0` + `Connection: close` + `flush()`

Loading animation (60s) is shown only for 1:1 direct chats (`sourceType === 'user'`).

### Message Splitting

Messages are split at 4900 characters (LINE limit is 5000) via `LineWebhookUtils::splitMessage()`.

### Group Chat Support

Bot only responds in groups when directly mentioned (bot user ID detection via `LineWebhookUtils::getBotUserId()` and `shouldRespondToEvent()`).

---

## 9. Admin & Dashboard

### PHP Admin Panel (`/admin/`)

Session-based authentication (bcrypt). Features:
- View paused conversations
- Manually pause/resume chatbot per conversation
- View LINE user display names (via LINE Profile API)
- Monitor chatbot status

Generate password hash:
```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php admin/generate-password-hash.php
```

### React Monitoring Dashboard (`/dashboard/`)

Built with React + TypeScript + Vite + TanStack Query + Tailwind CSS.

Features:
- Real-time conversation grid (recent 6 conversations)
- Date picker to browse conversations by date
- Message thread view per conversation
- Auto-refresh via TanStack Query polling

Build:
```bash
cd dashboard
npm install
npm run build    # outputs to dashboard/dist/
```

API endpoint: `GET /admin/api/monitoring.php` — returns recent conversations with last N messages.

---

## 10. Testing

### Run Tests (Always Sequential)

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-chatbot-without-history.php
sleep 15
/Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-chatbot-with-history.php
sleep 15
/Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-chatbot-batch-price.php
sleep 15
/Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-chatbot-unauthorized.php
```

**IMPORTANT:** Never run in parallel — Gemini rate limit is 1M tokens/minute and tests share the same API key.

### Test Descriptions

#### `test-chatbot-without-history.php`
5 independent questions with no conversation history:
1. Motor current calculation (general electrical engineering)
2. Waterproof/dustproof lamps — multiple brands
3. THW cable specific price
4. THW cable 400m weight calculation
5. IMC conduit straight coupling identification

#### `test-chatbot-with-history.php`
12-turn conversation simulating full customer quotation workflow:
1. Product search: "มีเบรกเกอร์ abb ไหม"
2. Add to list: "เพิ่มรายการ ลูกเซอร์กิตเบรกเกอร์ ... 2 ตัว"
3. Compatibility: "ใช้กับสายไฟไหนได้บ้าง"
4. Add accessory: "เพิ่มรายการ สายไฟ VCT ..."
5. Summary with pricing
6. Quotation (no rate) → expects default `c`
7. Quotation with rate `vb` → expects PDF link
8. New search: "มีสวิตช์ตัดไฟ RCD ไหม"
9. "เพิ่ม รายการแรก 5 ชิ้น" → must NOT trigger quotation
10. "เอา ตัวแรก 2 อัน" → must NOT trigger quotation
11. Quotation with rate `a` → PDF with all accumulated products
12. Color inquiry → AI must NOT fabricate color variants not in data

#### `test-chatbot-batch-price.php`
Batch price workflow (WORKFLOW 1B):
- Multiple products priced sequentially
- All prices shown (not the 3-max rule)
- Quotation offered at the end

#### `test-chatbot-unauthorized.php`
Authorization enforcement:
- Unauthorized user requests a non-`c` rate
- Verifies `priceType` is forced to `c` in the quotation PDF URL

---

## 11. File Cache Management

### Gemini File API Cache (`file-cache.json`)

Only the **product catalog** is uploaded to File API. The system prompt is sent inline.

```json
{
  "catalog-summary": {
    "uri": "https://generativelanguage.googleapis.com/v1beta/files/abc123",
    "name": "files/abc123",
    "uploadedAt": 1705449600,
    "expiresAt": 1705622400
  }
}
```

Cache lifecycle: upload → cached 46h → auto-refresh → Gemini auto-deletes at 48h.

### Managing File Cache

```bash
# List uploaded Gemini files
php cleanup-files.php list

# Delete all Gemini files
php cleanup-files.php delete-all

# Clear local cache only (triggers re-upload on next request)
php cleanup-files.php clear-cache
```

### Product Catalog Cache (`cache/catalog-summary-cache.md`)

The product catalog is fetched from the external API once per 24 hours and cached locally.

```bash
# Force refresh catalog cache
rm cache/catalog-summary-cache.md
```

### Force Refresh Everything

```php
require_once 'vendor/autoload.php';
require_once 'AppConfig.php';
require_once 'services/ProductAPIService.php';
require_once 'chatbot/SirichaiElectricChatbot.php';

$config = AppConfig::getInstance();
$productAPI = new ProductAPIService($config->get('productAPI'));
$chatbot = new SirichaiElectricChatbot($config->get('gemini'), $productAPI);
$chatbot->refreshFiles(); // clears file-cache.json, re-fetches catalog, re-uploads to File API
```

---

## 12. Common Tasks

### Update AI Behavior

1. Edit `system-prompt.txt`
2. Delete `file-cache.json` (it only caches catalog, but safe to delete anyway)
3. Next request will re-upload catalog automatically

Note: system prompt is loaded via `loadSystemPromptText()` → sent inline each request. No File API cache for the prompt itself.

### Add an Authorized User

```bash
mysql -u root -p chatbotdb -e "INSERT INTO authorized_users (user_id) VALUES ('U_LINE_USER_ID');"
```

The `INSTR(?, user_id)` query means the stored value can be a substring of the actual user ID — useful for adding multiple users from the same LINE profile group prefix.

### Add a New Gemini Function

1. Add declaration to `SirichaiElectricChatbot::getFunctionDeclarations()`
2. Add handler to `SirichaiElectricChatbot::executeFunction()`
3. Add logging to `SirichaiElectricChatbot::extractSearchCriteria()`
4. Update `system-prompt.txt` with usage instructions
5. No file refresh needed (system prompt is inline)

### Deploying

```bash
# 1. Run tests
/Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-chatbot-without-history.php

# 2. Backup database
mysqldump -u root -p chatbotdb > backup-$(date +%Y%m%d).sql

# 3. Sync files (rsync/git pull)

# 4. Run any new migrations
mysql -u root -p chatbotdb < migrations/xxx.sql

# 5. Update .env if new variables added

# 6. Clear caches if config changed
rm -f file-cache.json cache/catalog-summary-cache.md

# 7. Verify LINE webhook URL in LINE console (Developers > Messaging API)

# 8. Monitor logs
tail -f logs.log
```

---

## 13. Troubleshooting

### AI returns empty response / no content

**Symptom:** `finishReason: STOP` but no text in response.

**Automatic recovery:** `GeminiChatbot` retries 3x with forced function calling mode, then refreshes catalog and retries once more.

**Manual fix if persisting:**
```bash
rm file-cache.json cache/catalog-summary-cache.md
```

### system-prompt.txt changes not taking effect

The system prompt is sent inline on every request — changes apply immediately. No cache to clear.

If AI behavior hasn't changed, verify the file was saved correctly:
```bash
wc -l system-prompt.txt   # check line count
head -5 system-prompt.txt # verify content
```

### Quotation cut short / batch price stops early

The chained function call limit is 6 additional rounds. If a batch needs more than 6 sequential product searches + 1 quotation, increase `$additionalCallsRemaining` in `GeminiChatbot::handleFunctionCalls()`.

### LINE webhook not responding

1. Check `logs.log` for errors
2. Verify signature: `VERIFY_LINE_SIGNATURE=true` requires correct `LINE_CHANNEL_SECRET`
3. Verify `closeConnection()` is working — check server type (LiteSpeed/FPM/Apache)
4. Check LINE console for webhook delivery errors

### Admin login fails

Generate a new hash:
```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php admin/generate-password-hash.php
```
Update `ADMIN_PASSWORD_HASH` in `.env`.

### Database connection error

Check credentials in `.env`. `AppConfig::validate()` throws on missing `DB_USER` or `DB_NAME`.
```bash
mysql -u $DB_USER -p$DB_PASSWORD $DB_NAME -e "SELECT 1;"
```

---

## 14. Development History

### Version 3.0.0 (March 2026)
- **chatbot-core extracted as Composer library** (`vendor/wittakarn/chatbot-core`)
- `GeminiChatbot` made abstract with 3 overridable hooks
- `LineWebhookHandler` made abstract, handles all LINE plumbing
- `Config` made extensible via `buildConfig()` override
- `AppConfig` extends `Config` with product API and admin sections
- `SirichaiLineWebhook` replaces old `line-webhook.php` monolith
- `SirichaiElectricChatbot` slimmed to 174 lines (was ~800+)
- 4 test scripts covering all workflows

### Version 2.3.0 (February 2026)
- Fuzzy search for `search_product_detail` (partial keyword matching)
- Chained function call limit increased from 2 to 6 (for 5-product batch quotes)
- Empty STOP retry with `mode=ANY` forcing
- System prompt simplified (269 lines → ~90 lines)
- Hybrid File API: system prompt inline, catalog in File API

### Version 2.0.0 (February 2026)
- Admin dashboard (PHP session auth)
- React monitoring dashboard (Vite + TanStack Query)
- Authorized user system (`authorized_users` table)
- `generate_quotation` function with price tier control
- Chatbot pause/resume for human agent handoff
- Image recognition via Gemini vision (`chatWithImage`)

### Version 1.0.0 (January 2026)
- Initial chatbot with `search_products` and `search_product_detail`
- Gemini File API for catalog caching
- LINE webhook integration
- MySQL-backed conversation history
- Repository pattern + PDO

---

**Repository:** sirichaielectric-chatbot
**Library:** wittakarn/chatbot-core (path dependency in composer.json)
