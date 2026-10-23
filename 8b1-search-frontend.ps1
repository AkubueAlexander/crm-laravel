Write-Host '8b.1 global search (frontend) v1'
$ErrorActionPreference = 'Stop'
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Write-NoBom {
    param([string]$Path, [string]$Content)
    $full = Join-Path (Get-Location).Path $Path
    $dir = Split-Path $full -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $eol = "`n"
    if (Test-Path $full) {
        $existing = [System.IO.File]::ReadAllText($full)
        if ($existing.Contains("`r`n")) { $eol = "`r`n" }
    }
    $text = ($Content -replace "`r`n", "`n") -replace "`r", "`n"
    if (-not $text.EndsWith("`n")) { $text += "`n" }
    if ($eol -eq "`r`n") { $text = $text -replace "`n", "`r`n" }
    [System.IO.File]::WriteAllText($full, $text, $utf8)
    Write-Host "wrote $Path"
}

function Patch-Once {
    param([string]$Path, [string]$Old, [string]$New, [string]$Marker)
    $full = Join-Path (Get-Location).Path $Path
    if (-not (Test-Path $full)) { throw "Missing file: $Path" }
    $raw = [System.IO.File]::ReadAllText($full)
    $crlf = $raw.Contains("`r`n")
    $norm = $raw -replace "`r`n", "`n"
    if ($norm.Contains($Marker)) { Write-Host "skip $Path (already patched: $Marker)"; return }
    $o = ($Old -replace "`r`n", "`n").Trim("`n")
    $n = ($New -replace "`r`n", "`n").Trim("`n")
    $idx = $norm.IndexOf($o)
    if ($idx -lt 0) { throw "Anchor not found in $Path : $o" }
    if ($norm.IndexOf($o, $idx + 1) -ge 0) { throw "Anchor not unique in $Path : $o" }
    $out = $norm.Substring(0, $idx) + $n + $norm.Substring($idx + $o.Length)
    if ($crlf) { $out = $out -replace "`n", "`r`n" }
    [System.IO.File]::WriteAllText($full, $out, $utf8)
    Write-Host "patched $Path"
}

# ---------------------------------------------------------------- schema
Write-NoBom -Path 'frontend\src\features\search\schemas\search.ts' -Content @'
import { z } from 'zod';

// Mirrors SearchController (app/Http/Controllers/Api/Search). A group the actor
// lacks the *.view permission for is absent from `data`, hence optional.
export const SEARCH_GROUP_KEYS = ['leads', 'contacts', 'accounts', 'deals'] as const;
export type SearchGroupKey = (typeof SEARCH_GROUP_KEYS)[number];

export const searchItemSchema = z.object({
    id: z.number(),
    label: z.string(),
    sublabel: z.string().nullable(),
});

export type SearchItem = z.infer<typeof searchItemSchema>;

export const searchResponseSchema = z.object({
    data: z.object({
        leads: z.array(searchItemSchema).optional(),
        contacts: z.array(searchItemSchema).optional(),
        accounts: z.array(searchItemSchema).optional(),
        deals: z.array(searchItemSchema).optional(),
    }),
    query: z.string(),
});

export type SearchResponse = z.infer<typeof searchResponseSchema>;

export const SEARCH_MIN_LENGTH = 2;
'@

# ---------------------------------------------------------------- hook
Write-NoBom -Path 'frontend\src\features\search\api\useGlobalSearch.ts' -Content @'
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { SEARCH_MIN_LENGTH, searchResponseSchema } from '../schemas/search';

// 8b.1: pass an already-debounced term (useDebouncedValue, 300ms). The key is
// tenant-namespaced (1.2) so a tenant switch can never show another tenant's hits.
export function useGlobalSearch(term: string) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: ['search', tenant?.id, term],
        queryFn: async ({ signal }) => {
            const res = await apiClient.get('/search', { params: { q: term }, signal });
            return searchResponseSchema.parse(res.data);
        },
        enabled: !!tenant && term.length >= SEARCH_MIN_LENGTH,
        staleTime: 30_000,
        placeholderData: keepPreviousData,
    });
}
'@

# ---------------------------------------------------------------- component
Write-NoBom -Path 'frontend\src\features\search\components\GlobalSearch.tsx' -Content @'
import { Fragment, useEffect, useMemo, useRef, useState, type KeyboardEvent as ReactKeyboardEvent, type ReactNode } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { useUiStore } from '@/shared/stores/ui';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { useGlobalSearch } from '../api/useGlobalSearch';
import { SEARCH_MIN_LENGTH, type SearchGroupKey, type SearchItem } from '../schemas/search';

const MODAL_ID = 'global-search';

// Group order is display order. There are no record detail routes yet, so picking a
// hit opens that section's list (deep links arrive with URL-persisted filters, 8.7).
const SEARCH_GROUPS: ReadonlyArray<{
    key: SearchGroupKey;
    label: string;
    to: '/leads' | '/contacts' | '/accounts' | '/deals';
}> = [
    { key: 'leads', label: 'Leads', to: '/leads' },
    { key: 'contacts', label: 'Contacts', to: '/contacts' },
    { key: 'accounts', label: 'Accounts', to: '/accounts' },
    { key: 'deals', label: 'Deals', to: '/deals' },
];

type Row = { group: (typeof SEARCH_GROUPS)[number]; item: SearchItem };

// Header trigger + Ctrl/Cmd+K hotkey. Open state is UI-only, so it lives in Zustand (8.1).
export function GlobalSearch() {
    const open = useUiStore((s) => s.activeModal === MODAL_ID);
    const openModal = useUiStore((s) => s.openModal);

    useEffect(() => {
        function onKeyDown(e: KeyboardEvent) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                openModal(MODAL_ID);
            }
        }
        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [openModal]);

    return (
        <>
            <button
                type="button"
                onClick={() => openModal(MODAL_ID)}
                className="flex items-center gap-3 rounded-md border border-[var(--color-border)] px-3 py-1.5 text-sm text-[var(--color-ink-muted)] hover:bg-[var(--color-paper)]"
            >
                <span>Search...</span>
                <kbd className="rounded border border-[var(--color-border)] px-1 text-xs">Ctrl K</kbd>
            </button>
            {open && <SearchPalette />}
        </>
    );
}

// Mounted only while open, so the typed query resets every time it closes.
function SearchPalette() {
    const closeModal = useUiStore((s) => s.closeModal);
    const navigate = useNavigate();
    const inputRef = useRef<HTMLInputElement>(null);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);

    const trimmed = query.trim();
    const term = useDebouncedValue(trimmed, 300);
    const { data, isError, isFetching } = useGlobalSearch(term);

    useEffect(() => {
        inputRef.current?.focus();
    }, []);

    // Derived from the query cache, never copied into state (8.1).
    const rows = useMemo<Row[]>(() => {
        if (term.length < SEARCH_MIN_LENGTH || !data) return [];
        return SEARCH_GROUPS.flatMap((group) =>
            (data.data[group.key] ?? []).map((item) => ({ group, item })),
        );
    }, [data, term]);

    const activeIndex = Math.min(active, Math.max(rows.length - 1, 0));

    function choose(row: Row) {
        closeModal();
        void navigate({ to: row.group.to });
    }

    function onKeyDown(e: ReactKeyboardEvent<HTMLInputElement>) {
        if (e.key === 'Escape') {
            e.preventDefault();
            closeModal();
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive(Math.min(activeIndex + 1, rows.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(Math.max(activeIndex - 1, 0));
        } else if (e.key === 'Enter') {
            const current = rows[activeIndex];
            if (current) {
                e.preventDefault();
                choose(current);
            }
        }
    }

    const tooShort = trimmed.length < SEARCH_MIN_LENGTH;
    const settling = isFetching || term !== trimmed;

    let body: ReactNode;
    if (tooShort) {
        body = <p className="px-4 py-6 text-center text-sm text-[var(--color-ink-muted)]">Type at least {SEARCH_MIN_LENGTH} characters.</p>;
    } else if (isError && rows.length === 0) {
        body = <p className="px-4 py-6 text-center text-sm text-red-600">Search failed. Try again.</p>;
    } else if (rows.length === 0) {
        body = (
            <p className="px-4 py-6 text-center text-sm text-[var(--color-ink-muted)]">
                {settling ? 'Searching...' : `No results for "${term}".`}
            </p>
        );
    } else {
        body = (
            <ul role="listbox" aria-label="Search results" className="max-h-96 overflow-auto py-2">
                {rows.map((row, i) => (
                    <Fragment key={`${row.group.key}-${row.item.id}`}>
                        {rows[i - 1]?.group.key !== row.group.key && (
                            <li role="presentation" className="px-4 pb-1 pt-2 text-xs font-medium uppercase tracking-wide text-[var(--color-ink-muted)]">
                                {row.group.label}
                            </li>
                        )}
                        <li role="presentation">
                            <button
                                type="button"
                                role="option"
                                aria-selected={i === activeIndex}
                                onMouseDown={(e) => e.preventDefault()}
                                onMouseEnter={() => setActive(i)}
                                onClick={() => choose(row)}
                                className={`flex w-full flex-col px-4 py-2 text-left ${i === activeIndex ? 'bg-[var(--color-paper)]' : ''}`}
                            >
                                <span className="text-sm font-medium">{row.item.label}</span>
                                {row.item.sublabel && (
                                    <span className="text-xs text-[var(--color-ink-muted)]">{row.item.sublabel}</span>
                                )}
                            </button>
                        </li>
                    </Fragment>
                ))}
            </ul>
        );
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-start justify-center bg-black/40 p-4 pt-24"
            onMouseDown={(e) => {
                if (e.target === e.currentTarget) closeModal();
            }}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label="Global search"
                className="w-full max-w-xl overflow-hidden rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink)] shadow-lg"
            >
                <input
                    ref={inputRef}
                    value={query}
                    onChange={(e) => {
                        setQuery(e.target.value);
                        setActive(0);
                    }}
                    onKeyDown={onKeyDown}
                    placeholder="Search leads, contacts, accounts, deals..."
                    aria-label="Search"
                    maxLength={100}
                    className="w-full border-b border-[var(--color-border)] bg-transparent px-4 py-3 text-sm outline-none"
                />
                {body}
            </div>
        </div>
    );
}
'@

# ---------------------------------------------------------------- AppShell wiring
Patch-Once -Path 'frontend\src\shared\components\AppShell.tsx' -Marker "import { GlobalSearch }" `
    -Old @'
import { useCurrentUser } from '@/features/auth/api/useCurrentUser';
'@ `
    -New @'
import { useCurrentUser } from '@/features/auth/api/useCurrentUser';
import { GlobalSearch } from '@/features/search/components/GlobalSearch';
'@

Patch-Once -Path 'frontend\src\shared\components\AppShell.tsx' -Marker '<GlobalSearch />' `
    -Old @'
<div />
'@ `
    -New @'
<GlobalSearch />
'@

Write-Host 'Done. Not shown to me / not touched: nothing else was modified. Hits open the section list (no record detail routes or URL-persisted filters yet, 8.7). Ctrl+K may be claimed by the browser in some setups (Firefox focuses its search bar); the header button always works.'
