<?php

namespace App\Http\Controllers\Api\Purchasing;

use App\Domain\Purchasing\Actions\ReceivePurchaseOrderAction;
use App\Domain\Purchasing\Actions\SavePurchaseOrderAction;
use App\Domain\Purchasing\Actions\TransitionPurchaseOrderAction;
use App\Domain\Purchasing\Exceptions\PurchaseOrderTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\SavePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

// {purchaseOrder} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
// Stale lock_version -> 409 { message, code, current } (state-machine pattern 3.0), same shape as quotes and invoices.
final class PurchaseOrderController extends Controller
{
    private const SORT_COLUMNS = [
        'po_number' => 'po_seq',
        'status' => 'status',
        'subtotal' => 'subtotal',
        'expected_date' => 'expected_date',
        'created_at' => 'created_at',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('inventory.view');

        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(PurchaseOrder::STATUSES)],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::SORT_COLUMNS))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $tenantId = (int) $request->user()->tenant_id;
        $q = trim((string) ($v['q'] ?? ''));
        $direction = $v['direction'] ?? 'desc';
        $column = self::SORT_COLUMNS[$v['sort'] ?? 'po_number'];

        $rows = PurchaseOrder::query()
            ->where('tenant_id', $tenantId)
            ->with('supplier')
            ->withCount('lines')
            ->when($q !== '', function ($query) use ($q, $tenantId) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($w) use ($like, $tenantId) {
                    $w->where('po_number', 'ilike', $like)
                        ->orWhereIn('supplier_id', Supplier::query()->where('tenant_id', $tenantId)->where('name', 'ilike', $like)->select('id'));
                });
            })
            ->when($v['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($v['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('supplier_id', (int) $supplierId))
            ->orderBy($column, $direction)
            ->orderBy('id', $direction) // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return PurchaseOrderResource::collection($rows);
    }

    public function show(Request $request, int $purchaseOrder): PurchaseOrderResource
    {
        Gate::authorize('inventory.view');

        return new PurchaseOrderResource(
            PurchaseOrder::query()
                ->where('tenant_id', (int) $request->user()->tenant_id)
                ->with(['supplier', 'lines.item'])
                ->findOrFail($purchaseOrder),
        );
    }

    public function store(SavePurchaseOrderRequest $request, SavePurchaseOrderAction $action): JsonResponse
    {
        $po = $action->execute($request->user(), null, $request->validated());

        return (new PurchaseOrderResource($po->load(['supplier', 'lines.item'])))->response()->setStatusCode(201);
    }

    public function update(SavePurchaseOrderRequest $request, int $purchaseOrder, SavePurchaseOrderAction $action): JsonResponse
    {
        $v = $request->validated();

        if (! array_key_exists('lock_version', $v)) {
            return response()->json([
                'message' => 'The lock version is required.',
                'errors' => ['lock_version' => ['The lock version is required.']],
                'code' => 'validation_failed',
            ], 422);
        }

        try {
            $po = $action->execute($request->user(), $purchaseOrder, $v);
        } catch (PurchaseOrderTransitionException $e) {
            return $this->stale($e, $request);
        }

        return $this->present($po, $request);
    }

    public function transition(Request $request, int $purchaseOrder, TransitionPurchaseOrderAction $action): JsonResponse
    {
        Gate::authorize('inventory.manage');

        $v = $request->validate([
            'to_status' => ['required', 'string', Rule::in(PurchaseOrder::STATUSES)],
            'lock_version' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $po = $action->execute($request->user(), $purchaseOrder, $v['to_status'], (int) $v['lock_version'], $v['note'] ?? null);
        } catch (PurchaseOrderTransitionException $e) {
            return $this->stale($e, $request);
        }

        return $this->present($po, $request);
    }

    public function receive(Request $request, int $purchaseOrder, ReceivePurchaseOrderAction $action): JsonResponse
    {
        Gate::authorize('inventory.manage');

        $v = $request->validate([
            'lock_version' => ['required', 'integer', 'min:0'],
            'receipts' => ['required', 'array', 'min:1', 'max:100'],
            'receipts.*.line_id' => ['required', 'integer', 'min:1'],
            'receipts.*.quantity' => ['required'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $receipts = array_map(
            fn (array $r) => ['line_id' => $r['line_id'], 'quantity' => is_scalar($r['quantity']) ? (string) $r['quantity'] : ''],
            array_values($v['receipts']),
        );

        try {
            $po = $action->execute($request->user(), $purchaseOrder, (int) $v['lock_version'], $receipts, $v['note'] ?? null);
        } catch (PurchaseOrderTransitionException $e) {
            return $this->stale($e, $request);
        }

        return $this->present($po, $request);
    }

    private function present(PurchaseOrder $po, Request $request): JsonResponse
    {
        return (new PurchaseOrderResource($po->load(['supplier', 'lines.item'])))->response();
    }

    // 409: the client's lock_version is behind. Return the current PO so it can reconcile.
    private function stale(PurchaseOrderTransitionException $e, Request $request): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'stale_purchase_order',
            'current' => (new PurchaseOrderResource($e->current->load(['supplier', 'lines.item'])))->resolve($request),
        ], 409);
    }
}
