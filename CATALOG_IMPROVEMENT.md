# Catalog Bottleneck Improvement

**Date:** March 2026
**Status:** Planned — not yet implemented

---

## Problem

The product catalog (`cache/catalog-summary-cache.md`, ~94KB, 1,083 category names) is passed to Gemini on every request via File API. Gemini reads the entire catalog solely to pick **exact category name strings** to pass to `search_products(criterias[])`. This creates:

1. **Exact-match failure risk** — AI picks wrong category variant → wrong/empty results
2. **Large context overhead** — 94KB of category names processed each request
3. **Uncached backend calls** — `search_products()` and `search_product_detail()` hit backend API every time, no caching

Note: Gemini 2.5 Flash already does **implicit KV caching** for File API content (shown in `cachedContentTokenCount`). Explicit Context Caching API would be redundant.

---

## Solution: Natural-Language Search (Option A + cache)

Replace exact-category-name search with fuzzy keyword search. Catalog no longer needed in Gemini context.

### How it works

**Before:**
```
Customer: "มี SCHNEIDER LRD05 ไหม"
→ AI reads 94KB catalog → picks exact name → search_products(criterias: ["โอเวอร์โหลด SCHNEIDER { LRD ขนาดเล็ก, LRD05 to LRD32 ... }"])
→ Backend: exact match
```

**After:**
```
Customer: "มี SCHNEIDER LRD05 ไหม"
→ AI calls search_products(query: "โอเวอร์โหลด SCHNEIDER LRD05")
→ Backend: keyword scoring (LIKE %โอเวอร์โหลด% + LIKE %SCHNEIDER% + LIKE %LRD05%) → ranked results
```

### Backend fuzzy scoring (SQL pattern)

File to modify on `shop.sirichaielectric.com`: `services/products-by-categories-prompt.php`

```php
// Accept free-form query instead of criterias[] array
$query = $_POST['query'];
$keywords = array_filter(explode(' ', trim($query)));

// Build scoring query
$scoreExpr = [];
$whereOr = [];
$params = [];

foreach ($keywords as $kw) {
    $scoreExpr[] = "(category_name LIKE ?)";
    $whereOr[]   = "category_name LIKE ?";
    $params[] = "%$kw%";  // for score
    $params[] = "%$kw%";  // for WHERE
}

$sql = "SELECT category_name,
               (" . implode(' + ', $scoreExpr) . ") AS score
        FROM categories
        WHERE " . implode(' OR ', $whereOr) . "
        ORDER BY score DESC
        LIMIT 5";

// Then fetch products for top matching categories
```

This mirrors the existing pattern in `product-detail-prompt.php` (fuzzy product name matching).

### Chatbot changes

#### 1. `services/ProductAPIService.php`
```php
// Before
public function searchProducts($criterias) {
    $body = ['criterias' => $criterias];
    ...
}

// After
public function searchProducts($query) {
    // 2-hour file cache
    $cacheKey = md5($query);
    $cacheFile = $this->cacheDir . '/search-' . $cacheKey . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 7200) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    $body = ['query' => $query];
    // ... existing HTTP POST logic ...

    file_put_contents($cacheFile, json_encode($result));
    return $result;
}
```

Also add same 2h cache to `getProductDetail()`.

#### 2. `chatbot/SirichaiElectricChatbot.php`

`getFunctionDeclarations()` — change parameter:
```php
// Before: criterias (array of strings)
// After:
[
    'name' => 'search_products',
    'parameters' => [
        'type' => 'object',
        'properties' => [
            'query' => [
                'type' => 'string',
                'description' => 'Free-form search query describing the product, brand, or model number'
            ]
        ],
        'required' => ['query']
    ]
]
```

`executeFunction()` — change argument:
```php
// Before: $args['criterias']
// After:  $args['query']
```

#### 3. `system-prompt.txt`
- Remove WORKFLOW 1 exact-category-name instructions
- Remove warning about special chars `{}`, `[]`, `()`
- New instruction: "call search_products() with a natural language description of what the customer wants, including brand and model number if mentioned"

#### 4. `vendor/wittakarn/chatbot-core/src/GeminiChatbot.php`
- `uploadContextFiles()`: Remove catalog upload (keep system prompt inline load)
- `callGeminiWithFunctions()`: Remove `file_data` catalog injection (lines ~341–346)

---

## End-to-end flow example (LRD05)

```
Customer: "มี SCHNEIDER LRD05 ไหม ราคาเท่าไหร่"

Gemini Call 1:
  No catalog in context (removed)
  AI understands: Schneider overload relay LRD05
  → calls search_products(query: "โอเวอร์โหลด SCHNEIDER LRD05")

ProductAPI Call 1 (fuzzy backend):
  Keywords: ["โอเวอร์โหลด", "SCHNEIDER", "LRD05"]
  Line 484: "โอเวอร์โหลด SCHNEIDER { LRD ขนาดเล็ก, LRD05 to LRD32... }" score=3 ← top
  Line 485: "โอเวอร์โหลด SCHNEIDER { LRD35... }"                               score=2
  Returns: all LRD products in top category, including LRD05 with price

Gemini Call 2:
  Finds LRD05 → responds with price + link

Total: 2 Gemini calls, 1 ProductAPI call
```

---

## Files to modify

| File | Location | Change |
|------|----------|--------|
| `products-by-categories-prompt.php` | `shop.sirichaielectric.com/services/` | Add keyword scoring SQL |
| `ProductAPIService.php` | `services/` | `searchProducts($query)` + 2h cache on search + detail |
| `SirichaiElectricChatbot.php` | `chatbot/` | Update function declaration + executeFunction |
| `system-prompt.txt` | project root | Simplify WORKFLOW 1 |
| `GeminiChatbot.php` | `vendor/wittakarn/chatbot-core/src/` | Remove catalog injection |

Cache files no longer generated after change:
- `cache/catalog-summary-cache.md`
- `file-cache.json`

---

## Verification

```bash
# Run full test suite sequentially after implementation
/Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-chatbot-without-history.php
sleep 15
/Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-chatbot-with-history.php
sleep 15
/Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-chatbot-batch-price.php
sleep 15
/Applications/MAMP/bin/php/php7.4.33/bin/php tests/test-chatbot-unauthorized.php

# Verify catalog cache no longer generated
ls cache/   # should NOT contain catalog-summary-cache.md

# Verify search result cache is created
ls cache/search-*.json   # appears after first product search

# Check token reduction in logs
tail -f logs.log | grep -i token
```
