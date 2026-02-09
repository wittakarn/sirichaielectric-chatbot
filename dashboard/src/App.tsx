import './App.css'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import ChatDashboard from './components/ui/ChatDashboard'

const queryClient = new QueryClient()

function App() {
  return <QueryClientProvider client={queryClient}>
       <ChatDashboard />
     </QueryClientProvider>
}

export default App
