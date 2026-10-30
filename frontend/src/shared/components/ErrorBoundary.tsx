import { Component, useEffect, type ErrorInfo, type ReactNode } from 'react';
import type { ErrorComponentProps } from '@tanstack/react-router';
import { reportClientError } from '@/shared/lib/errorReporter';

// 8.6: a top-level Error Boundary (render-time exceptions) plus the router's default error component
// (route pages are caught by the router before they reach the boundary). Both report to the API (14.1).

function ErrorScreen({ onRetry, retryLabel }: { onRetry: () => void; retryLabel: string }) {
    return (
        <div
            role="alert"
            className="flex h-screen flex-col items-center justify-center gap-4 bg-[var(--color-paper)] p-8 text-center"
        >
            <h1 className="text-lg font-medium text-[var(--color-ink)]">Something went wrong</h1>
            <p className="max-w-md text-sm text-[var(--color-ink-muted)]">
                The page hit an unexpected error. It has been reported. Try again, or reload the app.
            </p>
            <div className="flex gap-2">
                <button
                    type="button"
                    onClick={onRetry}
                    className="rounded-md border border-[var(--color-border)] px-3 py-1.5 text-sm"
                >
                    {retryLabel}
                </button>
                <button
                    type="button"
                    onClick={() => window.location.reload()}
                    className="rounded-md border border-[var(--color-border)] px-3 py-1.5 text-sm"
                >
                    Reload
                </button>
            </div>
        </div>
    );
}

type BoundaryProps = { children: ReactNode };
type BoundaryState = { failed: boolean };

export class ErrorBoundary extends Component<BoundaryProps, BoundaryState> {
    constructor(props: BoundaryProps) {
        super(props);
        this.state = { failed: false };
    }

    static getDerivedStateFromError(): BoundaryState {
        return { failed: true };
    }

    override componentDidCatch(error: Error, info: ErrorInfo): void {
        reportClientError(error, 'render', info.componentStack ?? undefined);
    }

    override render(): ReactNode {
        if (!this.state.failed) return this.props.children;
        return <ErrorScreen onRetry={() => this.setState({ failed: false })} retryLabel="Try again" />;
    }
}

export function RouteErrorFallback({ error, reset }: ErrorComponentProps) {
    useEffect(() => {
        reportClientError(error, 'render');
    }, [error]);

    return <ErrorScreen onRetry={reset} retryLabel="Try again" />;
}
