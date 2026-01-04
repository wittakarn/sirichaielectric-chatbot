# API Integration Complete ✅

## What's Connected

Your chatbot is now integrated with your live product API:

**API Endpoint:** `https://shop.sirichaielectric.com/services/category-stats.php`

**AI Model:** `gemini-2.5-flash` (Latest Gemini model - June 2025)

## How It Works

### 1. Product Data Flow

```
Your Website API → Product Fetcher → Chatbot AI → Customer Response
```

1. **Product Fetcher** calls your API every 60 minutes
2. Parses categories, brands, and featured products
3. Formats data for the AI
4. AI uses this + general knowledge to answer questions

### 2. What the AI Knows

The chatbot now has access to:
- ✅ **Product Categories** (with brand lists and product counts)
- ✅ **Available Brands** (YAZAKI, Mitsubishi, Schneider, etc.)
- ✅ **Featured Products** (top 15 with prices and stock status)
- ✅ **Price Ranges** (per category)
- ✅ **Stock Status** (in stock / out of stock)

### 3. Example Conversations

**Customer asks about specific products:**
```
Customer: "มี Yazaki VAF 2.5 ไหมครับ"
AI: "มีครับ เรามีสายไฟ Yazaki VAF 2.5 sq.mm ในหมวดสายไฟ
     ราคาประมาณ ฿1,250 และยังมีสต็อกครับ

     สามารถดูรายละเอียดเพิ่มเติมได้ที่
     https://shop.sirichaielectric.com/"
```

**Customer asks general questions:**
```
Customer: "สายไฟแบบไหนใช้ในบ้านได้บ้าง"
AI: [Uses general electrical knowledge]
    "สายไฟที่ใช้ในบ้านมีหลายประเภท เช่น VAF, THW, VFF
     ที่ Sirichai Electric เรามีสายไฟ YAZAKI, Helukabel
     ที่ได้มาตรฐาน มอก."
```

## Configuration

Your current setup (in `.env`):

```env
GEMINI_MODEL=gemini-2.5-flash
GEMINI_API_KEY=AIzaSyA... (your key)
PRODUCT_API_ENDPOINT=https://shop.sirichaielectric.com/services/category-stats.php
PRODUCT_UPDATE_INTERVAL_MINUTES=60
```

## Gemini 2.5 Flash Model

**Why this model:**
- ✅ Latest stable model (June 2025 release)
- ✅ Fast response times
- ✅ 1M token context window
- ✅ Supports Thai and English fluently
- ✅ Better reasoning than older models

**Free Tier Limits:**
- 15 requests per minute (RPM)
- 1 million tokens per minute (TPM)
- 1,500 requests per day (RPD)

## What Gets Fetched

Based on your API response:

```json
{
  "lastUpdated": "2026-01-04T14:30:00+07:00",
  "categories": [
    {
      "name": "เซอร์กิตเบรกเกอร์",
      "brands": ["Mitsubishi", "Schneider Electric"],
      "productCount": 156,
      "priceRange": "฿500 - ฿5,000"
    }
  ],
  "brands": ["YAZAKI", "Mitsubishi", ...],
  "featuredProducts": [
    {
      "name": "Yazaki VAF 2.5 sq.mm",
      "brand": "YAZAKI",
      "price": 1250.50,
      "inStock": true
    }
  ]
}
```

## How Product Context is Added to AI

The product fetcher formats your API data like this:

```
CURRENT PRODUCT INVENTORY (Updated: 2026-01-04T14:30:00+07:00):

หมวดหมู่สินค้า (Product Categories):
- เซอร์กิตเบรกเกอร์ [Mitsubishi, Schneider Electric] (156 สินค้า) - ราคา ฿500 - ฿5,000
- สายไฟ [YAZAKI, Helukabel] (89 สินค้า) - ราคา ฿30 - ฿800

แบรนด์ที่มีจำหน่าย (Available Brands):
YAZAKI, Mitsubishi, Schneider Electric, ABB, Philips, Panasonic

สินค้าแนะนำ (Featured Products):
- Yazaki VAF 2.5 sq.mm [YAZAKI] (สายไฟ) - ฿1,250
- Mitsubishi NF30-CS [Mitsubishi] (เซอร์กิตเบรกเกอร์) - ฿850

Website: https://shop.sirichaielectric.com/
```

This context is added to EVERY customer conversation!

## Option 3: Smart Fallback Strategy

You chose **Option 3** which means:

### ✅ AI WILL:
- Answer general electrical questions using built-in knowledge
- Mention products/brands from your inventory
- Show featured products when relevant
- Guide customers to website for full details
- Be conversational and ask clarifying questions

### ✅ AI WON'T:
- Guarantee exact pricing (will say "ราคาประมาณ" or direct to website)
- Promise specific stock levels (will direct to website to confirm)
- Make up products not in your inventory

### Example Response Style:
```
"เรามีสายไฟ Yazaki VAF 2.5 ตร.มม. ในสต็อกครับ ราคาอยู่ที่ประมาณ ฿1,250

สายไฟ VAF เหมาะสำหรับงานติดตั้งไฟฟ้าทั่วไปในบ้าน/อาคาร
แรงดันไฟฟ้า 450/750V ตาม มอก.

สำหรับราคาและสต็อกล่าสุด กรุณาเข้าชมที่
https://shop.sirichaielectric.com/

ต้องการคำแนะนำเพิ่มเติมไหมครับ?"
```

## Monitoring

Product data is fetched:
- **On startup** - Immediately when server starts
- **Every 60 minutes** - Auto-refresh
- **On demand** - Can manually trigger via API (future feature)

Check logs for:
```
[Product Fetcher] Fetching product data...
[Product Fetcher] Product data updated successfully
[Product Fetcher] Auto-update enabled (every 60 minutes)
```

## Testing

Test the integration:

```bash
# Start server
npm run dev

# Test with product question (Thai)
curl -X POST http://localhost:3000/api/chat \
  -H "Content-Type: application/json" \
  -d '{"message": "มีสายไฟ Yazaki ไหมครับ"}'

# Test with general question (English)
curl -X POST http://localhost:3000/api/chat \
  -H "Content-Type: application/json" \
  -d '{"message": "What circuit breakers do you have?"}'

# Test conversation history
curl -X POST http://localhost:3000/api/chat \
  -H "Content-Type: application/json" \
  -d '{"message": "มี Yazaki VAF 2.5 ไหม", "conversationId": "user123"}'

curl -X POST http://localhost:3000/api/chat \
  -H "Content-Type: application/json" \
  -d '{"message": "ราคาเท่าไร", "conversationId": "user123"}'
```

## Next Steps

Your chatbot is ready! Here's what you can do:

1. **✅ Test thoroughly** - Try different product questions
2. **✅ Update product-top-rank-search.txt** - Control which products appear as "featured"
3. **📊 Monitor Usage** - Watch API calls to stay within free tier (1,500/day)
4. **🌐 Deploy** - When ready, deploy to production (Railway, Render, DigitalOcean)
5. **🎨 Add to Website** - Integrate chat widget
6. **📈 Track Analytics** - See what customers ask about

## Troubleshooting

**Product data not loading?**
- Check API endpoint is accessible: `curl https://shop.sirichaielectric.com/services/category-stats.php`
- Check server logs for errors
- Verify `.env` has correct `PRODUCT_API_ENDPOINT`

**AI not mentioning products?**
- Check Product Fetcher logs for successful fetch
- Verify API returns valid JSON
- Check `productContext` is being added to prompts

**Model not found error?**
- Ensure you're using `gemini-2.5-flash` (not older models)
- Verify API key is valid
- Check you haven't exceeded daily quota (1,500 requests)

**Need to force refresh?**
- Restart the server: `npm run dev`
- Product data fetches on startup
- Or wait for automatic hourly refresh

## API Updates

When you update your API:
- Chatbot auto-refreshes every 60 minutes
- Or restart server for immediate update
- No code changes needed!

## Available Models (Your API Key)

Your API key has access to these models:
- ✅ **`gemini-2.5-flash`** - Fast, efficient (currently using)
- ✅ **`gemini-2.5-pro`** - More powerful, slower

To switch models, update `.env`:
```env
GEMINI_MODEL=gemini-2.5-pro  # For more complex reasoning
```

---

🎉 **Your chatbot is now live and connected to your product catalog!**

It combines:
- Real-time product data from your website
- AI's electrical engineering knowledge
- Thai/English bilingual support
- Conversation memory

Ready to help your customers! 🚀
