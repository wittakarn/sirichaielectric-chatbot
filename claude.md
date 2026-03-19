# Claude AI Context - Sirichai Electric Chatbot

This file provides context for Claude AI when working on this codebase.

> **For comprehensive documentation:** See [PROJECT.md](PROJECT.md)

## Project Overview

**Name:** Sirichai Electric Chatbot
**Purpose:** AI-powered customer service chatbot for electrical product inquiries
**Tech Stack:** PHP 7.4+, MySQL 5.7+, Google Gemini 2.5 Flash API, LINE Messaging API
**Architecture:** chatbot-core library (Composer package) + Sirichai application layer

## Architecture: Two-Layer Design

The project is split into two distinct layers:

### Layer 1: `wittakarn/chatbot-core` (Composer library)
Located at `vendor/wittakarn/chatbot-core/src/`:

| File | Role |
|------|------|
| `GeminiChatbot.php` | Abstract base — handles all Gemini API calls, function-call loops (up to 6 chained calls), retry logic (3x), File API upload/cache, token tracking |
| `LineWebhookHandler.php` | Abstract base — handles LINE signature verification, HTTP 200 async, pause/resume commands, message splitting (4900 char), push API dispatch |
| `ConversationManager.php` | Conversation state, history trimming, pause/resume, authorized user checks |
| `GeminiFileManager.php` | Uploads catalog to Gemini File API, caches URIs in `file-cache.json` for 46h |
| `Config.php` | Singleton `.env` loader, extensible via `buildConfig()` override |
| `DatabaseManager.php` | Singleton PDO wrapper |
| `Repository/BaseRepository.php` | Abstract PDO helpers (fetchAll, fetchOne, fetchColumn, transaction support) |
| `Repository/ConversationRepository.php` | conversations table CRUD + pause/resume/auto-resume |
| `Repository/MessageRepository.php` | messages table CRUD + history/token queries |
| `Repository/AuthorizedUserRepository.php` | authorized_users table — checks `INSTR(?, user_id)` |
| `Utils/LineWebhookUtils.php` | Static helpers: signature verify, push message, loading animation, message split, bot mention detection |

### Layer 2: Sirichai Application (project root)

| File | Role |
|------|------|
| `chatbot/SirichaiElectricChatbot.php` | Extends `GeminiChatbot` — implements 3 functions: `search_products`, `search_product_detail`, `generate_quotation`. Forces `priceType=c` for unauthorized users |
| `SirichaiLineWebhook.php` | Extends `LineWebhookHandler` — wires chatbot + ConversationManager, checks authorization per user |
| `AppConfig.php` | Extends `Config` — adds `productAPI`, `website`, `rateLimit`, `admin` config sections |
| `services/ProductAPIService.php` | HTTP client for 4 external APIs: unique keyword index (24h cache), product search, product detail, quotation PDF |
| `index.php` | REST API entry point — routes: `GET /health`, `POST /chat`, `GET /conversation/:id`, `DELETE /conversation/:id` |
| `line-webhook.php` | LINE webhook entry — boots `SirichaiLineWebhook()->run()` |
| `system-prompt.txt` | AI behavior instructions — loaded as `systemInstruction` text (NOT File API) |

### Admin & Dashboard

| File | Role |
|------|------|
| `admin/` | PHP session-auth admin UI — view/pause/resume conversations, LINE profile lookup |
| `admin/api/monitoring.php` | REST API for React dashboard |
| `controllers/DashboardController.php` | Controller layer for monitoring endpoints |
| `services/DashboardService.php` | Aggregates conversation + message data for dashboard |
| `dashboard/` | React + TypeScript + Vite + TanStack Query — real-time monitoring UI |
| `services/LineProfileService.php` | Fetches LINE display name/picture for admin dashboard |
| `services/CacheClearService.php` | HTTP endpoint to clear catalog cache |

## Key Architecture Decisions

### Hybrid File API Approach
- **System prompt** → inline `systemInstruction` text (~5KB, direct)
- **Product keyword index** → Gemini File API upload (~30KB, cached 46h in `file-cache.json`)
- `fetchCatalogSummary()` in `SirichaiElectricChatbot` prepends a 3-line header to the raw keyword list before upload so Gemini understands it is reference data, not a user message
- Result: lightweight context, fast responses, server-side caching

### Function Calling (3 Available Functions)
Gemini two-step flow:
1. AI returns function call request
2. PHP executes → sends result back → AI formats text response

Available functions:
- `search_products(criterias[])` — search by individual keywords from the keyword index (max 3 terms)
- `search_product_detail(productName)` — get specs (weight, size, qty/pack) by fuzzy name match
- `generate_quotation(quotaDetail[], priceType)` — generate PDF, forces `priceType=c` if unauthorized

Chained calls: up to **6 additional** rounds (supports 5-product batch quotation).

### Authorization System
- `authorized_users` DB table — stores authorized LINE user IDs
- `ConversationManager::isUserAuthorized()` checks per message
- Unauthorized users: `priceType` always forced to `"c"` regardless of request
- Rate types: `ss|s|a|b|c|vb|vc|d|e|f` — NOT shown to users

### Retry / Empty STOP Recovery
`GeminiChatbot::executeWithRetry()`:
1. Retry up to 3x with 2s/3s delays
2. On retry 2+: force `tool_config.function_calling_config.mode = "ANY"` to prevent empty STOP
3. After all retries fail: `chat()` calls `refreshFiles()` (force re-upload catalog) and retries once more

### LINE Async Processing
- Respond HTTP 200 immediately (`closeConnection()` — supports LiteSpeed, FastCGI, fallback)
- `initialize()` runs after 200 is sent
- Loading animation (60s) for 1:1 chats only
- Push API (not Reply API) — no 60s reply token expiry

### Conversation ID Format
- API conversations: `conv_{timestamp}_{9chars}`
- LINE conversations: `line_{userId}` (user-scoped, persistent across sessions)

## Database Schema

### `conversations`
```sql
conversation_id VARCHAR(100) UNIQUE
platform        VARCHAR(20)      -- 'api' or 'line'
user_id         VARCHAR(100)     -- LINE user ID
max_messages_limit INT           -- default 20
is_chatbot_active  TINYINT       -- 1=active, 0=paused
paused_at       TIMESTAMP
created_at, last_activity TIMESTAMP
```

### `messages`
```sql
id              INT AUTO_INCREMENT
conversation_id VARCHAR(100) FK
role            ENUM('user','assistant')
content         TEXT
tokens_used     INT
sequence_number INT
search_criteria TEXT             -- JSON of function call args
is_active       TINYINT          -- soft delete flag
timestamp       TIMESTAMP
```

### `authorized_users`
```sql
user_id   VARCHAR(100)           -- LINE user ID substring match
```

## Environment Variables

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

# Product API (all 4 required)
UNIQUE_CATALOG_KEYWORD_URL=https://shop.sirichaielectric.com/services/...
PRODUCT_SEARCH_URL=https://shop.sirichaielectric.com/services/products-by-categories-prompt.php
PRODUCT_DETAIL_URL=https://shop.sirichaielectric.com/services/...
QUOTATION_URL=https://shop.sirichaielectric.com/services/...

WEBSITE_URL=https://shop.sirichaielectric.com/

# Conversation
MAX_MESSAGES_PER_CONVERSATION=20
AUTO_RESUME_TIMEOUT_MINUTES=30
MAX_REQUESTS_PER_MINUTE=15
API_BASE_PATH=

# Admin
ADMIN_USERNAME=xxx
ADMIN_PASSWORD_HASH=xxx   # bcrypt hash, generate with admin/generate-password-hash.php
```

## Running Tests

Run SEQUENTIALLY — never in parallel (Gemini rate limits):

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-chatbot-without-history.php
sleep 15
/Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-chatbot-with-history.php
sleep 15
/Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-chatbot-batch-price.php
sleep 15
/Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-chatbot-unauthorized.php
```

### Test Coverage

**`test-chatbot-without-history.php`** — 5 independent questions (no history):
1. Motor current calculation (general electrical engineering)
2. Waterproof/dustproof lamps — multiple brands
3. THW cable specific price
4. THW cable weight for 400m
5. IMC conduit straight coupling identification

**`test-chatbot-with-history.php`** — 12-turn quotation workflow:
1. Product search (ABB circuit breaker)
2. Add product with quantity
3. Wire compatibility question
4. Add accessory with quantity
5. Summary with pricing
6. Quotation without rate (expects default `c`)
7. Quotation with rate `vb` (expects PDF link)
8. New product search (RCD)
9. "เพิ่ม X ชิ้น" — must NOT trigger quotation
10. "เอา X อัน" — must NOT trigger quotation
11. Quotation with rate `a` (all accumulated products)
12. Color inquiry — AI must NOT fabricate variants not in data

**`test-chatbot-batch-price.php`** — Batch price workflow (WORKFLOW 1B):
- Multiple products searched sequentially
- All prices shown (not 3-max rule)
- Quotation offered at end

**`test-chatbot-unauthorized.php`** — Authorization enforcement:
- Unauthorized user requests non-`c` rate
- Expects `priceType` forced to `c`

## Code Patterns

### Adding a New Gemini Function
1. Add declaration in `SirichaiElectricChatbot::getFunctionDeclarations()`
2. Add handler in `SirichaiElectricChatbot::executeFunction()`
3. Add logging in `SirichaiElectricChatbot::extractSearchCriteria()`
4. Update `system-prompt.txt`
5. Delete `file-cache.json` or call `$chatbot->refreshFiles()`

### Extending chatbot-core for a New Project
```php
// 1. Subclass GeminiChatbot
class MyChatbot extends GeminiChatbot {
    protected function getFunctionDeclarations(): array { ... }
    protected function executeFunction(string $name, array $args): string { ... }
    protected function extractSearchCriteria(string $name, array $args): ?string { ... }
}

// 2. Subclass LineWebhookHandler
class MyWebhook extends LineWebhookHandler {
    protected function initialize(): void { /* boot chatbot + conversationManager */ }
    protected function getAIResponse(string $text, string $convId, string $userId): string { ... }
    protected function getAIResponseWithImage(...): string { ... }
}

// 3. Entry point
(new MyWebhook())->run();
```

## Important Files

| File | Notes |
|------|-------|
| `system-prompt.txt` | AI behavior — edit here, then delete `file-cache.json` to apply |
| `file-cache.json` | Gemini File API URI cache — in `.gitignore`, auto-refreshes at 46h |
| `schema.sql` | DB structure — use `migrations/` for changes |
| `cache/unique-keyword-cache.md` | Product keyword index cache (24h) — delete to force refresh |
| `logs.log` | Application error log |

## Common Pitfalls

1. **system-prompt.txt not applied** — delete `file-cache.json` or call `$chatbot->refreshFiles()`
2. **Batch quotation cut short** — chained call limit is 6 (was 2, increased for 5-product batches)
3. **Empty STOP from Gemini** — retry with `mode=ANY` + catalog refresh handles this automatically
4. **Unauthorized user gets wrong rate** — `priceType` override is in `executeFunction()` in `SirichaiElectricChatbot`
5. **LINE reply timeout** — not applicable, Push API is used (not Reply API)
6. **Config not loading** — `AppConfig::validate()` throws on missing required keys; check `.env`
7. **AI ignores user question / gives short non-answer** — the keyword index file must include a header identifying it as reference data. `fetchCatalogSummary()` prepends the header; if the header is missing, Gemini treats the raw keyword list as a user message and waits for a "real" question

## Chatbot Behaviors (system-prompt.txt)

- Rate info (ss|s|a|b|c|vb|vc|d|e|f) is NOT shown to users
- Default priceType: `"c"` when no rate specified
- Unauthorized users: always forced to `"c"`
- All prices include VAT
- **WORKFLOW 1B (Batch Price):** One search per product sequentially, show ALL prices, offer quotation at end: "ต้องการออกใบเสนอราคาไหมคะ?"
- Quotation TRIGGER: message contains "ออกใบเสนอราคา" or "สร้างใบเสนอราคา"
- NEVER auto-generate quotation from price inquiry alone
- Must use EXACT product names from search results for `generate_quotation`

## Debugging

1. Check `logs.log` — all components log with `[ClassName]` prefix
2. Token usage logged per Gemini call — look for `[GeminiChatbot] Token Usage`
3. Function calls logged — look for `[GeminiChatbot] AI decided to call function:`
4. File cache status: check `file-cache.json` or search logs for `[GeminiChatbot] === File API Context Ready ===`
5. Catalog cache: check `cache/unique-keyword-cache.md` modification time

---

**Last Updated:** March 20, 2026
**Version:** 3.1.0 — unique keyword index for product search + keyword file header fix
