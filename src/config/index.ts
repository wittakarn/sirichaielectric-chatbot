import dotenv from 'dotenv';

dotenv.config();

export const config = {
  gemini: {
    apiKey: process.env.GEMINI_API_KEY || '',
    model: process.env.GEMINI_MODEL || 'gemini-2.0-flash-exp',
    temperature: parseFloat(process.env.GEMINI_TEMPERATURE || '0.7'),
    maxTokens: parseInt(process.env.GEMINI_MAX_TOKENS || '2048'),
  },
  server: {
    port: parseInt(process.env.PORT || '3000'),
    env: process.env.NODE_ENV || 'development',
  },
  rateLimit: {
    maxRequestsPerMinute: parseInt(process.env.MAX_REQUESTS_PER_MINUTE || '15'),
    maxTokensPerMinute: parseInt(process.env.MAX_TOKENS_PER_MINUTE || '1000000'),
  },
  productData: {
    websiteUrl: process.env.WEBSITE_URL || 'https://shop.sirichaielectric.com/',
    apiEndpoint: process.env.PRODUCT_API_ENDPOINT || '',
    updateIntervalMinutes: parseInt(process.env.PRODUCT_UPDATE_INTERVAL_MINUTES || '60'),
  },
} as const;

export function validateConfig(): void {
  if (!config.gemini.apiKey) {
    throw new Error('GEMINI_API_KEY is required in .env file');
  }

  if (!config.productData.websiteUrl) {
    throw new Error('WEBSITE_URL is required in .env file');
  }
}
