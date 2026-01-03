import { GoogleGenerativeAI } from '@google/generative-ai';
import { ChatMessage, ChatRequest, ChatResponse, ConversationHistory, GeminiConfig } from '../types';

export class SirichaiChatbot {
  private genAI: GoogleGenerativeAI;
  private model: any;
  private conversations: Map<string, ConversationHistory>;
  private systemPrompt: string;

  constructor(config: GeminiConfig) {
    this.genAI = new GoogleGenerativeAI(config.apiKey);
    this.model = this.genAI.getGenerativeModel({
      model: config.model || 'gemini-2.0-flash-exp',
      generationConfig: {
        temperature: config.temperature || 0.7,
        maxOutputTokens: config.maxTokens || 2048,
        topP: config.topP || 0.95,
        topK: config.topK || 40,
      },
    });
    this.conversations = new Map();
    this.systemPrompt = this.buildSystemPrompt();
  }

  private buildSystemPrompt(): string {
    return `You are a helpful and knowledgeable customer service assistant for Sirichai Electric (ศิริชัยอิเล็คทริค), a leading electrical equipment supplier in Thailand.

COMPANY INFORMATION:
- Website: https://shop.sirichaielectric.com/
- Business: Electrical equipment and industrial products supplier
- Specialties: Electrical wiring, circuit protection, LED lighting, solar/EV equipment, industrial supplies

PRODUCT CATEGORIES:
- Electrical Wires and Cables (Yazaki, Helukabel)
- Circuit Breakers and Contactors (Mitsubishi, Schneider, ABB)
- LED Lights and Fixtures (Philips, Panasonic)
- Cable Management Systems
- Solar and EV Charging Equipment
- Control Equipment and Switches

YOUR ROLE:
1. Answer questions about electrical products, specifications, and applications
2. Help customers find the right products for their needs
3. Provide technical guidance on electrical equipment
4. Support both Thai and English languages fluently
5. Be professional, friendly, and informative

LANGUAGE HANDLING:
- Detect the customer's language (Thai or English) from their message
- Respond in the same language they use
- If they use Thai, respond in Thai (ตอบเป็นภาษาไทย)
- If they use English, respond in English
- Use proper technical terms in both languages

GUIDELINES:
- Provide accurate information about electrical products and applications
- For specific product availability, specifications, or pricing, suggest visiting the website or contacting sales
- Recommend appropriate products based on customer needs and applications
- Explain technical specifications in a clear, understandable way
- Be honest if you don't have specific information - offer to connect them with a specialist
- Focus on helping customers make informed decisions

TONE:
- Professional yet approachable
- Patient and helpful
- Clear and concise
- Technical when needed, but always understandable

Remember: You represent a trusted electrical supplier. Build confidence and provide value in every interaction.`;
  }

  private detectLanguage(message: string): 'th' | 'en' {
    // Simple Thai character detection
    const thaiPattern = /[\u0E00-\u0E7F]/;
    return thaiPattern.test(message) ? 'th' : 'en';
  }

  private getOrCreateConversation(conversationId: string): ConversationHistory {
    if (!this.conversations.has(conversationId)) {
      this.conversations.set(conversationId, {
        conversationId,
        messages: [],
        createdAt: new Date(),
        lastActivity: new Date(),
      });
    }
    return this.conversations.get(conversationId)!;
  }

  private buildContextMessage(history: ChatMessage[]): string {
    if (history.length === 0) return '';

    return '\n\nPREVIOUS CONVERSATION:\n' +
      history.map(msg => `${msg.role === 'user' ? 'Customer' : 'Assistant'}: ${msg.content}`).join('\n');
  }

  async chat(request: ChatRequest): Promise<ChatResponse> {
    try {
      const conversationId = request.conversationId || this.generateConversationId();
      const conversation = this.getOrCreateConversation(conversationId);
      const language = request.language === 'auto' ? this.detectLanguage(request.message) : (request.language || 'auto');

      // Add user message to history
      const userMessage: ChatMessage = {
        role: 'user',
        content: request.message,
        timestamp: new Date(),
      };
      conversation.messages.push(userMessage);

      // Build the full prompt with system context and conversation history
      const recentHistory = conversation.messages.slice(-10); // Keep last 10 messages for context
      const contextMessage = this.buildContextMessage(recentHistory.slice(0, -1));
      const fullPrompt = `${this.systemPrompt}${contextMessage}\n\nCUSTOMER MESSAGE:\n${request.message}`;

      // Generate response
      const result = await this.model.generateContent(fullPrompt);
      const response = result.response;
      const responseText = response.text();

      // Add assistant message to history
      const assistantMessage: ChatMessage = {
        role: 'assistant',
        content: responseText,
        timestamp: new Date(),
      };
      conversation.messages.push(assistantMessage);
      conversation.lastActivity = new Date();

      return {
        success: true,
        response: responseText,
        conversationId,
        language: language === 'auto' ? this.detectLanguage(request.message) : language,
      };
    } catch (error: any) {
      console.error('[Chatbot] Error:', error);
      return {
        success: false,
        response: '',
        conversationId: request.conversationId || '',
        error: error.message || 'An error occurred while processing your message',
      };
    }
  }

  async streamChat(request: ChatRequest, onChunk: (chunk: string) => void): Promise<ChatResponse> {
    try {
      const conversationId = request.conversationId || this.generateConversationId();
      const conversation = this.getOrCreateConversation(conversationId);

      const userMessage: ChatMessage = {
        role: 'user',
        content: request.message,
        timestamp: new Date(),
      };
      conversation.messages.push(userMessage);

      const recentHistory = conversation.messages.slice(-10);
      const contextMessage = this.buildContextMessage(recentHistory.slice(0, -1));
      const fullPrompt = `${this.systemPrompt}${contextMessage}\n\nCUSTOMER MESSAGE:\n${request.message}`;

      // Generate streaming response
      const result = await this.model.generateContentStream(fullPrompt);
      let fullResponse = '';

      for await (const chunk of result.stream) {
        const chunkText = chunk.text();
        fullResponse += chunkText;
        onChunk(chunkText);
      }

      const assistantMessage: ChatMessage = {
        role: 'assistant',
        content: fullResponse,
        timestamp: new Date(),
      };
      conversation.messages.push(assistantMessage);
      conversation.lastActivity = new Date();

      return {
        success: true,
        response: fullResponse,
        conversationId,
        language: this.detectLanguage(request.message),
      };
    } catch (error: any) {
      console.error('[Chatbot] Stream error:', error);
      return {
        success: false,
        response: '',
        conversationId: request.conversationId || '',
        error: error.message || 'An error occurred while streaming your message',
      };
    }
  }

  getConversationHistory(conversationId: string): ConversationHistory | undefined {
    return this.conversations.get(conversationId);
  }

  clearConversation(conversationId: string): boolean {
    return this.conversations.delete(conversationId);
  }

  clearAllConversations(): void {
    this.conversations.clear();
  }

  private generateConversationId(): string {
    return `conv_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
  }

  // Cleanup old conversations (older than specified hours)
  cleanupOldConversations(maxAgeHours: number = 24): number {
    const now = new Date();
    const maxAge = maxAgeHours * 60 * 60 * 1000;
    let cleaned = 0;

    for (const [id, conv] of this.conversations.entries()) {
      const age = now.getTime() - conv.lastActivity.getTime();
      if (age > maxAge) {
        this.conversations.delete(id);
        cleaned++;
      }
    }

    return cleaned;
  }
}
