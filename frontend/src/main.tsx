import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from '@tanstack/react-router';
import { queryClient } from '@/shared/lib/queryClient';
import { ErrorBoundary } from '@/shared/components/ErrorBoundary';
import { installGlobalErrorReporting } from '@/shared/lib/errorReporter';
import { router } from '@/router';
import './index.css';

installGlobalErrorReporting();

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <ErrorBoundary>
            <QueryClientProvider client={queryClient}>
                <RouterProvider router={router} />
            </QueryClientProvider>
        </ErrorBoundary>
    </StrictMode>,
);
