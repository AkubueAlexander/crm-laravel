import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { PublicLeadForm, Unavailable } from './PublicLeadForm';
import '../index.css';

// 9.8c: standalone, UNAUTHENTICATED entry. No router, no auth, no tenant hooks, no axios apiClient.
// Public data has no tenant namespace (the form uuid is the only key), so keys are ['public-form', uuid].
const queryClient = new QueryClient();

const match = window.location.pathname.match(
    /^\/f\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\/?$/i,
);

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            {match ? <PublicLeadForm uuid={match[1]} /> : <Unavailable />}
        </QueryClientProvider>
    </StrictMode>,
);
