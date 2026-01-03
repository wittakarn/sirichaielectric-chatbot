import rateLimit from 'express-rate-limit';
import { config } from '../config';

/**
 * Rate limiting to prevent abuse and stay within Gemini API limits
 *
 * Gemini Free Tier Limits:
 * - 15 requests per minute (RPM)
 * - 1 million tokens per minute (TPM)
 * - 1,500 requests per day (RPD)
 *
 * Why rate limiting is important:
 * 1. Prevents abuse and malicious users from exhausting your API quota
 * 2. Ensures fair usage across all users
 * 3. Protects against accidentally hitting Gemini's free tier limits
 * 4. Prevents your API key from being blocked due to excessive requests
 */

export const chatRateLimiter = rateLimit({
  windowMs: 60 * 1000, // 1 minute
  max: config.rateLimit.maxRequestsPerMinute, // 15 requests per minute (safe limit for Gemini free tier)
  message: {
    success: false,
    error: 'Too many requests. Please try again in a minute.',
  },
  standardHeaders: true, // Return rate limit info in `RateLimit-*` headers
  legacyHeaders: false, // Disable `X-RateLimit-*` headers
  // Use conversation ID or IP as identifier
  keyGenerator: (req) => {
    return req.body?.conversationId || req.ip || 'unknown';
  },
});
