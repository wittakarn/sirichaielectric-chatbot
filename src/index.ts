import express, { Request, Response, NextFunction } from 'express';
import cors from 'cors';
import bodyParser from 'body-parser';
import { config, validateConfig } from './config';
import { SirichaiChatbot } from './services/chatbot';
import { ProductFetcher } from './services/product-fetcher';
import { createChatRouter } from './routes/chat.routes';

// Validate configuration
try {
  validateConfig();
} catch (error: any) {
  console.error('❌ Configuration error:', error.message);
  console.error('💡 Make sure to copy .env.example to .env and add your GEMINI_API_KEY');
  process.exit(1);
}

// Initialize Express app
const app = express();

// Middleware
app.use(cors());
app.use(bodyParser.json());
app.use(bodyParser.urlencoded({ extended: true }));

// Request logging
app.use((req: Request, _res: Response, next: NextFunction) => {
  console.log(`[${new Date().toISOString()}] ${req.method} ${req.path}`);
  next();
});

// Initialize product fetcher
const productFetcher = new ProductFetcher(config.productData);

// Initialize chatbot with product fetcher
const chatbot = new SirichaiChatbot({
  apiKey: config.gemini.apiKey,
  model: config.gemini.model,
  temperature: config.gemini.temperature,
  maxTokens: config.gemini.maxTokens,
}, productFetcher);

// Start fetching product data
productFetcher.start().catch(err => {
  console.error('[Product Fetcher] Failed to start:', err);
});

// Health check endpoint
app.get('/health', (_req: Request, res: Response) => {
  res.json({
    status: 'ok',
    service: 'Sirichai Electric Chatbot',
    version: '1.0.0',
    timestamp: new Date().toISOString(),
  });
});

// API routes
app.use('/api', createChatRouter(chatbot));

// 404 handler
app.use((_req: Request, res: Response) => {
  res.status(404).json({
    success: false,
    error: 'Endpoint not found',
  });
});

// Error handler
app.use((err: Error, _req: Request, res: Response, _next: NextFunction) => {
  console.error('❌ Unhandled error:', err);
  res.status(500).json({
    success: false,
    error: 'Internal server error',
  });
});

// Cleanup old conversations every hour
setInterval(() => {
  const cleaned = chatbot.cleanupOldConversations(24);
  if (cleaned > 0) {
    console.log(`[Cleanup] Removed ${cleaned} old conversations`);
  }
}, 60 * 60 * 1000);

// Start server
const PORT = config.server.port;
app.listen(PORT, () => {
  console.log(`\n🤖 Sirichai Electric Chatbot Server`);
  console.log(`📡 Server: http://localhost:${PORT}`);
  console.log(`🔑 Model: ${config.gemini.model}`);
  console.log(`🌍 Environment: ${config.server.env}`);
  console.log(`⚡ Rate limit: ${config.rateLimit.maxRequestsPerMinute} requests/minute\n`);
});

export default app;
