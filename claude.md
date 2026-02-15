# Claude AI Context - Sirichai Electric Chatbot

This file provides context for Claude AI when working on this codebase.

## Project Overview

**Name:** Sirichai Electric Chatbot
**Purpose:** AI-powered customer service chatbot for electrical product inquiries
**Tech Stack:** PHP 5.6+, MySQL 5.7+, Google Gemini 2.5 Flash API, LINE Messaging API
**Architecture:** Repository Pattern, File API Integration, Database-backed conversations

## Key Components

### 1. Core Files
- `SirichaiElectricChatbot.php` - Main chatbot logic with Gemini API integration
- `ConversationManager.php` - Service layer for conversation management
- `DatabaseManager.php` - Singleton PDO wrapper
- `ProductAPIService.php` - Product catalog and search API integration
- `GeminiFileManager.php` - Gemini File API for token optimization

### 2. Repository Layer
- `repository/BaseRepository.php` - Abstract base with common DB operations
- `repository/ConversationRepository.php` - Conversation table operations
- `repository/MessageRepository.php` - Message table operations

### 3. Integration Points
- `index.php` - REST API entry point
- `line-webhook.php` - LINE Official Account webhook handler
- `config.php` - Configuration singleton (.env loader)
- `system-prompt.txt` - AI behavior instructions (uploaded to Gemini File API)

### 4. Testing
- `test-chatbot.php` - Integration test for multi-turn conversations
- `test-file-api.php` - File API integration test

## Database Schema

### `conversations` Table
```sql
- conversation_id (VARCHAR 100, UNIQUE) - Primary identifier
- platform (VARCHAR 20) - 'api' or 'line'
- user_id (VARCHAR 100) - LINE user ID if applicable
- max_messages_limit (INT) - Default 20
- is_chatbot_active (TINYINT) - 1=active, 0=paused for human agent
- paused_at (TIMESTAMP) - When chatbot was paused
- created_at, last_activity (TIMESTAMP)
```

### `messages` Table
```sql
- id (INT AUTO_INCREMENT)
- conversation_id (VARCHAR 100, FK to conversations)
- role (ENUM 'user', 'assistant')
- content (TEXT) - Message content
- tokens_used (INT) - Gemini API tokens
- sequence_number (INT) - Message order
- search_criteria (TEXT) - JSON of search params used
- timestamp (TIMESTAMP)
```

## Key Architecture Decisions

### File API Integration
- System prompt and product catalog uploaded as files to Gemini File API
- Reduces token usage by 95%+ (from ~3000 tokens to ~10-50 tokens)
- Files cached in `file-cache.json` for 46 hours (auto-refresh before 48h expiry)
- File API is FREE (no storage/retrieval charges)

### Repository Pattern
- All database operations use PDO prepared statements (SQL injection prevention)
- Clean separation: Controllers → Service Layer → Repository Layer → Database
- Transactions supported via BaseRepository
- Centralized query logic for maintainability

### Function Calling
Gemini uses two-step conversational flow:
1. **Step 1:** AI decides to call a function → returns function call request
2. **Step 2:** PHP executes function → sends results back → AI formats response

**Available Functions:**
- `search_products(criterias[])` - Search products by category names
- `search_product_detail(productName)` - Get detailed specs (weight, size, qty/pack)

### LINE Integration
- Async processing (responds HTTP 200 immediately, processes in background)
- Loading animation (60s) during AI processing
- Push API (not Reply API) for reliability with long processing times
- Image message support (downloads from LINE Content API, analyzes with Gemini vision)
- Signature verification in production

### Chatbot Pause/Resume
- Users can request human agent with "ติดต่อพนักงาน" or "/human"
- Agents respond via LINE Official Account Manager
- Resume with "/bot" or "เปิดแชทบอท"
- Auto-resume after configurable timeout (prevents forgotten paused chats)

## Critical Issues (INVESTIGATION_SUMMARY.md)

### Fixed Issue
✅ **First Query Empty Response** - Simplified system prompt from 269 lines to 72 lines

### Current Issue
❌ **Follow-up Query Empty Response**

**Problem:** AI returns empty response with `finishReason: STOP` on follow-up questions

**Root Cause:** Product names lost during formatting
1. `search_products()` returns exact DB name: `รางวายเวย์ 2"x3" (50x75) ยาว 2.4เมตร สีขาว KWSS2038-10 KJL`
2. AI formats for humans: `ทางเรามีรางวายเวย์ KWSS2038-10 KJL ขนาด...` (exact name lost)
3. Follow-up needs exact name for `search_product_detail()` but only has formatted sentence
4. Model gets confused → empty response

**Proposed Solutions:**
1. **Option 1 (Recommended):** Store product names in `messages.product_names` JSON column
2. **Option 2:** Include `[PRODUCT:name]` markers in responses
3. **Option 3 (Simplest):** Update system prompt to always search first, even for follow-ups

## Environment Setup

### Required .env Variables
```bash
# Gemini API
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

# Product API
CATALOG_SUMMARY_URL=https://shop.sirichaielectric.com/services/category-products-prompt.php
PRODUCT_SEARCH_URL=https://shop.sirichaielectric.com/services/products-by-categories-prompt.php
WEBSITE_URL=https://shop.sirichaielectric.com/

# Conversation
MAX_MESSAGES_PER_CONVERSATION=20
AUTO_RESUME_TIMEOUT_MINUTES=30
```

### PHP Version
- **Development:** `/Applications/MAMP/bin/php/php7.4.33/bin/php`
- **Compatibility:** PHP 5.6+ (no type hints, uses docblock annotations)

## Common Tasks

### Running Tests
```bash
# Integration test (clears DB, tests 2 questions)
./test-chatbot.php

# File API test
php test-file-api.php
```

### Database Operations
```bash
# Clear all conversations
mysql -u root -p chatbotdb -e "DELETE FROM conversations;"

# View recent messages
mysql -u root -p chatbotdb -e "SELECT * FROM messages ORDER BY timestamp DESC LIMIT 10;"

# Token usage stats
mysql -u root -p chatbotdb -e "SELECT platform, SUM(tokens_used) FROM conversations c JOIN messages m ON c.conversation_id = m.conversation_id GROUP BY platform;"
```

### File Cache Management
```bash
# List uploaded files
php cleanup-files.php list

# Delete all files
php cleanup-files.php delete-all

# Clear local cache
php cleanup-files.php clear-cache
```

### Force Refresh Files
```php
require_once 'SirichaiElectricChatbot.php';
$chatbot = new SirichaiElectricChatbot($geminiConfig, $productAPI);
$chatbot->refreshFiles(); // Re-uploads system-prompt.txt and catalog
```

## Code Patterns

### Adding a New Function
1. Add function declaration in `SirichaiElectricChatbot::callGeminiWithFunctions()`
2. Add execution handler in `SirichaiElectricChatbot::executeFunction()`
3. Add logging support in `SirichaiElectricChatbot::extractSearchCriteria()`
4. Update `system-prompt.txt` with usage instructions
5. Call `$chatbot->refreshFiles()` to upload new prompt

### Adding a New Repository Method
```php
// In repository class (extends BaseRepository)
public function findByCustomField($value) {
    $sql = "SELECT * FROM table_name WHERE custom_field = ?";
    return $this->fetchAll($sql, array($value));
}
```

### Adding a New Conversation Manager Method
```php
// In ConversationManager.php
public function getCustomData($conversationId) {
    try {
        return $this->conversationRepository->findByCustomField($conversationId);
    } catch (PDOException $e) {
        error_log('[ConversationManager] getCustomData failed: ' . $e->getMessage());
        return null;
    }
}
```

## Important Files to Understand

### 1. `system-prompt.txt`
- Defines AI behavior, role, and workflow
- Uploaded to Gemini File API (cached for 46 hours)
- **CRITICAL:** After editing, must run `$chatbot->refreshFiles()`
- Simplified to 72 lines after debugging empty response issue

### 2. `file-cache.json`
- Stores Gemini File API URIs for system prompt and catalog
- Format: `{fileType: {uri, name, uploadedAt, expiresAt}}`
- Auto-refreshed at 46 hours
- **Should be in .gitignore** (temporary cache)

### 3. `schema.sql`
- Database structure definition
- Run migrations in `migrations/` folder for schema updates
- Always use migrations instead of direct schema edits

### 4. `INVESTIGATION_SUMMARY.md`
- Documents the empty response bug investigation
- Contains detailed root cause analysis
- Includes 3 proposed solutions with pros/cons
- Reference this when fixing the follow-up question issue

## Best Practices

### Security
- Always use prepared statements (never concatenate SQL)
- Verify LINE signatures in production
- Keep `.env` in `.gitignore`
- No sensitive data in code/logs

### Performance
- Use File API caching (don't upload files on every request)
- Trim conversation history (20 message limit recommended)
- Use database indexes on frequently queried columns
- Close connections properly (DatabaseManager singleton handles this)

### Maintainability
- Follow repository pattern for all DB operations
- Log errors with context: `error_log('[Component] Message: ' . $details)`
- Update `PROJECT.md` when making architectural changes
- Write tests for critical flows

### Testing
- Run `test-chatbot.php` after system prompt changes
- Test LINE integration with test account before production
- Verify database queries return expected results
- Check logs after each test run

## Common Pitfalls

### 1. Forgetting to Refresh Files
**Issue:** Updated `system-prompt.txt` but AI still uses old behavior
**Fix:** Run `$chatbot->refreshFiles()` or delete `file-cache.json`

### 2. SQL Without Prepared Statements
**Issue:** Security vulnerability
**Fix:** Always use `?` placeholders with parameter binding

### 3. LINE Reply Token Expiry
**Issue:** Reply API fails after 60 seconds
**Fix:** Use Push API for async processing (already implemented)

### 4. Empty Response with STOP
**Issue:** AI returns `finishReason: STOP` but no content
**Causes:**
- System prompt too complex/conflicting (fixed by simplification)
- Product name lost in conversation (current issue - see INVESTIGATION_SUMMARY.md)
- Model confusion from unclear instructions

### 5. File Cache Not Expiring
**Issue:** Files older than 48 hours but still in cache
**Fix:** File API auto-deletes, cache refresh logic runs at 46 hours

## Development Workflow

### Making Changes
1. Read `PROJECT.md` for architecture overview
2. Check `INVESTIGATION_SUMMARY.md` for known issues
3. Write code following existing patterns
4. Run integration tests
5. Update documentation
6. Test in LINE test account (if LINE-related)
7. Deploy

### Debugging
1. Check `logs.log` for error details
2. Run SQL queries to verify data state
3. Use `test-chatbot.php` to reproduce issues
4. Add detailed logging with `error_log()`
5. Verify file cache status with `cleanup-files.php list`

### Deploying
1. Run tests: `./test-chatbot.php`
2. Backup database: `mysqldump chatbotdb > backup.sql`
3. Update `.env` if config changed
4. Run migrations if schema changed
5. Verify LINE webhook URL in LINE console
6. Monitor logs after deployment

## Reference Links

- **PROJECT.md** - Comprehensive documentation (2000+ lines)
- **INVESTIGATION_SUMMARY.md** - Empty response bug analysis
- **schema.sql** - Database structure
- **Gemini API Docs:** https://ai.google.dev/docs
- **LINE Messaging API Docs:** https://developers.line.biz/en/docs/messaging-api/

## Key Metrics

- **Token Reduction:** 95%+ via File API
- **Message Limit:** 20 messages per conversation
- **File Cache TTL:** 46 hours (refreshes before 48h expiry)
- **Database:** chatbotdb (MySQL 5.7+)
- **Platform Support:** LINE Official Account + REST API
- **Language Support:** Thai + English (auto-detected)

---

**Last Updated:** February 15, 2026
**Version:** 2.2.0
