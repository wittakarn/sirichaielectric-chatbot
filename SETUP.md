# Setup Guide - Sirichai Electric Chatbot

## ✅ What's Done

Your TypeScript chatbot project is fully set up with:
- ✅ TypeScript configuration
- ✅ Express API server with rate limiting
- ✅ Gemini AI integration
- ✅ Thai/English bilingual support
- ✅ Product knowledge system (fetches from website)
- ✅ Conversation history management
- ✅ Test scripts
- ✅ All dependencies installed

## 🚀 Quick Start (3 Steps)

### Step 1: Get Your FREE Gemini API Key

1. Visit: https://makersuite.google.com/app/apikey
2. Sign in with your Google account
3. Click **"Create API Key"**
4. Copy the API key

### Step 2: Add API Key to .env

Open the `.env` file and replace `your_gemini_api_key_here` with your actual API key:

```env
GEMINI_API_KEY=AIzaSyD... (your actual key here)
```

### Step 3: Start the Server

```bash
npm run dev
```

The server will start at `http://localhost:3000`

## 🧪 Test It

### Option 1: Run Test Script

```bash
npm test
```

This will send test messages in Thai and English and show the responses.

### Option 2: Use curl

```bash
curl -X POST http://localhost:3000/api/chat \
  -H "Content-Type: application/json" \
  -d '{"message": "What circuit breakers do you have?"}'
```

### Option 3: Use Postman/Insomnia

**POST** `http://localhost:3000/api/chat`

Body:
```json
{
  "message": "มีสายไฟ Yazaki ไหมครับ"
}
```

## 📁 Project Structure

```
sirichaielectric-chatbot/
├── src/
│   ├── config/              # Configuration
│   ├── middleware/          # Rate limiting
│   ├── routes/              # API routes
│   ├── services/            # Chatbot & product fetcher
│   ├── types/               # TypeScript types
│   ├── index.ts             # Main server
│   └── test-chatbot.ts      # Test script
├── .env                     # YOUR CONFIG (add API key here)
├── package.json
├── tsconfig.json
└── README.md
```

## 🔧 Available Commands

```bash
# Development (with auto-reload)
npm run dev

# Build TypeScript to JavaScript
npm run build

# Run production server
npm start

# Run tests
npm test

# Type check only
npm run type-check
```

## 🌐 API Endpoints

### 1. Chat (POST /api/chat)
Send a message and get a response.

### 2. Streaming Chat (POST /api/chat/stream)
Get real-time streaming responses.

### 3. Get History (GET /api/conversation/:id)
Retrieve conversation history.

### 4. Clear Chat (DELETE /api/conversation/:id)
Clear a conversation.

### 5. Health Check (GET /health)
Check server status.

## ⚙️ Configuration (.env)

```env
# Required
GEMINI_API_KEY=your_key_here

# Optional (already set with defaults)
PORT=3000
GEMINI_MODEL=gemini-2.0-flash-exp
MAX_REQUESTS_PER_MINUTE=15
WEBSITE_URL=https://shop.sirichaielectric.com/
```

## 🎯 Next Steps

1. **✅ Get API Key** - From Google AI Studio
2. **✅ Test Locally** - Run `npm run dev` and test
3. **🔄 Connect to Product Data** - Edit `src/services/product-fetcher.ts` to fetch from your actual product database/API
4. **🌐 Deploy** - Use Railway, Render, or DigitalOcean
5. **🎨 Add Chat Widget** - Integrate into your website
6. **📊 Monitor Usage** - Track API calls to stay within free tier

## 🆓 Gemini Free Tier Limits

- **15 requests per minute**
- **1 million tokens per minute**
- **1,500 requests per day**

The chatbot has built-in rate limiting to stay within these limits.

## ❓ Troubleshooting

### "GEMINI_API_KEY is required"
→ Add your API key to the `.env` file

### Port already in use
→ Change PORT in `.env` or stop other services on port 3000

### TypeScript errors
→ Run `npm install` again

### Rate limit errors
→ Wait 1 minute between requests (free tier limit)

## 📚 Learn More

- Gemini API Docs: https://ai.google.dev/docs
- TypeScript Docs: https://www.typescriptlang.org/
- Express.js Docs: https://expressjs.com/

## 🎉 You're Ready!

Your chatbot is ready to use. Just add your Gemini API key and run `npm run dev`!
