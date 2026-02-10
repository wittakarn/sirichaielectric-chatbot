export const getConversationList = async () => {
  const response = await fetch(`${window.WEBSITE_URL}/admin/api/monitoring.php?conversation_limit=10&message_limit=10`)
  if (!response.ok) {
    throw new Error('Failed to fetch monitoring data')
  }
  return response.json()
}