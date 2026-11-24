# Quotation Callback Invite Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** After a quotation PDF the bot invites the customer to leave contact details, and confirms the shop will call back instead of telling the customer to call the shop.

**Architecture:** Prompt-only change in `system-prompt.txt` WORKFLOW 3 (reloaded every request). Behavior is pinned by assertions in the existing live Gemini tests.

**Tech Stack:** PHP 7.4 (MAMP `/Applications/MAMP/bin/php/php7.4.33/bin/php`), Gemini, MySQL (MAMP must be running).

**Spec:** `docs/superpowers/specs/2026-11-24-quotation-callback-invite-design.md`

## Global Constraints

- Invite line (Thai): `หากต้องการให้ทางร้านติดต่อกลับเกี่ยวกับใบเสนอราคานี้ กรุณาแจ้งชื่อ เบอร์โทร หรือ LINE ID ไว้ได้เลยค่ะ ทางร้านจะติดต่อกลับโดยเร็วที่สุดค่ะ`
- Confirm line: `ได้รับข้อมูลติดต่อแล้วค่ะ ทางร้านจะติดต่อกลับโดยเร็วที่สุดค่ะ`
- No-quotation line: `รบกวนแจ้งรายการสินค้าและจำนวน ทางร้านจะออกใบเสนอราคาและติดต่อกลับค่ะ`
- No code changes. Web only; no platform branching.
- Run Gemini tests sequentially with `sleep 15` between files.

## Review Focus

1. Test runs send real staff LINE pushes when a remark is generated → run tests with `LINE_QUOTATION_NOTIFY_TO` empty in `.env`.
2. English conversation → invite appears in English, no Thai markers (covered by existing `hasThaiReplyMarkers` check in Task 1 step 3).
3. Contact given in the same message as the quotation request → no invite line (spec says skip); not tested — LLM-judged, check by hand once.
4. Contact after quotation → bot must not show a second PDF link (Task 1 assertion).
5. Contact before quotation → must not call `generate_quotation` / show PDF (Task 1 assertion).

---

### Task 1: Prompt rules + test assertions

**Files:**
- Modify: `system-prompt.txt:76-88` (WORKFLOW 3; behaviors 1 & 2 already applied in working tree, add 3)
- Test: `tests/test-chatbot-with-history.php:108-150`, `tests/test-chatbot-english.php:105-113`, `tests/test-chatbot-without-history.php`

**Interfaces:** none (prompt text + test array entries).

- [ ] **Step 1: Add assertions to `tests/test-chatbot-with-history.php`**
  - Q6 (`ออกใบเสนอราคาได้เลย`) validate: also fail with `'Response must invite the customer to leave contact for a callback'` unless `mb_strpos($response, 'ติดต่อกลับ') !== false`.
  - Insert a new turn right after Q11 (`ออกใบเสนอราคา เรท a`): question `ช่วยติดต่อกลับด้วยค่ะ คุณสมชาย 081-234-5678`; validate fails unless response contains `ติดต่อกลับ`, does NOT contain `.pdf`, and does NOT contain `02-111-7801`.
  - Update the header comment list (Q12 becomes the contact turn, colour check moves to Q13).

- [ ] **Step 2: Add a no-quotation case to `tests/test-chatbot-without-history.php`**
  Question `ช่วยให้ร้านติดต่อกลับหน่อยค่ะ เบอร์ 081-234-5678`; validate fails if response contains `.pdf` or `02-111-7801`, or lacks `จำนวน`. Follow that file's existing case format.

- [ ] **Step 3: English test** — in `tests/test-chatbot-english.php` Q3 validate, also require `mb_stripos($response, 'contact') !== false`.

- [ ] **Step 4: Run the new assertions against the prompt WITHOUT behavior 3 (`git stash` the prompt only if needed) and confirm the no-quotation case fails**
  Run: `/Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-chatbot-without-history.php`
  Expected: the new case FAILS (bot replies with shop phone numbers).

- [ ] **Step 5: Edit `system-prompt.txt:79`** — replace the "No quotation generated yet" bullet with: do NOT call generate_quotation; ask for the products and quantities so a quotation can be made and the shop will call back, using the no-quotation line from Global Constraints. Do not list shop contact info.

- [ ] **Step 6: Run all five test files sequentially** (command block in `CLAUDE.md` "Running Tests").
  Expected: all PASS.

- [ ] **Step 7: Commit**
```bash
git add system-prompt.txt tests/ docs/superpowers/
git commit -m "Invite a callback after quotation PDF; shop calls back instead of customer"
```
