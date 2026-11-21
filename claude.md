# Claude AI Context - Sirichai Electric Chatbot

This file provides context for Claude AI when working on this codebase.

> **For comprehensive documentation:** See [PROJECT.md](PROJECT.md)

## Project Overview

**Name:** Sirichai Electric Chatbot
**Purpose:** AI-powered customer service chatbot for electrical product inquiries
**Tech Stack:** PHP 7.4+, MySQL 5.7+, Google Gemini 3.8 Flash API (thinking level `low`), LINE Messaging API
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
| `services/SearchService.php` | Product search on the **OneElectric** Supabase index (product IDs are shared by both shops): one batch Gemini embedding call (1536 dims) for all queries, then the `search_products_all` RPC per query in parallel. Returns product IDs + names |
| `services/ProductAPIService.php` | `searchProducts()`: IDs from `SearchService`, then Sirichai names/prices from the shop's `products-by-ids.php` (falls back to the keywords, then categories endpoint when the index finds nothing). Also product detail + quotation PDF |
| `index.php` | REST API entry point — routes: `GET /health`, `POST /verify`, `POST /chat` (needs `X-Chat-Token`), `GET /history`, `POST /new-chat` |
| `services/ChatAccess.php` | Signed session token (HMAC) + per-token rate limit + Turnstile `siteverify`. Table `chat_rate_limits` (`migrations/`) |
| `line-webhook.php` | LINE webhook entry — boots `SirichaiLineWebhook()->run()` |
| `system-prompt.txt` | AI behavior instructions — loaded as `systemInstruction` text (NOT File API) |
| `supabase/search_products_all.sql` | The RPC Sirichai calls, stored in the OneElectric Supabase. Same vector + keyword RRF as OneElectric's `search_products`, but ignores sales |

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

### Product Search — One Call, OneElectric Index
- **System prompt** → inline `systemInstruction` text, reloaded every request
- **Products** → `search_products(criterias=[one query per product])`, the customer's own words per item. A batch list is ONE call.
- Pipeline (`ProductAPIService::searchProducts`):
  1. `SearchService::searchMany` — one Gemini `batchEmbedContents` call (`gemini-embedding-001`, `RETRIEVAL_QUERY`, 1536 dims), then `rpc/search_products_all` for every query in parallel (curl_multi) on the **OneElectric** Supabase. Up to 20 `{product_id, name}` per query.
  2. `POST {PRODUCT_BY_IDS_URL}` with `{"product_ids":[...]}` → Sirichai `Name | Price | Unit | Id` lines (discontinued/hidden products dropped).
  3. Output grouped under `## <query>` per item; Gemini picks the matching lines.
  4. If the index finds nothing priced → keywords endpoint → categories endpoint.
- The index is owned by OneElectric (`oneelectric-chatbot/supabase/schema.sql`, nightly ingest there). Sirichai only reads it; products sold only by Sirichai are found through the fallback endpoints.
- Sales are ignored on purpose (`search_products_all`, not OneElectric's sales-filtered `search_products`).

### Function Calling (3 Available Functions)
Gemini two-step flow:
1. AI returns function call request
2. PHP executes → sends result back → AI formats text response

Available functions:
- `search_products(criterias[])` — one query per product, max 10. Returns lines grouped per query.
- `search_product_detail(productName)` — get specs (weight, size, qty/pack) by fuzzy name match
- `generate_quotation(quotaDetail[], priceType)` — generate PDF, forces `priceType=c` if unauthorized

Chained calls: up to **6 additional** rounds. A 5-product batch + quotation now needs 2 calls (was ~11 with the old catalog → products path).

### Gemini Thinking
`GEMINI_THINKING_LEVEL=low` (chatbot-core ≥ v1.3.1). Gemini time was 75–93% of every answer; `low` cut answers from 12–28 s to 8–9 s with all tests passing and harder electrical questions still answered correctly (it can skip extra nuance, e.g. star-delta overload setting). chatbot-core ≥ v1.3.0 is also required for Gemini 3.x `thoughtSignature` on function calls.

### Authorization System
- `authorized_users` DB table — stores authorized LINE user IDs
- `ConversationManager::isUserAuthorized()` checks per message
- Unauthorized users: `priceType` always forced to `"c"` regardless of request
- Rate types: `ss|s|a|b|c|vb|vc|d|e|f` — NOT shown to users

### Retry / Empty STOP Recovery
`GeminiChatbot::executeWithRetry()`:
1. Retry up to 3x with 2s/3s delays
2. On retry 2+: force `tool_config.function_calling_config.mode = "ANY"` to prevent empty STOP
3. After all retries fail: `chat()` calls `refreshFiles()` once more (no-op for this project — catalog is no longer File-API-uploaded)

### LINE Async Processing
- Respond HTTP 200 immediately (`closeConnection()` — supports LiteSpeed, FastCGI, fallback)
- `initialize()` runs after 200 is sent
- Loading animation (60s) for 1:1 chats only
- Push API (not Reply API) — no 60s reply token expiry

### Web Chat Access (Turnstile + cookie)
- `chat.html`/`demo.html` render a Cloudflare Turnstile widget (`interaction-only`), POST the result to `/verify`, get a 30-min HMAC token (`ChatAccess`), and send it as `X-Chat-Token` on every `/chat`. 401 = missing/expired (page re-verifies), 429 = over `CHAT_MAX_REQUESTS_PER_MINUTE` (default 15, per token; separate from `MAX_REQUESTS_PER_MINUTE`).
- The conversation is identified ONLY by the HttpOnly cookie `sirichai_cid` (SameSite=Lax, 7 days). A client-supplied `conversationId` is ignored, so ids can't be guessed to read someone else's chat.
- `GET /history` redraws the chat after a refresh. `POST /new-chat` expires the cookie (history stays in the DB); the next message gets a new id.
- Removed: `POST /search`, `GET`/`DELETE /conversation/:id` (unauthenticated, no callers).
- CORS is same-origin only; `ALLOWED_ORIGINS` (comma-separated) opens it to other sites.
- Env: `TURNSTILE_SECRET`, `CHAT_TOKEN_SECRET` (`openssl rand -hex 32`), `CHAT_MAX_REQUESTS_PER_MINUTE`, `ALLOWED_ORIGINS`. The site key is public, in the two HTML files. Real values go in `.env` (only file loaded), never in the tracked `.env.local` template.
- Turnstile widget hostnames: `assistant.sirichaielectric.com` (the iframe's own origin, not the shop) + `localhost`.

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
GEMINI_MODEL=gemini-3.8-flash
GEMINI_THINKING_LEVEL=low   # chatbot-core >= 1.3.1; minimal|low|medium|high
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

# Supabase product index — the OneElectric project (shared product IDs)
SUPABASE_REST_URL=https://<oneelectric-project>.supabase.co
SUPABASE_KEY=xxx                              # key with RPC access to search_products_all

# Product API
PRODUCT_BY_IDS_URL=https://shop.sirichaielectric.com/services/products-by-ids.php   # prices for index hits
PRODUCT_KEYWORDS_URL=https://shop.sirichaielectric.com/services/products-by-keywords-prompt.php  # fallback 1
PRODUCT_SEARCH_URL=https://shop.sirichaielectric.com/services/products-by-categories-prompt.php
PRODUCT_DETAIL_URL=https://shop.sirichaielectric.com/services/...
QUOTATION_URL=https://shop.sirichaielectric.com/services/...
QUOTATION_TOKEN=xxx                           # shared secret, = QUOTATION_TOKEN in shop configuration config.php
LINE_QUOTATION_NOTIFY_TO=                     # staff LINE group/user ID or 'broadcast' (staff-only OA); quotation with remark → push contact + PDF. Empty = off
LINE_QUOTATION_NOTIFY_TOKEN=                  # sender account's token; empty = LINE_CHANNEL_ACCESS_TOKEN (required for 'broadcast')

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
sleep 15
/Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-chatbot-english.php
```

### Test Coverage

**`test-chatbot-english.php`** — English conversation (3 turns):
1. "hi" → English fallback, no Thai, no security refusal
2. English price question → product links with "Price: X THB/Unit", no Thai template text
3. "Please create a quotation …" → English trigger generates PDF

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
- All products searched in ONE `search_products` call (one criteria item each)
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
4. Update `system-prompt.txt` (reloaded on next request — no cache to clear)

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
| `system-prompt.txt` | AI behavior — edit here; reloaded on next request (no cache to clear) |
| `schema.sql` | DB structure — use `migrations/` for changes |
| `logs.log` | Application error log |

## Common Pitfalls

1. **Batch quotation cut short** — chained call limit is 6; a batch is now one `search_products` call, so this should no longer trigger
2. **Empty STOP from Gemini** — retry with `mode=ANY` handles this automatically
3. **Unauthorized user gets wrong rate** — `priceType` override is in `executeFunction()` in `SirichaiElectricChatbot`
4. **LINE reply timeout** — not applicable, Push API is used (not Reply API)
5. **Config not loading** — `AppConfig::validate()` throws on missing required keys; check `.env`
6. **Search returns wrong/loose matches** — the ranking comes from the OneElectric index (`search_products_all`: embedding quality + name tokens). Check with the OneElectric CLI `php bin/search.php "<query>"`. A product missing from results may not exist in OneElectric's index — the keywords/categories fallback only runs when the index finds nothing.
7. **`HTTP 400 ... missing a thought_signature`** — chatbot-core older than v1.3.0 with a Gemini 3.x model. Run `composer install`.

## Chatbot Behaviors (system-prompt.txt)

- Rate info (ss|s|a|b|c|vb|vc|d|e|f) is NOT shown to users
- Default priceType: `"c"` when no rate specified
- Unauthorized users: always forced to `"c"`
- All prices include VAT
- **WORKFLOW 1B (Batch Price):** One `search_products` call with one item per product, show ALL prices, offer quotation at end: "ต้องการออกใบเสนอราคาไหมคะ?"
- Quotation TRIGGER: message contains "ออกใบเสนอราคา" or "สร้างใบเสนอราคา"
- NEVER auto-generate quotation from price inquiry alone
- Must use EXACT product names from search results for `generate_quotation`
- Customer asks for a quotation instead of answering a set-or-add quantity question → treat "เอา X" as set to X, generate, and state the quantity used

## Debugging

1. Check `logs.log` — all components log with `[ClassName]` prefix
2. Token usage logged per Gemini call — look for `[GeminiChatbot] Token Usage`
3. Function calls logged — look for `[GeminiChatbot] Calling: <function>`
4. Product search: `[ProductAPI] Searching products with criteria:` then `Index search returned` (or `Index search empty — falling back`); `[SearchService]` for embed/Supabase errors

---

**Last Updated:** November 14, 2026
**Version:** 4.0.0 — product search on the OneElectric Supabase index (one call per message), Gemini thinking `low`, chatbot-core 1.3.1

## Guardrails (proof-tested)

Security-relevant rules live in `guardrails/` as **Gates** (Rules + one Check + Proofs), adapted from [redproof](https://github.com/schalermthai/redproof). A passing Check only says the current code is fine; a **RED proof** plants a violation in a throw-away copy, reruns the *same* Check, and requires it to fail on the *targeted* Rule.

```bash
php guardrails/run.php check      # Checks against the real tree (exit 0/1/2)
php guardrails/run.php prove      # prove every Rule can fail; a Rule with no RED proof is UNPROVEN
php guardrails/run.php describe   # list Gates, Rules, Proofs
```

| Gate | Guards |
|------|--------|
| `prompt-injection` | each rule of `services/PromptInjectionDetector.php`, plus no false positives on real queries |
| `input-guard` | 1000-char limit (inclusive boundary) and injection refusal in `services/InputGuard.php` |
| `quotation-price-type` | unauthorized users forced to tier `c` (`services/PriceTypePolicy.php`) |
| `wiring` | chatbot still calls the guard/policy; `system-prompt.txt` keeps its SECURITY block |
| `chat-access` | token verify (tamper/wrong secret/expired/empty secret), per-token rate limit, `/chat` calls `requireChatAccess()` (`services/ChatAccess.php`, `index.php`) |

Exit codes: `0` ok, `1` breach / proof failed, `2` refused (Check inspected nothing, unknown gate, unhealthy baseline).

Conventions: detector rules are one line each with a stable id (`sub/…`, `rx/…`) because proofs disable a rule by rewriting its line. Adding a rule means adding its payload and a RED proof in `guardrails/gates/prompt-injection.php`. Run `prove` before merging changes to the guarded files.
