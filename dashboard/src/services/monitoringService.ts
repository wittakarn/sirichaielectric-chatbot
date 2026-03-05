declare global {
  interface Window {
    WEBSITE_URL: string
  }
}

export interface Message {
  id: number
  conversation_id: string
  role: 'user' | 'assistant'
  content: string
  timestamp: number
  tokens_used: number
  sequence_number: number
}

export interface Conversation {
  conversation_id: string
  platform: 'api' | 'line'
  user_id: string | null
  is_chatbot_active: number
  paused_at: number | null
  created_at: number
  last_activity: number
  message_count: number
  first_message: string | null
}

export interface ConversationWithMessages {
  conversation_id: string
  platform: 'api' | 'line'
  user_id: string | null
  is_chatbot_active: number
  paused_at: number | null
  created_at: number
  last_activity: number
  messages: Message[]
}

export const getConversationList = async () => {
  const response = await fetch(`${window.WEBSITE_URL}/admin/api/monitoring.php?conversation_limit=10&message_limit=10`)
  if (!response.ok) {
    throw new Error('Failed to fetch monitoring data')
  }
  return response.json()
}

export const getConversationsByDate = async (date: string): Promise<{ success: boolean; data: Conversation[] }> => {
  const response = await fetch(`${window.WEBSITE_URL}/admin/api/conversations.php?date=${date}`)
  if (!response.ok) {
    throw new Error('Failed to fetch conversations')
  }
  return response.json()
}

export const getConversationMessages = async (conversationId: string): Promise<{ success: boolean; data: ConversationWithMessages }> => {
  const response = await fetch(`${window.WEBSITE_URL}/admin/api/conversations.php?conversation_id=${encodeURIComponent(conversationId)}`)
  if (!response.ok) {
    throw new Error('Failed to fetch conversation messages')
  }
  return response.json()
}
