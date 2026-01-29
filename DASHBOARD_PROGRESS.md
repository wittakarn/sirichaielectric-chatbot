# Dashboard Development Progress

## Completed Tasks ✅

### 1. Backend Architecture (Clean Code Structure)
- **Repository Layer** (`/repository/`)
  - `ConversationRepository.php` - Added `findRecentForMonitoring()` method
  - `MessageRepository.php` - Added `getLastNMessages()` method to get last 6 messages

- **Service Layer** (`/services/`)
  - `DashboardService.php` - Business logic for dashboard
    - `getRecentConversationsForGrid($conversationLimit = 6, $messageLimit = 6)` - Gets 6 conversations with their last 6 messages each
    - `getConversationWithMessages($conversationId)` - Gets full conversation with all messages

- **Controller Layer** (`/controllers/`)
  - `DashboardController.php` - API request handlers
    - `getMonitoringConversations()` - Returns recent conversations for grid
    - `getConversation($conversationId)` - Returns full conversation details

- **API Endpoints** (`/admin/api/`)
  - `monitoring.php` - GET endpoint for fetching monitoring data
    - Query params: `conversation_limit` (default: 6), `message_limit` (default: 6)
    - Returns: Array of conversations with recent messages

### 2. Frontend Setup (React + shadcn/ui)
- **Project Structure** (`/dashboard/`)
  - ✅ Vite + React + TypeScript initialized
  - ✅ shadcn/ui properly configured with Tailwind CSS v4
  - ✅ Path aliases configured (`@/` → `./src/`)
  - ✅ TypeScript configs updated (tsconfig.json, tsconfig.app.json)
  - ✅ Vite config updated with path resolution

- **UI Components Installed**
  - ✅ button
  - ✅ card
  - ✅ table
  - ✅ input
  - ✅ badge
  - ✅ scroll-area
  - ✅ separator

- **Dependencies Installed**
  - ✅ @tanstack/react-query (for API data fetching)
  - ✅ react-router-dom (for routing)
  - ✅ lucide-react (icons)
  - ✅ tailwindcss-animate
  - ✅ class-variance-authority
  - ✅ clsx
  - ✅ tailwind-merge

### 3. System Prompt Updates
- ✅ Updated category name format examples
- ✅ Clarified bracket handling: `[6.6.Z] ตู้เหล็ก ธรรมดา 5KSS 5K`
- ✅ Changed "3 products per category" to "at most 3 products per category"

## Next Steps 🚀

### UI Components to Build

#### 1. Main Dashboard Layout
File: `/dashboard/src/App.tsx`
- 3x2 Grid layout for 6 conversation cards
- Auto-refresh every 5-10 seconds using TanStack Query
- Dark/light mode toggle

#### 2. Conversation Card Component
File: `/dashboard/src/components/ConversationCard.tsx`
```tsx
interface ConversationCardProps {
  conversation: {
    conversation_id: string
    platform: 'api' | 'line'
    user_id: string | null
    is_chatbot_active: number
    message_count: number
    last_activity: number
    recent_messages: Message[]
  }
}
```

**Features:**
- Show platform badge (LINE/API)
- Show chatbot status (Active/Paused)
- Display last 6 messages in scrollable area
- User messages on right (blue), assistant on left (gray)
- Timestamp on each message
- Click to expand full conversation

#### 3. API Service Layer
File: `/dashboard/src/services/api.ts`
```typescript
const API_BASE = '/admin/api'

export const getMonitoringData = async () => {
  const response = await fetch(`${API_BASE}/monitoring.php?conversation_limit=6&message_limit=6`)
  return response.json()
}
```

#### 4. TanStack Query Setup
File: `/dashboard/src/main.tsx`
```typescript
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchInterval: 5000, // Auto-refresh every 5 seconds
    },
  },
})
```

#### 5. Message Display Component
File: `/dashboard/src/components/MessageBubble.tsx`
- User message: Right-aligned, blue background
- Assistant message: Left-aligned, gray background
- Truncate long messages with "..." (expand on hover)
- Show relative time (e.g., "2 mins ago")

## API Response Format

### GET `/admin/api/monitoring.php`
```json
{
  "success": true,
  "data": [
    {
      "conversation_id": "conv_123",
      "platform": "line",
      "user_id": "U1234567890",
      "is_chatbot_active": 1,
      "paused_at": null,
      "created_at": 1738195200,
      "last_activity": 1738199999,
      "message_count": 12,
      "recent_messages": [
        {
          "id": 1,
          "conversation_id": "conv_123",
          "role": "user",
          "content": "สวัสดีครับ",
          "timestamp": 1738199990,
          "tokens_used": 5,
          "sequence_number": 1
        },
        {
          "id": 2,
          "conversation_id": "conv_123",
          "role": "assistant",
          "content": "สวัสดีค่ะ มีอะไรให้ช่วยไหมคะ",
          "timestamp": 1738199995,
          "tokens_used": 15,
          "sequence_number": 2
        }
      ]
    }
  ],
  "timestamp": 1738200000
}
```

## Design Requirements

### Grid Layout
```
┌──────────┬──────────┬──────────┐
│  Chat 1  │  Chat 2  │  Chat 3  │
│          │          │          │
└──────────┴──────────┴──────────┘
┌──────────┬──────────┬──────────┐
│  Chat 4  │  Chat 5  │  Chat 6  │
│          │          │          │
└──────────┴──────────┴──────────┘
```

### Card Content (from top to bottom)
1. **Header**: Platform badge, Conversation ID, Status badge
2. **Message Area**: Scrollable chat bubbles (last 6 messages)
3. **Footer**: Message count, Last activity time

## Commands to Continue Development

```bash
# Navigate to dashboard
cd dashboard

# Start dev server
npm run dev

# Build for production
npm run build
```

## Notes

- Backend API is fully functional and tested
- All database queries are optimized (using repositories)
- Clean separation: Repository → Service → Controller → API
- Frontend just needs UI components connected to API
- Use TanStack Query for automatic polling/refresh
- shadcn/ui components are ready to use

## Files Modified Today

**Backend:**
- `/repository/ConversationRepository.php` - Added monitoring method
- `/repository/MessageRepository.php` - Added getLastNMessages
- `/services/DashboardService.php` - Created service layer
- `/controllers/DashboardController.php` - Created controller
- `/admin/api/monitoring.php` - Created API endpoint
- `/system-prompt.txt` - Updated product display rules

**Frontend:**
- `/dashboard/package.json` - Added dependencies
- `/dashboard/vite.config.ts` - Configured path aliases
- `/dashboard/tsconfig.json` - Added path mapping
- `/dashboard/tsconfig.app.json` - Added path mapping
- `/dashboard/tailwind.config.js` - Created Tailwind config
- `/dashboard/postcss.config.js` - Created PostCSS config
- `/dashboard/src/index.css` - Added Tailwind + shadcn styles
- `/dashboard/src/lib/utils.ts` - Added cn() utility
- `/dashboard/components.json` - shadcn configuration
