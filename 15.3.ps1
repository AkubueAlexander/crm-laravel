$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw 'artisan not found' }
Set-Location $repo
$fe = Join-Path $repo 'frontend'
$ci = Join-Path $repo '.github\workflows\ci.yml'
foreach ($p in @((Join-Path $fe 'package.json'), $ci, (Join-Path $fe 'src\shared\hooks\useEchoChannel.ts'), (Join-Path $fe 'src\features\deals\api\useMoveDealStage.ts'), (Join-Path $fe 'src\shared\lib\applyServerErrors.ts'))) {
    if (-not (Test-Path $p)) { throw "Missing prerequisite: $p" }
}

$report = New-Object System.Collections.ArrayList
function Add-Report([string]$t) { [void]$report.Add($t) }
function Tail([string]$text, [int]$n) {
    $lines = $text -split "`r?`n"
    if ($lines.Count -le $n) { return ($lines -join "`n") }
    return (($lines | Select-Object -Last $n) -join "`n")
}
function Finish {
    $out = $report -join "`n"
    Write-Host $out
    Set-Clipboard -Value $out
    Write-Host 'Report copied'
    Read-Host 'Press Enter to close'
}
function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = Split-Path $Path -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $c = $Content.Replace("`r`n", "`n").TrimEnd("`n") + "`n"
    [System.IO.File]::WriteAllText($Path, $c, (New-Object System.Text.UTF8Encoding($false)))
}
function Read-Text([string]$p) { return [System.IO.File]::ReadAllText($p, [System.Text.Encoding]::UTF8) }
function Count-Sub([string]$text, [string]$sub) {
    $n = 0; $i = 0
    while (($i = $text.IndexOf($sub, $i, [StringComparison]::Ordinal)) -ge 0) { $n++; $i += $sub.Length }
    return $n
}
function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    $raw = Read-Text $Path
    $crlf = $raw.Contains("`r`n")
    $t = $raw.Replace("`r`n", "`n")
    if ($Skip -and $t.Contains($Skip)) { return 'skipped' }
    if ((Count-Sub $t $Anchor) -ne 1) { throw "Anchor not unique in ${Path}: $Anchor" }
    $t = $t.Replace($Anchor, $Replacement)
    if ($crlf) { $t = $t.Replace("`n", "`r`n") }
    [System.IO.File]::WriteAllText($Path, $t, (New-Object System.Text.UTF8Encoding($false)))
    return 'patched'
}

# ------------------------------------------------------------ files
$files = [ordered]@{}

$files['frontend\vitest.config.ts'] = @'
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';

// 15.3: kept separate from vite.config.ts (which carries the multi-entry build and the dev rewrite plugin).
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        setupFiles: ['./src/test/setup.ts'],
        include: ['src/**/*.test.{ts,tsx}'],
    },
});
'@

$files['frontend\src\test\server.ts'] = @'
import { setupServer } from 'msw/node';

// 15.3: one MSW server for the whole test run. Tests add handlers with server.use(...); setup.ts resets them.
export const server = setupServer();
'@

$files['frontend\src\test\setup.ts'] = @'
import { afterAll, afterEach, beforeAll } from 'vitest';
import { cleanup } from '@testing-library/react';
import { server } from './server';

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));

afterEach(() => {
    cleanup();
    server.resetHandlers();
});

afterAll(() => server.close());
'@

$files['frontend\src\test\queryWrapper.tsx'] = @'
import type { ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

// 15.3: a fresh client per test (no retries, nothing garbage-collected mid-test).
export function createTestQueryClient(): QueryClient {
    return new QueryClient({
        defaultOptions: {
            queries: { retry: false, gcTime: Infinity },
            mutations: { retry: false },
        },
    });
}

export function queryWrapper(client: QueryClient) {
    return function Wrapper({ children }: { children: ReactNode }) {
        return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
    };
}
'@

$files['frontend\src\shared\hooks\useEchoChannel.test.tsx'] = @'
import { renderHook } from '@testing-library/react';
import { useQueryClient } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createTestQueryClient, queryWrapper } from '@/test/queryWrapper';
import { useEchoChannel } from './useEchoChannel';

// A fake Echo that records listeners per channel+event, so a test can emit a "broadcast".
const fake = vi.hoisted(() => {
    type H = (payload: unknown) => void;
    const listeners = new Map<string, H[]>();
    const keyOf = (channel: string, event: string) => channel + '|' + event;

    const channelApi = (channel: string) => ({
        listen(event: string, handler: H) {
            const key = keyOf(channel, event);
            listeners.set(key, [...(listeners.get(key) ?? []), handler]);
            return this;
        },
        stopListening(event: string, handler?: H) {
            const key = keyOf(channel, event);
            listeners.set(
                key,
                (listeners.get(key) ?? []).filter((h) => h !== handler),
            );
            return this;
        },
    });

    return {
        listeners,
        keyOf,
        echo: { private: vi.fn((channel: string) => channelApi(channel)), leave: vi.fn() },
        emit(channel: string, event: string, payload: unknown) {
            for (const handler of listeners.get(keyOf(channel, event)) ?? []) handler(payload);
        },
    };
});

vi.mock('@/shared/lib/echo', () => ({ echo: fake.echo }));

beforeEach(() => {
    fake.listeners.clear();
    fake.echo.private.mockClear();
    fake.echo.leave.mockClear();
});

describe('useEchoChannel', () => {
    it('delivers payloads to the latest callback without resubscribing', () => {
        const first = vi.fn();
        const second = vi.fn();

        const { rerender } = renderHook(({ cb }) => useEchoChannel('tenant.1.board.1', 'DealStageChanged', cb), {
            initialProps: { cb: first },
        });
        rerender({ cb: second });

        fake.emit('tenant.1.board.1', 'DealStageChanged', { id: 5 });

        expect(first).not.toHaveBeenCalled();
        expect(second).toHaveBeenCalledWith({ id: 5 });
        expect(fake.listeners.get(fake.keyOf('tenant.1.board.1', 'DealStageChanged'))).toHaveLength(1);
    });

    it('stops listening and leaves the channel on unmount', () => {
        const { unmount } = renderHook(() => useEchoChannel('tenant.2.board.1', 'DealStageChanged', vi.fn()));

        unmount();

        expect(fake.listeners.get(fake.keyOf('tenant.2.board.1', 'DealStageChanged'))).toHaveLength(0);
        expect(fake.echo.leave).toHaveBeenCalledWith('tenant.2.board.1');
    });

    it('leaves a shared channel only after its last subscriber unmounts', () => {
        const a = renderHook(() => useEchoChannel('tenant.3.board.1', 'DealStageChanged', vi.fn()));
        const b = renderHook(() => useEchoChannel('tenant.3.board.1', 'DealUpdated', vi.fn()));

        a.unmount();
        expect(fake.echo.leave).not.toHaveBeenCalled();

        b.unmount();
        expect(fake.echo.leave).toHaveBeenCalledTimes(1);
        expect(fake.echo.leave).toHaveBeenCalledWith('tenant.3.board.1');
    });

    it('does nothing for an empty channel name', () => {
        renderHook(() => useEchoChannel('', 'DealStageChanged', vi.fn()));

        expect(fake.echo.private).not.toHaveBeenCalled();
    });

    it('lets a broadcast patch the TanStack Query cache (15.5 pattern)', () => {
        type Card = { id: number; stage: string };
        const client = createTestQueryClient();
        client.setQueryData<Card[]>(['deals', 7, 1], [
            { id: 1, stage: 'lead' },
            { id: 2, stage: 'lead' },
        ]);

        renderHook(
            () => {
                const qc = useQueryClient();
                useEchoChannel('tenant.7.board.1', 'DealStageChanged', (payload) => {
                    const { id, stage } = payload as Card;
                    qc.setQueryData<Card[]>(['deals', 7, 1], (old) => old?.map((d) => (d.id === id ? { ...d, stage } : d)));
                });
            },
            { wrapper: queryWrapper(client) },
        );

        fake.emit('tenant.7.board.1', 'DealStageChanged', { id: 1, stage: 'won' });

        expect(client.getQueryData<Card[]>(['deals', 7, 1])).toEqual([
            { id: 1, stage: 'won' },
            { id: 2, stage: 'lead' },
        ]);
    });
});
'@

$files['frontend\src\features\deals\api\useMoveDealStage.test.tsx'] = @'
import { act, renderHook, waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { describe, expect, it, vi } from 'vitest';
import { server } from '@/test/server';
import { createTestQueryClient, queryWrapper } from '@/test/queryWrapper';
import type { Deal, DealStage } from '../schemas/deal';
import { isStaleDealError, useMoveDealStage } from './useMoveDealStage';

vi.mock('@/shared/hooks/useTenant', () => ({ useTenant: () => ({ tenant: { id: 7 } }) }));

const KEY = ['deals', 7, 1] as const;
const deal = (id: number, stage: string) => ({ id, stage, lock_version: 1 }) as unknown as Deal;

describe('useMoveDealStage (3.2)', () => {
    it('moves the card optimistically, then rolls back and flags a stale deal on 409', async () => {
        let release!: () => void;
        const gate = new Promise<void>((resolve) => {
            release = resolve;
        });
        let body: unknown;

        server.use(
            http.post('*/api/v1/deals/1/transition', async ({ request }) => {
                body = await request.json();
                await gate;
                return HttpResponse.json(
                    { message: 'This deal was updated by someone else.', errors: [], code: 'conflict' },
                    { status: 409 },
                );
            }),
        );

        const client = createTestQueryClient();
        client.setQueryData<Deal[]>(KEY, [deal(1, 'qualified'), deal(2, 'lead')]);
        const { result } = renderHook(() => useMoveDealStage(1), { wrapper: queryWrapper(client) });

        act(() => {
            result.current.mutate({ dealId: 1, toStage: 'won' as unknown as DealStage, lockVersion: 3 });
        });

        // Optimistic: the dragged card is already in its new column while the request is in flight.
        await waitFor(() => expect(client.getQueryData<Deal[]>(KEY)?.[0]?.stage).toBe('won'));

        release();
        await waitFor(() => expect(result.current.isError).toBe(true));

        // Rollback: the card is back, the neighbour was never touched.
        expect(client.getQueryData<Deal[]>(KEY)?.[0]?.stage).toBe('qualified');
        expect(client.getQueryData<Deal[]>(KEY)?.[1]?.stage).toBe('lead');
        expect(isStaleDealError(result.current.error)).toBe(true);
        expect(body).toEqual({ to_stage: 'won', lock_version: 3 });
    });

    it('does not treat other errors as a stale deal', () => {
        expect(isStaleDealError(new Error('network'))).toBe(false);
    });
});
'@

$files['frontend\src\shared\lib\applyServerErrors.test.tsx'] = @'
import { act, renderHook } from '@testing-library/react';
import { AxiosError, type InternalAxiosRequestConfig } from 'axios';
import { useForm } from 'react-hook-form';
import { describe, expect, it } from 'vitest';
import { applyServerErrors } from './applyServerErrors';

type Values = { name: string; stages: { won: number } };

function apiError(status: number, data: unknown): AxiosError {
    const config = { headers: {} } as InternalAxiosRequestConfig;
    return new AxiosError('Request failed', 'ERR_BAD_REQUEST', config, null, {
        status,
        statusText: '',
        headers: {},
        config,
        data,
    });
}

function setup() {
    return renderHook(() => useForm<Values>({ defaultValues: { name: '', stages: { won: 0 } } }));
}

describe('applyServerErrors (14.2)', () => {
    it('maps a 422 errors payload onto fields, including dotted paths, first message wins', () => {
        const { result } = setup();
        let mapped = false;

        act(() => {
            mapped = applyServerErrors(
                result.current,
                apiError(422, {
                    message: 'The given data was invalid.',
                    errors: { name: ['Name is taken', 'second message'], 'stages.won': ['Too high'] },
                    code: 'validation_failed',
                }),
            );
        });

        expect(mapped).toBe(true);
        expect(result.current.getFieldState('name').error?.message).toBe('Name is taken');
        expect(result.current.getFieldState('stages.won').error?.message).toBe('Too high');
    });

    it('returns false and sets nothing for non-validation failures', () => {
        const { result } = setup();

        expect(applyServerErrors(result.current, new Error('boom'))).toBe(false);
        expect(
            applyServerErrors(result.current, apiError(403, { message: 'Forbidden', errors: [], code: 'http_403' })),
        ).toBe(false);
        expect(result.current.getFieldState('name').error).toBeUndefined();
    });

    it('skips fields whose message list is empty', () => {
        const { result } = setup();

        expect(
            applyServerErrors(result.current, apiError(422, { message: 'Invalid', errors: { name: [] }, code: 'validation_failed' })),
        ).toBe(false);
    });
});
'@

# ------------------------------------------------------------ preflight
foreach ($rel in $files.Keys) {
    if (Test-Path (Join-Path $repo $rel)) { throw "PREFLIGHT: $rel already exists. Paste it first; this script never overwrites files it has not seen." }
}
$ciText = (Read-Text $ci).Replace("`r`n", "`n")
$ciAnchor = 'run: npx tsc --noEmit'
$ciSkip = 'Unit tests (Vitest)'
$ciRep = "run: npx tsc --noEmit`n      - name: Unit tests (Vitest)`n        run: npm test"
if (-not $ciText.Contains($ciSkip) -and (Count-Sub $ciText $ciAnchor) -ne 1) { throw "PREFLIGHT: ci.yml anchor not unique: $ciAnchor" }

# ------------------------------------------------------------ write
foreach ($rel in $files.Keys) { Write-NoBom $rel $files[$rel] }
Add-Report ('Wrote: ' + (($files.Keys | ForEach-Object { $_.Replace('\', '/') }) -join ', '))
Add-Report ('patch ci.yml: ' + (Patch-Once $ci $ciAnchor $ciRep $ciSkip))

# ------------------------------------------------------------ install peer + gates
Set-Location $fe
$ErrorActionPreference = 'Continue'
if (-not (Test-Path (Join-Path $fe 'node_modules\@testing-library\dom'))) {
    $inst = npm.cmd install --save-dev "@testing-library/dom@^10" --no-audit --no-fund 2>&1 | Out-String
    Add-Report ("npm install @testing-library/dom (exit " + $LASTEXITCODE + "):`n" + (Tail $inst 8))
} else {
    Add-Report '@testing-library/dom already installed'
}

$tsc = npx.cmd tsc --noEmit 2>&1 | Out-String
$tscCode = $LASTEXITCODE
if ($tscCode -eq 0) { Add-Report 'tsc: clean' } else { Add-Report ("tsc FAILED:`n" + (Tail $tsc 60)) }

$vt = npm.cmd test 2>&1 | Out-String
$vtCode = $LASTEXITCODE
$ErrorActionPreference = 'Stop'
Set-Location $repo
if ($vtCode -eq 0) { Add-Report ("Vitest OK:`n" + (Tail $vt 14)) } else { Add-Report ("Vitest FAILED:`n" + (Tail $vt 70)) }

Add-Report 'NOT TOUCHED (referenced, not shown): frontend/tsconfig.json (assumed to include src and use jsx react-jsx), shared/lib/echo.ts and useTenant.ts (mocked in tests), features/deals/schemas/deal.ts (cast in tests), Board.tsx and the real board wiring of useEchoChannel, QuoteBuilder and quote.ts (15.3b), vite.config.ts (unchanged)'
Finish
