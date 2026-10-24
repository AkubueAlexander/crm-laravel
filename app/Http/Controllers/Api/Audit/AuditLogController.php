<?php

namespace App\Http\Controllers\Api\Audit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListAuditLogRequest;
use App\Http\Resources\AuditEntryResource;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 8c.3: the tenant's audit feed. Two sources (deal stage changes, administrative events) merged by one
 * SQL union so filtering, ordering and pagination all happen in the database. Neither source table is
 * read through Eloquent, so every branch carries its own explicit tenant predicate: that is the isolation.
 */
final class AuditLogController extends Controller
{
    public function index(ListAuditLogRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $tenantId = (int) $request->user()->tenant_id;
        $source = $v['source'] ?? null;

        $branches = [];
        if ($source === null || $source === 'deal_stage') {
            $branches[] = $this->dealStageBranch($tenantId);
        }
        if ($source === null || $source !== 'deal_stage') {
            $branches[] = $this->eventBranch($tenantId, $source);
        }

        $union = array_shift($branches);
        foreach ($branches as $branch) {
            $union->unionAll($branch);
        }

        $rows = DB::query()
            ->fromSub($union, 'a')
            ->leftJoin('users as u', function ($join) use ($tenantId) {
                $join->on('u.id', '=', 'a.user_id')->where('u.tenant_id', '=', $tenantId);
            })
            ->select('a.*', 'u.name as user_name')
            ->when($v['user_id'] ?? null, fn ($q, $id) => $q->where('a.user_id', (int) $id))
            ->when($v['from'] ?? null, fn ($q, $d) => $q->where('a.occurred_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($v['to'] ?? null, fn ($q, $d) => $q->where('a.occurred_at', '<=', Carbon::parse($d)->endOfDay()))
            ->orderByDesc('a.occurred_at')
            ->orderByDesc('a.source')
            ->orderByDesc('a.row_id')
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return AuditEntryResource::collection($rows);
    }

    /** Users of this tenant who appear in the feed: the options for the "user" filter. */
    public function actors(\Illuminate\Http\Request $request): JsonResponse
    {
        Gate::authorize('audit.view');

        $tenantId = (int) $request->user()->tenant_id;

        $users = DB::table('users as u')
            ->where('u.tenant_id', $tenantId)
            ->where(function ($q) use ($tenantId) {
                $q->whereExists(fn ($s) => $s->selectRaw('1')->from('deal_stage_audit_log as d')
                    ->whereColumn('d.user_id', 'u.id')->where('d.tenant_id', $tenantId))
                    ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('audit_events as e')
                        ->whereColumn('e.user_id', 'u.id')->where('e.tenant_id', $tenantId));
            })
            ->orderBy('u.name')
            ->orderBy('u.id')
            ->get(['u.id', 'u.name']);

        return response()->json([
            'data' => $users->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->name])->values()->all(),
        ]);
    }

    private function dealStageBranch(int $tenantId): Builder
    {
        return DB::table('deal_stage_audit_log as d')
            ->leftJoin('deals as dl', function ($join) {
                $join->on('dl.id', '=', 'd.deal_id')->on('dl.tenant_id', '=', 'd.tenant_id');
            })
            ->where('d.tenant_id', $tenantId)
            ->selectRaw(
                "'deal_stage'::text as source, 'deal.stage_changed'::text as event, d.id as row_id, d.user_id as user_id, "
                ."d.transitioned_at as occurred_at, 'deal'::text as subject_type, d.deal_id as subject_id, "
                ."dl.name::text as subject_label, jsonb_build_object('from', d.from_state, 'to', d.to_state) as details"
            );
    }

    private function eventBranch(int $tenantId, ?string $source): Builder
    {
        return DB::table('audit_events as e')
            ->where('e.tenant_id', $tenantId)
            ->when($source !== null, fn ($q) => $q->where('e.source', $source))
            ->selectRaw(
                'e.source::text as source, e.event::text as event, e.id as row_id, e.user_id as user_id, '
                .'e.created_at as occurred_at, e.subject_type::text as subject_type, e.subject_id as subject_id, '
                ."e.subject_label::text as subject_label, coalesce(e.properties, '{}'::jsonb) as details"
            );
    }
}
