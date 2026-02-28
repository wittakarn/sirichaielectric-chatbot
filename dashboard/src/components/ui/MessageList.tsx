import { useQuery } from '@tanstack/react-query'
import { ScrollArea } from '@/components/ui/scroll-area'
import { getConversationMessages, type Message } from '@/services/monitoringService'
import { cn, formatTime } from '@/lib/utils'
import { MessageSquare } from 'lucide-react'

interface MessageListProps {
  conversationId: string | null
}

export default function MessageList({ conversationId }: MessageListProps) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['conversation', conversationId],
    queryFn: () => getConversationMessages(conversationId!),
    enabled: !!conversationId,
  })

  if (!conversationId) {
    return (
      <div className="flex flex-1 items-center justify-center text-muted-foreground">
        <div className="flex flex-col items-center gap-2">
          <MessageSquare className="size-10" />
          <p className="text-sm">Select a conversation to view messages</p>
        </div>
      </div>
    )
  }

  if (isLoading) {
    return <div className="p-4 text-sm text-muted-foreground">Loading messages...</div>
  }

  if (error) {
    return <div className="p-4 text-sm text-destructive">Failed to load messages</div>
  }

  const messages = data?.data?.messages ?? []

  return (
    <>
      <div className="border-b px-4 py-3">
        <h2 className="text-sm font-semibold font-mono">{conversationId}</h2>
      </div>

      <ScrollArea className="flex-1 p-4">
        <div className="flex flex-col gap-3">
          {messages.map((msg: Message) => (
            <div
              key={msg.id}
              className={cn(
                'flex flex-col gap-1 max-w-[75%]',
                msg.role === 'user' ? 'self-end items-end' : 'self-start items-start'
              )}
            >
              <div className={cn(
                'rounded-lg px-3 py-2 text-sm whitespace-pre-wrap',
                msg.role === 'user'
                  ? 'bg-primary text-primary-foreground'
                  : 'bg-muted'
              )}>
                {msg.content}
              </div>
              <span className="text-[10px] text-muted-foreground">
                {formatTime(msg.timestamp)}
              </span>
            </div>
          ))}
        </div>
      </ScrollArea>
    </>
  )
}
