# Quotation callback invite — design

## Why
Customers download a quotation PDF and leave. Staff are only notified (LINE push, `ProductAPIService::notifyQuotationRemark`) when a quotation carries a contact remark — that is expected. So the bot should ask for contact details, and never push the follow-up work back onto the customer ("please call the shop").

## Scope
- Web chat only (LINE not supported for now). The prompt is shared, so no platform branching.
- Prompt-only change in `system-prompt.txt`, WORKFLOW 3. No code changes.

## Behavior
1. After every quotation PDF link, invite a callback:
   "หากต้องการให้ทางร้านติดต่อกลับเกี่ยวกับใบเสนอราคานี้ กรุณาแจ้งชื่อ เบอร์โทร หรือ LINE ID ไว้ได้เลยค่ะ ทางร้านจะติดต่อกลับโดยเร็วที่สุดค่ะ"
   Skip when the request already carried contact details (remark sent).
2. Contact given after a quotation → re-call `generate_quotation` with remark (unchanged), reply:
   "ได้รับข้อมูลติดต่อแล้วค่ะ ทางร้านจะติดต่อกลับโดยเร็วที่สุดค่ะ" — no "contact the shop" redirect.
3. Contact given before any quotation → do not call `generate_quotation`; ask for products + quantities so a quotation can be made and staff call back:
   "รบกวนแจ้งรายการสินค้าและจำนวน ทางร้านจะออกใบเสนอราคาและติดต่อกลับค่ะ"

English conversations get the same lines in English (existing language rule).

## Approaches
- **Prompt (picked):** bot writes the lines, so Thai/English follows the conversation.
- Append fixed text in PHP after the PDF URL: deterministic, but Thai-only and can't skip when a remark was already sent. Rejected.

## Verification
`tests/test-chatbot-with-history.php` and `tests/test-chatbot-english.php` (quotation turns) pass; spot-check the invite line appears after the PDF link. Needs MAMP MySQL running.
