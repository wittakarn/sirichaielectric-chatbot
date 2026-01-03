# Sirichai Electric Chatbot

AI-powered chatbot for Sirichai Electric e-commerce website using Google's Gemini API.

## Features

- 🤖 Powered by Google Gemini AI (Free Tier)
- 🌐 Bilingual support (Thai & English) with auto-detection
- 💡 Smart product knowledge system (fetches from website/API)
- 🔌 RESTful API for easy integration
- 📦 Ready for n8n workflow integration
- ⚡ Rate limiting to stay within free tier limits
- 💬 Conversation history management
- 🎯 TypeScript for type safety

## Quick Start

### 1. Get Gemini API Key (FREE)

1. Go to [Google AI Studio](https://makersuite.google.com/app/apikey)
2. Sign in with your Google account
3. Click "Create API Key"
4. Copy your API key

**Gemini Free Tier Limits:**
- 15 requests per minute
- 1 million tokens per minute
- 1,500 requests per day

### 2. Install Dependencies

```bash
npm install
```

### 3. Configure Environment

```bash
cp .env.example .env
```

Edit `.env` and add your Gemini API key:

```env
GEMINI_API_KEY=your_api_key_here
WEBSITE_URL=https://shop.sirichaielectric.com/
```

### 4. Run the Server

```bash
# Development mode with auto-reload
npm run dev

# Build TypeScript
npm run build

# Production mode
npm start
```

The server will start at `http://localhost:3000`

## API Endpoints

### 1. Send a Chat Message

**POST** `/api/chat`

```bash
curl -X POST http://localhost:3000/api/chat \
  -H "Content-Type: application/json" \
  -d '{
    "message": "What circuit breakers do you have from Mitsubishi?",
    "conversationId": "optional-user-id"
  }'
```

**Request:**
```json
{
  "message": "string (required)",
  "conversationId": "string (optional)",
  "language": "th | en | auto (optional, default: auto)"
}
```

**Response:**
```json
{
  "success": true,
  "response": "We carry various Mitsubishi circuit breakers...",
  "conversationId": "conv_1234567890_abc123",
  "language": "en"
}
```

### 2. Streaming Chat (Real-time)

**POST** `/api/chat/stream`

Returns Server-Sent Events (SSE) for real-time streaming responses.

### 3. Get Conversation History

**GET** `/api/conversation/:conversationId`

### 4. Clear Conversation

**DELETE** `/api/conversation/:conversationId`

### 5. Health Check

**GET** `/health`

## Testing

Run the test script to verify everything works:

```bash
npm test
```

This will send test messages in both Thai and English.

## Product Knowledge System

The chatbot automatically fetches product information from your website to stay up-to-date.

### Configuration Options:

**Option 1: Fetch from API (Recommended)**
```env
PRODUCT_API_ENDPOINT=https://shop.sirichaielectric.com/api/products
PRODUCT_UPDATE_INTERVAL_MINUTES=60
```

**Option 2: Default (Basic info)**
Leave `PRODUCT_API_ENDPOINT` empty to use basic product categories.

### Customize Product Fetching

Edit `src/services/product-fetcher.ts` to:
- Scrape your website
- Connect to your database
- Parse product feeds/CSV files
- Call your e-commerce platform API

## Project Structure

```
sirichaielectric-chatbot/
├── src/
│   ├── config/
│   │   └── index.ts              # Configuration
│   ├── middleware/
│   │   └── rate-limit.ts         # Rate limiting
│   ├── routes/
│   │   └── chat.routes.ts        # API routes
│   ├── services/
│   │   ├── chatbot.ts            # Core chatbot logic
│   │   └── product-fetcher.ts    # Product data fetching
│   ├── types/
│   │   ├── chat.types.ts         # Chat types
│   │   ├── gemini.types.ts       # Gemini types
│   │   ├── product.types.ts      # Product types
│   │   └── index.ts              # Type exports
│   ├── index.ts                  # Express server
│   └── test-chatbot.ts           # Test script
├── .env                          # Your configuration
├── .env.example                  # Example configuration
├── package.json
├── tsconfig.json
└── README.md
```

## Example Usage

### Basic Chat (JavaScript)

```javascript
const response = await fetch('http://localhost:3000/api/chat', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    message: 'มีสายไฟ Yazaki ไหมครับ',
    conversationId: 'user123'
  })
});

const data = await response.json();
console.log(data.response);
```

### Streaming Chat (JavaScript)

```javascript
const response = await fetch('http://localhost:3000/api/chat/stream', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    message: 'Tell me about LED lights'
  })
});

const reader = response.body.getReader();
while (true) {
  const { done, value } = await reader.read();
  if (done) break;

  const text = new TextDecoder().decode(value);
  console.log(text); // Real-time chunks
}
```

## Integration Examples

### Website Chat Widget

```html
<script>
async function sendMessage(message) {
  const response = await fetch('http://localhost:3000/api/chat', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      message: message,
      conversationId: sessionStorage.getItem('chatId')
    })
  });

  const data = await response.json();
  sessionStorage.setItem('chatId', data.conversationId);
  return data.response;
}
</script>
```

### n8n Workflow

See `docs/n8n-integration.md` for detailed n8n setup guide.

## Customization

### Adjust AI Personality

Edit the system prompt in `src/services/chatbot.ts` (line ~27) to change how the AI responds.

### Add Product Categories

Update `src/services/product-fetcher.ts` to fetch your actual product data.

### Change Rate Limits

Edit `.env`:
```env
MAX_REQUESTS_PER_MINUTE=15  # Adjust based on your needs
```

## Troubleshooting

### "GEMINI_API_KEY is required"
- Copy `.env.example` to `.env`
- Add your Gemini API key from Google AI Studio

### "Rate limit exceeded"
- You're hitting Gemini's free tier limits (15 req/min)
- Wait 1 minute or upgrade to paid tier

### TypeScript errors
- Run `npm install` to install all dependencies
- Run `npm run type-check` to verify types

## Next Steps

1. **Connect to your product database** - Update `product-fetcher.ts`
2. **Deploy to production** - Use services like Railway, Render, or DigitalOcean
3. **Add to your website** - Integrate the chat widget
4. **Set up n8n** - Automate workflows
5. **Monitor usage** - Track API calls to stay within limits

## License

ISC

## Support

For issues or questions, please visit your website or contact your development team.
