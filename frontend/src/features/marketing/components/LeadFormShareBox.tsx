import { useState } from 'react';
import type { LeadForm } from '../schemas/leadForm';

/** Absolute link to the public page (the API only returns the path; the origin is the app's own). */
export function publicUrl(path: string): string {
  return `${window.location.origin}${path}`;
}

function CopyField({ label, value, multiline }: { label: string; value: string; multiline?: boolean }) {
  const [copied, setCopied] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    } catch {
      // Clipboard can be blocked (insecure origin): the field is selectable, so the user can copy by hand.
    }
  }

  return (
    <div className="space-y-1">
      <label className="text-xs font-medium text-[var(--color-ink-muted)]">{label}</label>
      <div className="flex gap-2">
        {multiline ? (
          <textarea
            readOnly
            rows={3}
            value={value}
            onFocus={(e) => e.currentTarget.select()}
            className="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-paper)] px-2 py-1.5 font-mono text-xs"
          />
        ) : (
          <input
            readOnly
            value={value}
            onFocus={(e) => e.currentTarget.select()}
            className="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-paper)] px-2 py-1.5 font-mono text-xs"
          />
        )}
        <button
          type="button"
          onClick={() => void copy()}
          className="shrink-0 self-start rounded-md border border-[var(--color-border)] px-3 py-1.5 text-xs hover:bg-[var(--color-surface)]"
        >
          {copied ? 'Copied' : 'Copy'}
        </button>
      </div>
    </div>
  );
}

export function LeadFormShareBox({ form }: { form: LeadForm }) {
  const url = publicUrl(form.public_path);
  const embed = `<iframe src="${url}" width="100%" height="520" style="border:0" title="${form.name.replace(/"/g, '&quot;')}"></iframe>`;

  return (
    <section className="space-y-3 rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-3">
      <h3 className="text-sm font-medium">Share this form</h3>
      {!form.is_active && (
        <p className="text-xs text-amber-700">This form is paused: the link shows a not-found page until it is active.</p>
      )}
      <CopyField label="Link" value={url} />
      <CopyField label="Embed on your website" value={embed} multiline />
    </section>
  );
}