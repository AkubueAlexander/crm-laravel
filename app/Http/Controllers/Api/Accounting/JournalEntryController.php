<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Domain\Accounting\Actions\PostJournalEntryAction;
use App\Domain\Accounting\Actions\ReverseJournalEntryAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\JournalEntryResource;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

// {entry} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
final class JournalEntryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('accounting.view');

        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $tenantId = (int) $request->user()->tenant_id;
        $q = trim((string) ($v['q'] ?? ''));

        $rows = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->withSum('lines as total_debit', 'debit')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($w) use ($like) {
                    $w->where('entry_number', 'ilike', $like)->orWhere('memo', 'ilike', $like);
                });
            })
            ->when($v['account_id'] ?? null, function ($query, $accountId) use ($tenantId) {
                $query->whereIn('id', JournalLine::query()->where('tenant_id', $tenantId)->where('ledger_account_id', (int) $accountId)->select('journal_entry_id'));
            })
            ->when($v['from'] ?? null, fn ($query, $from) => $query->where('entry_date', '>=', $from))
            ->when($v['to'] ?? null, fn ($query, $to) => $query->where('entry_date', '<=', $to))
            ->orderByDesc('entry_date')
            ->orderByDesc('id') // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        $this->attachReversals($rows->getCollection(), $tenantId);

        return JournalEntryResource::collection($rows);
    }

    public function show(Request $request, int $entry): JournalEntryResource
    {
        Gate::authorize('accounting.view');

        $tenantId = (int) $request->user()->tenant_id;
        $found = JournalEntry::query()->where('tenant_id', $tenantId)->with('lines.account')->findOrFail($entry);
        $this->attachReversals(collect([$found]), $tenantId);

        return new JournalEntryResource($found);
    }

    public function store(Request $request, PostJournalEntryAction $action): JsonResponse
    {
        Gate::authorize('accounting.manage');

        $v = $request->validate([
            'entry_date' => ['required', 'string', 'max:10'],
            'memo' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:'.PostJournalEntryAction::MAX_LINES],
            'lines.*.account_id' => ['required', 'integer', 'min:1'],
            'lines.*.debit' => ['nullable'],
            'lines.*.credit' => ['nullable'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $lines = array_map(fn (array $l) => [
            'account_id' => $l['account_id'],
            'debit' => is_scalar($l['debit'] ?? null) ? (string) $l['debit'] : '',
            'credit' => is_scalar($l['credit'] ?? null) ? (string) $l['credit'] : '',
            'description' => $l['description'] ?? null,
        ], array_values($v['lines']));

        $entry = $action->execute($request->user(), (string) $v['entry_date'], $v['memo'] ?? null, $lines);

        return (new JournalEntryResource($entry->load('lines.account')))->response()->setStatusCode(201);
    }

    public function reverse(Request $request, int $entry, ReverseJournalEntryAction $action): JsonResponse
    {
        Gate::authorize('accounting.manage');

        $v = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
            'entry_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $reversal = $action->execute($request->user(), $entry, (string) $v['reason'], $v['entry_date'] ?? null);

        return (new JournalEntryResource($reversal->load('lines.account')))->response()->setStatusCode(201);
    }

    /** @param Collection<int, JournalEntry> $entries */
    private function attachReversals(Collection $entries, int $tenantId): void
    {
        if ($entries->isEmpty()) {
            return;
        }

        $map = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('reverses_entry_id', $entries->pluck('id')->all())
            ->pluck('id', 'reverses_entry_id');

        foreach ($entries as $e) {
            $e->setAttribute('reversed_by_entry_id', $map[$e->id] ?? null);
        }
    }
}
