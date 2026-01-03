import { Router, Request, Response } from 'express';
import { SirichaiChatbot } from '../services/chatbot';
import { ChatRequest } from '../types';
import { chatRateLimiter } from '../middleware/rate-limit';

export function createChatRouter(chatbot: SirichaiChatbot): Router {
  const router = Router();

  // Apply rate limiting to all chat routes
  router.use(chatRateLimiter);

  // Chat endpoint - send a message and get a response
  router.post('/chat', async (req: Request, res: Response) => {
    try {
      const { message, conversationId, language } = req.body as ChatRequest;

      // Validation
      if (!message || typeof message !== 'string' || message.trim().length === 0) {
        return res.status(400).json({
          success: false,
          error: 'Message is required and must be a non-empty string',
        });
      }

      // Process chat request
      const chatRequest: ChatRequest = {
        message: message.trim(),
        conversationId,
        language: language || 'auto',
      };

      const response = await chatbot.chat(chatRequest);
      return res.json(response);
    } catch (error: any) {
      console.error('Chat endpoint error:', error);
      return res.status(500).json({
        success: false,
        error: 'Internal server error',
      });
    }
  });

  // Streaming chat endpoint - get responses in real-time chunks
  router.post('/chat/stream', async (req: Request, res: Response): Promise<void> => {
    try {
      const { message, conversationId, language } = req.body as ChatRequest;

      if (!message || typeof message !== 'string' || message.trim().length === 0) {
        res.status(400).json({
          success: false,
          error: 'Message is required',
        });
        return;
      }

      // Set headers for Server-Sent Events
      res.setHeader('Content-Type', 'text/event-stream');
      res.setHeader('Cache-Control', 'no-cache');
      res.setHeader('Connection', 'keep-alive');

      const chatRequest: ChatRequest = {
        message: message.trim(),
        conversationId,
        language: language || 'auto',
      };

      await chatbot.streamChat(chatRequest, (chunk) => {
        res.write(`data: ${JSON.stringify({ chunk })}\n\n`);
      });

      res.write('data: [DONE]\n\n');
      res.end();
    } catch (error: any) {
      console.error('Stream endpoint error:', error);
      if (!res.headersSent) {
        res.status(500).json({
          success: false,
          error: 'Internal server error',
        });
      }
    }
  });

  // Get conversation history
  router.get('/conversation/:conversationId', (req: Request, res: Response) => {
    const { conversationId } = req.params;
    const history = chatbot.getConversationHistory(conversationId);

    if (!history) {
      return res.status(404).json({
        success: false,
        error: 'Conversation not found',
      });
    }

    return res.json({
      success: true,
      conversation: history,
    });
  });

  // Clear specific conversation
  router.delete('/conversation/:conversationId', (req: Request, res: Response) => {
    const { conversationId } = req.params;
    const deleted = chatbot.clearConversation(conversationId);

    return res.json({
      success: deleted,
      message: deleted ? 'Conversation cleared' : 'Conversation not found',
    });
  });

  return router;
}
