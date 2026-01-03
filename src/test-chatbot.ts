import { config, validateConfig } from './config';
import { SirichaiChatbot } from './services/chatbot';
import { ProductFetcher } from './services/product-fetcher';

/**
 * Simple test script to verify chatbot functionality
 */

async function testChatbot() {
  console.log('🧪 Testing Sirichai Electric Chatbot\n');

  try {
    // Validate config
    validateConfig();
    console.log('✅ Configuration validated\n');

    // Initialize product fetcher
    const productFetcher = new ProductFetcher(config.productData);
    await productFetcher.start();
    console.log('✅ Product fetcher initialized\n');

    // Initialize chatbot
    const chatbot = new SirichaiChatbot({
      apiKey: config.gemini.apiKey,
      model: config.gemini.model,
      temperature: config.gemini.temperature,
      maxTokens: config.gemini.maxTokens,
    });
    console.log('✅ Chatbot initialized\n');

    // Test cases
    const testCases = [
      {
        message: 'What circuit breakers do you have from Mitsubishi?',
        language: 'en' as const,
      },
      {
        message: 'มีสายไฟ Yazaki ไหมครับ',
        language: 'th' as const,
      },
      {
        message: 'What are the benefits of LED lighting?',
        language: 'en' as const,
      },
    ];

    console.log('🔄 Running test cases...\n');

    for (const testCase of testCases) {
      console.log(`📝 Question: ${testCase.message}`);
      console.log(`🌐 Language: ${testCase.language}`);

      const response = await chatbot.chat({
        message: testCase.message,
        language: 'auto',
      });

      if (response.success) {
        console.log(`✅ Response: ${response.response.substring(0, 200)}...`);
        console.log(`🆔 Conversation ID: ${response.conversationId}`);
      } else {
        console.log(`❌ Error: ${response.error}`);
      }
      console.log('---\n');
    }

    console.log('✅ All tests completed!');

    // Cleanup
    productFetcher.stop();
    process.exit(0);

  } catch (error: any) {
    console.error('❌ Test failed:', error.message);
    process.exit(1);
  }
}

// Run tests
testChatbot();
