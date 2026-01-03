export interface ChatMessage {
  role: 'user' | 'assistant' | 'system';
  content: string;
  timestamp?: Date;
}

export interface ChatRequest {
  message: string;
  conversationId?: string;
  language?: 'th' | 'en' | 'auto';
}

export interface ChatResponse {
  success: boolean;
  response: string;
  conversationId: string;
  language?: string;
  error?: string;
}

export interface ConversationHistory {
  conversationId: string;
  messages: ChatMessage[];
  createdAt: Date;
  lastActivity: Date;
}
