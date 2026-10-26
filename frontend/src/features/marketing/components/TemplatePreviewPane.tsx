import { useMemo, useState } from 'react';
import type { TemplatePreview } from '@/features/marketing/api/useTemplatePreview';

type Props = {
  data: TemplatePreview | undefined;
  isPending: boolean;
  isFetching: boolean;
  isError: boolean;
};

const tabClass = 'rounded-md px-2 py-1 text-xs';

export function TemplatePreviewPane({ data, isPending, isFetching, isError }: Props) {
  const [tab, setTab] = useState<'email' | 'text'>('email');

  // The preview HTML comes from the API with raw HTML already stripped. It still renders in a
  // sandboxed iframe with no scripts and no same-origin access; links open in a new tab.
  const srcDoc = useMemo(
    () => (data ? data.html.replace('<head>', '<head><base target="_blank">') : ''),
    [data],
  );

  return (
    <section
      aria-label="Preview"
      className="space-y-3 rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
    >
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-medium text-[var(--color-ink)]">Live preview</h2>
        <div className="flex items-center gap-2">
          {isFetching ? <span className="text-xs text-[var(--color-ink-muted)]">Updating...</span> : null}
          <div className="flex gap-1" role="tablist" aria-label="Preview format">
            <button
              type="button"
              role="tab"
              aria-selected={tab === 'email'}
              onClick={() => setTab('email')}
              className={`${tabClass} ${tab === 'email' ? 'bg-[var(--color-ink)] text-[var(--color-paper)]' : 'border'}`}
            >
              Email
            </button>
            <button
              type="button"
              role="tab"
              aria-selected={tab === 'text'}
              onClick={() => setTab('text')}
              className={`${tabClass} ${tab === 'text' ? 'bg-[var(--color-ink)] text-[var(--color-paper)]' : 'border'}`}
            >
              Plain text
            </button>
          </div>
        </div>
      </div>

      {isPending ? (
        <p className="text-sm text-[var(--color-ink-muted)]">Rendering...</p>
      ) : isError && !data ? (
        <p className="text-sm text-[var(--color-ink-muted)]">Preview is unavailable right now.</p>
      ) : data ? (
        <div className="space-y-3">
          <p className="text-sm text-[var(--color-ink)]">
            <span className="text-[var(--color-ink-muted)]">Subject: </span>
            {data.subject === '' ? <span className="text-[var(--color-ink-muted)]">(empty)</span> : data.subject}
          </p>

          {data.unknown_variables.length > 0 ? (
            <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
              Unknown variable(s) render empty and block saving:{' '}
              {data.unknown_variables.map((name) => `{{ ${name} }}`).join(', ')}
            </p>
          ) : null}

          {tab === 'email' ? (
            <iframe
              title="Email preview"
              sandbox="allow-popups allow-popups-to-escape-sandbox"
              srcDoc={srcDoc}
              className="h-[28rem] w-full rounded-md border border-[var(--color-border)] bg-white"
            />
          ) : (
            <pre className="h-[28rem] overflow-auto whitespace-pre-wrap rounded-md border border-[var(--color-border)] bg-[var(--color-paper)] p-3 text-sm text-[var(--color-ink)]">
              {data.text === '' ? '(empty)' : data.text}
            </pre>
          )}

          <p className="text-xs text-[var(--color-ink-muted)]">
            Shown with sample contact data. Unsubscribe and footer details are added when a campaign sends (9.4).
          </p>
        </div>
      ) : null}
    </section>
  );
}