import { useState } from 'react'
import { Calendar } from '@/components/ui/calendar'
import ConversationList from '@/components/ui/ConversationList'
import MessageList from '@/components/ui/MessageList'
import { formatDate } from '@/lib/utils'

export default function ChatDashboard() {
  const [selectedDate, setSelectedDate] = useState<Date>(new Date())
  const [selectedConversationId, setSelectedConversationId] = useState<string | null>(null)

  const dateStr = formatDate(selectedDate)

  return (
    <div className="flex h-screen bg-background">
      {/* Left: Calendar */}
      <div className="flex flex-col items-center border-r p-4">
        <h2 className="mb-3 text-sm font-semibold">Select Date</h2>
        <Calendar
          mode="single"
          selected={selectedDate}
          onSelect={(date) => {
            if (date) {
              setSelectedDate(date)
              setSelectedConversationId(null)
            }
          }}
        />
      </div>

      {/* Middle: Conversation List */}
      <ConversationList
        date={dateStr}
        selectedDate={selectedDate}
        selectedConversationId={selectedConversationId}
        onSelect={setSelectedConversationId}
      />

      {/* Right: Messages */}
      <div className="flex flex-1 flex-col">
        <MessageList conversationId={selectedConversationId} />
      </div>
    </div>
  )
}
