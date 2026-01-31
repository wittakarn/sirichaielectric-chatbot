export const getConversationList = async () => {
  const response = await fetch(`${API_BASE}/monitoring.php?conversation_limit=6&message_limit=6`)
  if (!response.ok) {
    throw new Error('Failed to fetch monitoring data')
  }
  return response.json()
}