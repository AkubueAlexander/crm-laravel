import { useState } from 'react';

// 10.5: preview / download for a server-rendered PDF. The URL is a same-origin API path, so the session cookie
// authenticates the iframe and the download link; nothing is fetched until the user opens the preview.
// The PDF always shows the SAVED version of the document.
export function PdfPreview({ url, label = 'PDF' }: { url: string; label?: string }) {
    const [open, setOpen] = useState(false);

    return (
        <div className="mb-3">
            <div className="flex flex-wrap items-center gap-2">
                <button type="button" onClick={() => setOpen((o) => !o)} className="rounded-md border px-3 py-1.5 text-sm">
                    {open ? `Hide ${label} preview` : `Preview ${label}`}
                </button>
                <a href={`${url}?download=1`} className="rounded-md border px-3 py-1.5 text-sm">
                    Download {label}
                </a>
                <a href={url} target="_blank" rel="noreferrer" className="text-sm underline">
                    Open in new tab
                </a>
            </div>
            {open && <iframe title={`${label} preview`} src={url} className="mt-3 h-[720px] w-full rounded-md border border-[var(--color-border)] bg-white" />}
        </div>
    );
}
