import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { getConversationList } from '@/services/monitoringService';

const ChatDashboard: React.FC = () => {
    const { data, isLoading, error } = useQuery({
        queryKey: ['monitoring'],
        queryFn: getConversationList,
        refetchInterval: 10000, // Auto-refresh every 10 seconds
    })

    return <div>{JSON.stringify(data)}</div>;
}

export default ChatDashboard;