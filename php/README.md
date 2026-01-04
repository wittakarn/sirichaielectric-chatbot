# Sirichai Electric Chatbot - PHP Version

AI-powered customer service chatbot for Sirichai Electric using Google Gemini API.

**PHP 5.6+ Compatible** - Pure PHP implementation with no external dependencies.

## Features

- Google Gemini 2.5 Flash AI model
- Bilingual support (Thai/English with auto-detection)
- Real-time product data integration
- Conversation history using PHP sessions
- Simple REST API
- No composer or external dependencies required

## Requirements

- PHP 5.6.40 or higher
- cURL extension enabled
- Apache with mod_rewrite (for clean URLs)
- PHP sessions enabled

## Installation

### 1. Upload Files

Upload the `php/` directory to your web server.

### 2. Configure Environment

Edit the `.env` file with your settings:

```env
GEMINI_API_KEY=your_gemini_api_key_here
GEMINI_MODEL=gemini-2.5-flash
WEBSITE_URL=https://shop.sirichaielectric.com/
PRODUCT_API_ENDPOINT=https://shop.sirichaielectric.com/services/category-stats.php
```

### 3. Set Permissions

Make sure the `cache/` directory is writable:

```bash
mkdir cache
chmod 755 cache
```

### 4. Apache Configuration

The `.htaccess` file is already included. Make sure `mod_rewrite` is enabled:

```bash
sudo a2enmod rewrite
sudo service apache2 restart
```

## Quick Start - Try the Demo

After installation, open the demo UI in your browser:

```
http://your-domain.com/php/demo.html
```

The demo provides a beautiful chat interface where you can:
- Test the chatbot with Thai and English messages
- Use quick question buttons
- See real-time typing indicators
- View conversation history
- Clear conversations

Perfect for testing and showing to stakeholders!

## File Structure

```
php/
├── index.php              # Main API endpoint
├── config.php             # Configuration loader
├── SirichaiChatbot.php    # Chatbot service
├── ProductFetcher.php     # Product data fetcher
├── ConversationManager.php # Session-based conversation storage
├── demo.html             # Demo chat UI (standalone)
├── .env                   # Environment configuration
├── .htaccess             # Apache rewrite rules
├── cache/                # Product data cache (auto-created)
└── README.md             # This file
```

## API Endpoints

### 1. Health Check

```bash
GET /health

Response:
{
  "status": "ok",
  "service": "Sirichai Electric Chatbot (PHP)",
  "version": "1.0.0",
  "timestamp": "2026-01-04T12:00:00+00:00",
  "productDataLastUpdated": "2026-01-04T11:30:00+00:00"
}
```

### 2. Chat

```bash
POST /chat
Content-Type: application/json

{
  "message": "มีสายไฟ Yazaki ไหมครับ",
  "conversationId": "optional_conversation_id"
}

Response:
{
  "success": true,
  "response": "มีครับ เรามีสายไฟ Yazaki...",
  "conversationId": "conv_1234567890_abc123",
  "language": "th"
}
```

### 3. Get Conversation History

```bash
GET /conversation/{conversationId}

Response:
{
  "success": true,
  "conversation": {
    "conversationId": "conv_1234567890_abc123",
    "messages": [
      {
        "role": "user",
        "content": "มีสายไฟ Yazaki ไหมครับ",
        "timestamp": 1234567890
      },
      {
        "role": "assistant",
        "content": "มีครับ...",
        "timestamp": 1234567891
      }
    ],
    "createdAt": 1234567890,
    "lastActivity": 1234567891
  }
}
```

### 4. Clear Conversation

```bash
DELETE /conversation/{conversationId}

Response:
{
  "success": true,
  "message": "Conversation cleared"
}
```

## Demo UI Features

The included [demo.html](demo.html) file provides a complete chat interface with:

### Features
- **Modern Design** - Beautiful gradient purple theme with smooth animations
- **Bilingual Support** - Works seamlessly with Thai and English
- **Quick Questions** - Pre-defined questions for easy testing
- **Typing Indicators** - Shows when the AI is "thinking"
- **Conversation Memory** - Maintains context across messages
- **Mobile Responsive** - Works on phones, tablets, and desktops
- **Session Persistence** - Remembers your conversation ID
- **Clear Chat** - Reset conversation anytime

### Customization

To customize the demo for your brand:

1. **Colors**: Edit the CSS gradient in `demo.html`:
```css
background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
/* Change to your brand colors */
```

2. **Quick Questions**: Modify the buttons:
```html
<div class="quick-question" onclick="sendQuickQuestion(this.textContent)">
    Your custom question here
</div>
```

3. **Logo/Title**: Update the header section
4. **API URL**: The demo auto-detects the API URL based on its location

### Embedding in Your Website

You can embed the chatbot in your existing website:

```html
<iframe
    src="http://your-domain.com/php/demo.html"
    width="400"
    height="600"
    style="border: none; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);"
></iframe>
```

Or extract the chat component and integrate it directly into your site's layout.

## Testing

### Using the Demo UI

Simply open `demo.html` in your browser and start chatting!

### Using cURL

```bash
# Health check
curl http://your-domain.com/php/health

# Chat (Thai)
curl -X POST http://your-domain.com/php/chat \
  -H "Content-Type: application/json" \
  -d '{"message": "มีสายไฟ Yazaki ไหมครับ"}'

# Chat (English)
curl -X POST http://your-domain.com/php/chat \
  -H "Content-Type: application/json" \
  -d '{"message": "What circuit breakers do you have?"}'

# Chat with conversation ID (for context)
curl -X POST http://your-domain.com/php/chat \
  -H "Content-Type: application/json" \
  -d '{"message": "ราคาเท่าไร", "conversationId": "conv_123_abc"}'
```

### Using JavaScript

```javascript
// Chat request
fetch('http://your-domain.com/php/chat', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    message: 'มีสายไฟ Yazaki ไหมครับ',
    conversationId: sessionStorage.getItem('conversationId') || undefined
  })
})
.then(response => response.json())
.then(data => {
  console.log('Response:', data.response);
  // Save conversation ID for next request
  sessionStorage.setItem('conversationId', data.conversationId);
});
```

## Integration with Existing PHP App

### Option 1: Include Directly

```php
<?php
// In your existing PHP application
require_once 'path/to/chatbot/config.php';
require_once 'path/to/chatbot/ProductFetcher.php';
require_once 'path/to/chatbot/SirichaiChatbot.php';
require_once 'path/to/chatbot/ConversationManager.php';

// Initialize
$config = Config::getInstance();
$productFetcher = new ProductFetcher($config->get('productData'));
$chatbot = new SirichaiChatbot($config->get('gemini'), $productFetcher);
$conversationManager = new ConversationManager();

// Get conversation ID from session or generate new one
$conversationId = isset($_SESSION['chatbot_conv_id'])
    ? $_SESSION['chatbot_conv_id']
    : $conversationManager->generateConversationId();

// Get user message
$userMessage = $_POST['message'];

// Get conversation history
$history = $conversationManager->getConversationHistory($conversationId);

// Get response
$response = $chatbot->chat($userMessage, $history);

if ($response['success']) {
    // Add messages to history
    $conversationManager->addMessage($conversationId, 'user', $userMessage);
    $conversationManager->addMessage($conversationId, 'assistant', $response['response']);

    // Save conversation ID
    $_SESSION['chatbot_conv_id'] = $conversationId;

    echo $response['response'];
}
?>
```

### Option 2: Use as REST API

Keep the chatbot in a separate directory and call it via HTTP requests from your main application.

## Configuration Options

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `GEMINI_API_KEY` | (required) | Your Google Gemini API key |
| `GEMINI_MODEL` | `gemini-2.5-flash` | Gemini model to use |
| `GEMINI_TEMPERATURE` | `0.7` | Response randomness (0-1) |
| `GEMINI_MAX_TOKENS` | `2048` | Max response length |
| `WEBSITE_URL` | (required) | Your website URL |
| `PRODUCT_API_ENDPOINT` | (optional) | Product data API endpoint |
| `PRODUCT_UPDATE_INTERVAL_MINUTES` | `60` | How often to refresh product data |
| `MAX_REQUESTS_PER_MINUTE` | `15` | Rate limit (Gemini free tier) |

### Product Data Integration

The chatbot fetches product information from your API endpoint and caches it locally. The cache is updated:

- On first request after server restart
- Every 60 minutes (configurable)
- Falls back to cached data if API is unreachable

## Troubleshooting

### "Configuration error: GEMINI_API_KEY is required"

Make sure your `.env` file exists and contains `GEMINI_API_KEY=your_key_here`

### "cURL error: ..."

Make sure the cURL extension is enabled in PHP:

```bash
php -m | grep curl
```

If not installed:

```bash
# Ubuntu/Debian
sudo apt-get install php5-curl
sudo service apache2 restart

# CentOS/RHEL
sudo yum install php-curl
sudo service httpd restart
```

### Sessions not working

Make sure sessions are enabled in `php.ini`:

```ini
session.save_path = "/tmp"
session.auto_start = 0
```

And that the save path is writable.

### Clean URLs not working

Make sure:
1. `.htaccess` file exists
2. `mod_rewrite` is enabled
3. Apache is configured to allow `.htaccess` overrides:

```apache
<Directory /var/www/html>
    AllowOverride All
</Directory>
```

### Product data not loading

Check that:
1. `cache/` directory exists and is writable (755 or 775)
2. Your `PRODUCT_API_ENDPOINT` is accessible
3. API returns valid JSON

Test the endpoint directly:

```bash
curl https://shop.sirichaielectric.com/services/category-stats.php
```

## Security Considerations

- The `.htaccess` file prevents direct access to `.env`
- Never commit `.env` to version control
- Use HTTPS in production
- Implement rate limiting in your web server
- Consider adding API key authentication for production use

## PHP Version Compatibility

This code is designed to work with PHP 5.6.40 and avoids:
- Type declarations (PHP 7+)
- Null coalescing operator `??` (PHP 7+)
- Short array syntax `[]` in some places
- Scalar type hints
- Return type declarations

If you have PHP 7+, the code will still work perfectly.

## Gemini API Limits (Free Tier)

- 15 requests per minute (RPM)
- 1 million tokens per minute (TPM)
- 1,500 requests per day (RPD)

Monitor your usage to stay within limits.

## Support

For issues or questions:
- Check the troubleshooting section
- Review error logs: `/var/log/apache2/error.log`
- Test endpoints with curl
- Verify `.env` configuration

## License

MIT License - Free to use for commercial and personal projects.

---

Built for Sirichai Electric (ศิริชัยอิเล็คทริค)
