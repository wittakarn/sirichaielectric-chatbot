# LINE Quoted Image Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A LINE user replies to an earlier image with `zx <question>` and the bot answers using that image.

**Architecture:** `chatbot-core`'s `LineWebhookHandler` reads `message.quotedMessageId` on text events, downloads that message's content from LINE, and if it is an image routes to `getAIResponseWithImage` with the user's text; any failure falls back to the existing text path. No storage. The Sirichai app only adjusts history text, the system prompt and the library version.

**Tech Stack:** PHP 7.4 (`/Applications/MAMP/bin/php/php7.4.33/bin/php`), Composer, LINE Messaging API.

**Spec:** `docs/superpowers/specs/2026-10-02-line-quoted-image-design.md`

## Global Constraints

- PHP >= 7.4 syntax only (typed properties OK, no `match`, no arrow-fn-only features beyond 7.4, no `str_contains`).
- `chatbot-core` has no Composer dependencies and no test framework: tests are plain PHP scripts using a tiny `check()` helper.
- `downloadLineContent($messageId, $accessToken)` existing callers must keep working (new param optional, by-reference).
- Download failure never surfaces an error to the user; fall back to text path silently.
- Only `image/*` content is passed to Gemini.
- Tests that call Gemini run sequentially (not relevant to new tests, which stub the AI).

## Review Focus

- Quoted message is text/sticker (download fails, 404) -> normal text answer, no error. (Task 2 test)
- Quoted message is video/audio/file (download succeeds, MIME not `image/*`) -> text path, bytes not sent to Gemini. (Task 2 test)
- Download throws/returns `false` for expired content -> text path. (Task 2 test)
- `quotedMessageId` absent or empty string -> no download attempt. (Task 2 test)
- Content-Type with parameters (`image/png; charset=binary`) -> MIME normalised to `image/png`. (Task 1 test)

## File Structure

- `chatbot-core/src/Utils/LineWebhookUtils.php`: `downloadLineContent` returns MIME via optional by-ref param.
- `chatbot-core/src/LineWebhookHandler.php`: new protected seam `downloadContent()`; quoted-image routing in `handleEvent`; real MIME in image branch.
- `chatbot-core/tests/test-quoted-image.php`: new script.
- `sirichaielectric-chatbot/SirichaiLineWebhook.php`: history text.
- `sirichaielectric-chatbot/system-prompt.txt`: unseen-image rule.
- `sirichaielectric-chatbot/composer.json` / `composer.lock`: version bump.

(`chatbot-core` = `/Users/wittakarnkeeratichayakorn/Sites/chatbot-core`)

---

### Task 1: Return MIME type from `downloadLineContent`

**Files:**
- Modify: `chatbot-core/src/Utils/LineWebhookUtils.php` (`downloadLineContent`, ~line 251)
- Test: `chatbot-core/tests/test-quoted-image.php` (created here, extended in Task 2)

**Interfaces:**
- Produces: `LineWebhookUtils::normalizeMime(?string $contentType): string` (strips `; ...` params, lowercases, returns `''` for null) and `downloadLineContent(string $messageId, string $accessToken, &$mimeType = null)` which sets `$mimeType = normalizeMime(CURLINFO_CONTENT_TYPE)`.

- [ ] **Step 1: Write the failing test** — create `chatbot-core/tests/test-quoted-image.php`:

```php
<?php
spl_autoload_register(function ($c) {
    if (strpos($c, 'ChatbotCore\\') !== 0) return;
    $f = __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, 12)) . '.php';
    if (is_file($f)) require $f;
});

use ChatbotCore\Utils\LineWebhookUtils;

$failures = 0;
function check(string $name, $actual, $expected): void {
    global $failures;
    if ($actual === $expected) { echo "PASS $name\n"; return; }
    $failures++;
    echo "FAIL $name: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
}

check('mime strips params', LineWebhookUtils::normalizeMime('image/png; charset=binary'), 'image/png');
check('mime lowercases',    LineWebhookUtils::normalizeMime('IMAGE/JPEG'), 'image/jpeg');
check('mime null',          LineWebhookUtils::normalizeMime(null), '');

// --- Task 2 tests are appended below this line ---

exit($failures ? 1 : 0);
```

(`chatbot-core` has no `vendor/`, so the script carries its own PSR-4 loader.)

- [ ] **Step 2: Run, verify FAIL**

Run: `cd /Users/wittakarnkeeratichayakorn/Sites/chatbot-core && /Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-quoted-image.php`
Expected: fatal "Call to undefined method ... normalizeMime".

- [ ] **Step 3: Implement** in `LineWebhookUtils.php`:

```php
    public static function normalizeMime(?string $contentType): string {
        if ($contentType === null) {
            return '';
        }
        return strtolower(trim(explode(';', $contentType)[0]));
    }
```

and change `downloadLineContent` to:

```php
    public static function downloadLineContent(string $messageId, string $accessToken, &$mimeType = null) {
```

After `curl_exec`, before `curl_close($ch)`, add:

```php
        $mimeType = self::normalizeMime(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: null);
```

Update the docblock: `@param string|null $mimeType Set to the response MIME type (e.g. 'image/jpeg')`.

- [ ] **Step 4: Run, verify PASS** (same command; three PASS lines, exit 0).

- [ ] **Step 5: Commit** (in chatbot-core)

```bash
git add src/Utils/LineWebhookUtils.php tests/test-quoted-image.php
git commit -m "feat: downloadLineContent returns MIME type"
```

---

### Task 2: Route quoted images in `LineWebhookHandler`

**Files:**
- Modify: `chatbot-core/src/LineWebhookHandler.php` (`handleEvent`, image branch ~line 299-312, text branch ~line 314-322; add seam method)
- Test: `chatbot-core/tests/test-quoted-image.php`

**Interfaces:**
- Consumes: `LineWebhookUtils::downloadLineContent(..., &$mimeType)` from Task 1.
- Produces: `protected function downloadContent(string $messageId, &$mimeType = null)` on `LineWebhookHandler` (test seam, wraps the static call with `$this->channelAccessToken`).

- [ ] **Step 1: Write failing tests** — append to the test script above the `exit` line:

```php
use ChatbotCore\LineWebhookHandler;

class TestHandler extends LineWebhookHandler {
    public $calls = [];          // ['text'|'image', ...]
    public $downloads = [];      // messageId => [bytes|false, mime]
    public $downloadAsked = [];
    public function __construct() { $this->channelAccessToken = 'test'; }
    protected function downloadContent(string $messageId, &$mimeType = null) {
        $this->downloadAsked[] = $messageId;
        list($bytes, $mimeType) = $this->downloads[$messageId] ?? [false, ''];
        return $bytes;
    }
    protected function getAIResponse(string $text, string $c, string $u): string { $this->calls[] = ['text', $text]; return 'ok'; }
    protected function getAIResponseWithImage(string $d, string $m, string $t, string $c, string $u): string { $this->calls[] = ['image', $m, $t]; return 'ok'; }
    public function fire(array $event) { $this->handleEvent($event, 'Ubot'); }
}

function groupText(string $text, ?string $quoted): array {
    $m = ['type' => 'text', 'id' => 'm1', 'text' => $text];
    if ($quoted !== null) $m['quotedMessageId'] = $quoted;
    return ['type' => 'message', 'replyToken' => 'r', 'source' => ['type' => 'group', 'groupId' => 'G1', 'userId' => 'U1'], 'message' => $m];
}

$h = new TestHandler(); $h->downloads['img1'] = ['BYTES', 'image/png'];
$h->fire(groupText('zx what is this?', 'img1'));
check('quoted image -> image path', $h->calls, [['image', 'image/png', 'what is this?']]);

$h = new TestHandler(); // quoted text: download fails
$h->fire(groupText('zx hello', 'txt1'));
check('quoted text -> text path', $h->calls, [['text', 'hello']]);

$h = new TestHandler(); $h->downloads['vid1'] = ['VIDEO', 'video/mp4'];
$h->fire(groupText('zx hello', 'vid1'));
check('quoted video -> text path', $h->calls, [['text', 'hello']]);

$h = new TestHandler();
$h->fire(groupText('zx hello', null));
check('no quote -> no download', $h->downloadAsked, []);

$h = new TestHandler();
$h->fire(groupText('zx hello', ''));
check('empty quote id -> no download', $h->downloadAsked, []);
```

(Push calls inside `handleEvent` hit LINE with a fake token and fail harmlessly; the test only inspects `calls`.)

- [ ] **Step 2: Run, verify FAIL** — `php tests/test-quoted-image.php`; the quoted-image test fails (calls `text`, not `image`).

- [ ] **Step 3: Implement** in `LineWebhookHandler.php`.

Add the seam (near other protected helpers):

```php
    /**
     * Download message content from LINE. Overridable seam for tests.
     *
     * @return string|false
     */
    protected function downloadContent(string $messageId, &$mimeType = null) {
        return LineWebhookUtils::downloadLineContent($messageId, $this->channelAccessToken, $mimeType);
    }
```

In the image branch replace the download + AI call with:

```php
            $imageData = $this->downloadContent($messageId, $mimeType);
            ...(keep the existing failure handling)...
            $mimeType  = strpos((string)$mimeType, 'image/') === 0 ? $mimeType : 'image/jpeg';
            $aiResponse = $this->getAIResponseWithImage($imageData, $mimeType, '', $conversationId, $userId);
```

In the text `else` branch replace the two lines after the empty check with:

```php
            $cleanedMessage = LineWebhookUtils::removeZxPrefix($messageText);

            // Reply/quote on an earlier image: fetch it from LINE and answer with it.
            // Any failure (quoted text, expired content, non-image) falls through to text-only.
            $quotedId = isset($event['message']['quotedMessageId']) ? $event['message']['quotedMessageId'] : '';
            if ($quotedId !== '') {
                $quotedBytes = $this->downloadContent($quotedId, $quotedMime);
                if ($quotedBytes !== false && strpos((string)$quotedMime, 'image/') === 0) {
                    $aiResponse = $this->getAIResponseWithImage($quotedBytes, $quotedMime, $cleanedMessage, $conversationId, $userId);
                }
            }
            if ($aiResponse === null) {
                $aiResponse = $this->getAIResponse($cleanedMessage, $conversationId, $userId);
            }
```

(Not gated on source type: quoting an image in 1:1 also works; harmless addition to the spec's 1:1 rule.)

- [ ] **Step 4: Run, verify PASS** — all checks PASS, exit 0. Also `php -l src/LineWebhookHandler.php`.

- [ ] **Step 5: Commit and release**

```bash
git add src tests
git commit -m "feat: answer zx replies to earlier images using quotedMessageId"
```
Then push and release per `.github/workflows/release.yml` (version bump) so the Sirichai app can pull it. Ask the user before pushing/tagging.

---

### Task 3: Sirichai app wiring

**Files:**
- Modify: `SirichaiLineWebhook.php:67-` (`getAIResponseWithImage`)
- Modify: `system-prompt.txt`
- Modify: `composer.json`, `composer.lock` (via `composer update wittakarn/chatbot-core`)

**Interfaces:**
- Consumes: new `chatbot-core` release from Task 2 (`getAIResponseWithImage` now may receive non-empty `$text`).

- [ ] **Step 1: Store meaningful history** — in `getAIResponseWithImage` replace the placeholder:

```php
        $placeholder = '[รูปภาพ]' . ($text !== '' ? ' ' . $text : '');
        $this->conversationManager->addMessage($conversationId, 'user', $placeholder, 0, $searchCriteria);
```

- [ ] **Step 2: System prompt** — add one rule (match the file's existing section style) near the other general rules:

```
IMAGE NOT VISIBLE: If the customer refers to an image/picture/photo ("รูปนี้", "this picture") but no image is attached to the current message, say you cannot see the image (it may have expired) and ask them to resend it. Never guess its contents.
```

- [ ] **Step 3: Bump library** — set the constraint in `composer.json` to the new release (e.g. `^1.3.0`), then `composer update wittakarn/chatbot-core`. Verify `vendor/wittakarn/chatbot-core/src/LineWebhookHandler.php` contains `quotedMessageId`.

- [ ] **Step 4: Verify** — `php -l SirichaiLineWebhook.php`; `php guardrails/run.php check` (system-prompt SECURITY block must remain intact); run `php guardrails/run.php prove` since `system-prompt.txt` is guarded.

- [ ] **Step 5: Manual LINE check** (real group with the bot): post an image, reply `zx รูปนี้คืออะไร`; expect an answer about the image. Reply `zx สวัสดี` to a text message; expect a normal text answer. Check `logs.log` for `[LINE] Downloaded content` / `Content download failed`.

- [ ] **Step 6: Commit** (Sirichai repo)

```bash
git add SirichaiLineWebhook.php system-prompt.txt composer.json composer.lock
git commit -m "feat: handle zx replies to images"
```

---

## Self-Review

- Spec coverage: MIME from header (Task 1), quoted routing + fallback + real MIME in image branch (Task 2), history text / prompt / version bump (Task 3), tests (Tasks 1-2), Phase 2 deliberately absent. Group image silence needs no change.
- One deviation from spec: quoted-image routing is not gated to groups (also works in 1:1); update spec line if you want it strict.
- Names consistent: `downloadContent`, `normalizeMime`, `downloadLineContent(..., &$mimeType)`.
