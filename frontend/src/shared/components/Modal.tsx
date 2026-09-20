import { type ReactNode, useEffect } from 'react';

type ModalProps = {
    open: boolean;
    onClose: () => void;
    title: string;
    children: ReactNode;
};

// Minimal, reusable shell â€” 8c.1/9.2/10.1 will all want this eventually too.
export function Modal({ open, onClose, title, children }: ModalProps) {
    useEffect(() => {
        if (!open) return;
        function onKeyDown(e: KeyboardEvent) {
            if (e.key === 'Escape') onClose();
        }
        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [open, onClose]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div
                role="dialog"
                aria-modal="true"
                aria-label={title}
                className="w-full max-w-lg rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-6 shadow-lg"
            >
                <div className="mb-4 flex items-center justify-between">
                    <h2 className="text-base font-medium text-[var(--color-ink)]">{title}</h2>
                    <button
                        onClick={onClose}
                        aria-label="Close"
                        className="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        &times;
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}