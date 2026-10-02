# LINE: ask about an earlier image with `zx` + reply

## Goal
In a LINE **group/room**, a user posts an image (bot stays silent). Later anyone replies (quotes) that image with `zx <question>`; the bot looks at that image and answers.

## Decisions
- Scope: groups/rooms only. 1:1 keeps current behaviour (image answered immediately).
- Image-only. Replying to a *text* with `zx` is out of scope (see Phase 2).
- No storage: fetch the image on demand from LINE using `message.quotedMessageId`.

## LINE facts (verified in LINE Messaging API reference)
- `quotedMessageId`: ID of the quoted message, only present when the message quotes one. ID only, no content.
- `GET api-data.line.me/v2/bot/message/{id}/content` returns image/video/audio/file bytes; format is in the `Content-Type` header. Only works when `contentProvider.type == line`.
- Content is auto-deleted after an undocumented period; retention is not guaranteed.
- No API to re-fetch text of a past message.

## Design
### chatbot-core (`/Users/wittakarnkeeratichayakorn/Sites/chatbot-core`)
1. `LineWebhookUtils::downloadLineContent($id, $token, &$mimeType = null)`: also return the response `Content-Type` through the optional by-ref param (keeps current callers working).
2. `LineWebhookHandler::handleEvent`, text branch: if `message.quotedMessageId` is set, download it. If it succeeds and the MIME starts with `image/`, call `getAIResponseWithImage($bytes, $mime, $cleanedText, ...)`. Any other outcome (text/sticker/video quote, expired content, HTTP error): fall back to the existing text path, silently.
3. Existing image branch: use the real MIME from the header instead of hardcoded `image/jpeg`.
4. Group/room images remain ignored (`shouldRespondToEvent` unchanged).
5. Release a new chatbot-core version.

### Sirichai app
1. `composer.json`: bump `wittakarn/chatbot-core`.
2. `SirichaiLineWebhook::getAIResponseWithImage`: store `[รูปภาพ] <text>` as the user message (currently a fixed placeholder that drops the question).
3. `system-prompt.txt`: if the user refers to an image/picture and none is attached, say it cannot be seen and ask them to resend it (covers expired content).

## Error handling
Download failure never errors out to the user; it degrades to a text-only answer. One extra HTTP call per quoted `zx` message.

## Testing
- Unit-style script with fake events (stub download): quoted image -> image path with question text; quoted text -> text path; download failure -> text path; non-image MIME -> text path; group image alone -> ignored.
- Existing suites in `tests/` run sequentially as usual (no new Gemini test needed).

## Phase 2 (not now)
Reply-to-text needs us to store all group text (message_id -> text), handle unsend/edit events, and set a retention policy. Same table could hold images for reliability if LINE retention proves too short.
