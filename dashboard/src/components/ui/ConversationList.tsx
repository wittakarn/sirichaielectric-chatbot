import { useQuery } from '@tanstack/react-query'
import { ScrollArea } from '@/components/ui/scroll-area'
import { getConversationsByDate, type Conversation } from '@/services/monitoringService'
import { cn, formatTime, truncate } from '@/lib/utils'
import { MessageSquare } from 'lucide-react'

interface ConversationListProps {
  date: string
  selectedDate: Date
  selectedConversationId: string | null
  onSelect: (conversationId: string) => void
}

export default function ConversationList({ date, selectedDate, selectedConversationId, onSelect }: ConversationListProps) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['conversations', date],
    queryFn: () => getConversationsByDate(date),
  })

  const conversations = data?.data ?? []

  return (
    <div className="flex w-80 flex-col border-r">
      <div className="border-b px-4 py-3">
        <h2 className="text-sm font-semibold">
          Conversations — {selectedDate.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' })}
        </h2>
      </div>

      <ScrollArea className="flex-1">
        {isLoading && (
          <div className="p-4 text-sm text-muted-foreground">Loading...</div>
        )}

        {error && (
          <div className="p-4 text-sm text-destructive">Failed to load conversations</div>
        )}

        {!isLoading && !error && conversations.length === 0 && (
          <div className="flex flex-col items-center gap-2 p-8 text-muted-foreground">
            <MessageSquare className="size-8" />
            <p className="text-sm">No conversations on this date</p>
          </div>
        )}

        {conversations.map((conv: Conversation) => (
          <button
            key={conv.conversation_id}
            onClick={() => onSelect(conv.conversation_id)}
            className={cn(
              'flex w-full flex-col gap-1 border-b px-4 py-3 text-left transition-colors hover:bg-accent',
              selectedConversationId === conv.conversation_id && 'bg-accent'
            )}
          >
            <div className="flex items-center justify-between">
              <span className="text-xs font-mono text-muted-foreground">
                {truncate(conv.conversation_id, 20)}
              </span>
              <span className={cn(
                'rounded-full px-2 py-0.5 text-[10px] font-medium',
                conv.platform === 'line' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700'
              )}>
                {conv.platform.toUpperCase()}
              </span>
            </div>
            <div className="flex items-center justify-between text-xs text-muted-foreground">
              <span>{conv.message_count} messages</span>
              <span>{formatTime(conv.last_activity)}</span>
            </div>
            {conv.first_message && (
              <p className="text-xs text-muted-foreground truncate">
                {truncate(conv.first_message, 50)}
              </p>
            )}
          </button>
        ))}
      </ScrollArea>
    </div>
  )
}
