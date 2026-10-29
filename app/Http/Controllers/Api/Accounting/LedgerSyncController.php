<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Domain\Accounting\Actions\RequestLedgerSyncAction;
use App\Domain\Accounting\Actions\SaveLedgerSyncSettingsAction;
use App\Domain\Accounting\Sync\LedgerSyncProviders;
use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerSyncRunResource;
use App\Models\LedgerSyncRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

// 12.2: ledger sync to an external books-of-record system. accounting.view to read, accounting.manage to change/run.
// Tenant always comes from the session. Sync runs are not a status workflow the user edits: nothing here is optimistic.
final class LedgerSyncController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('accounting.view');

        return response()->json($this->status((int) $request->user()->tenant_id));
    }

    public function saveSettings(Request $request, SaveLedgerSyncSettingsAction $action): JsonResponse
    {
        Gate::authorize('accounting.manage');

        // Shape check only: the Action re-checks permission and that the provider is known (2.4).
        $v = $request->validate(['provider' => ['required', 'string', 'max:20']]);
        $action->execute($request->user(), $v['provider']);

        return response()->json($this->status((int) $request->user()->tenant_id));
    }

    public function run(Request $request, RequestLedgerSyncAction $action): JsonResponse
    {
        Gate::authorize('accounting.manage');

        $run = $action->execute($request->user());

        return (new LedgerSyncRunResource($run))->response()->setStatusCode(202);
    }

    public function runs(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('accounting.view');

        $v = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $rows = LedgerSyncRun::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->orderByDesc('id')
            ->paginate($v['per_page'] ?? 10)
            ->withQueryString();

        return LedgerSyncRunResource::collection($rows);
    }

    /** @return array<string, mixed> */
    private function status(int $tenantId): array
    {
        $provider = LedgerSyncProviders::current($tenantId);

        $base = fn () => LedgerSyncRun::query()->where('tenant_id', $tenantId);
        $last = $base()->orderByDesc('id')->first();
        $lastOk = $base()->where('provider', $provider)->where('status', 'succeeded')->orderByDesc('id')->first();
        $active = $base()->whereIn('status', LedgerSyncRun::ACTIVE)->orderByDesc('id')->first();

        return [
            'provider' => $provider,
            'providers' => LedgerSyncProviders::catalog(),
            'last_synced_at' => $lastOk?->finished_at?->toIso8601String(),
            'last_run' => $last ? (new LedgerSyncRunResource($last))->resolve() : null,
            'active_run' => $active ? (new LedgerSyncRunResource($active))->resolve() : null,
        ];
    }
}
