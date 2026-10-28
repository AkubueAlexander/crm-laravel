<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domain\Inventory\Actions\RecordStockMovementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\RecordStockMovementRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

// {item} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
final class InventoryMovementController extends Controller
{
    /** The ledger of one item, newest first. */
    public function index(Request $request, int $item): AnonymousResourceCollection
    {
        Gate::authorize('inventory.view');

        $v = $request->validate([
            'type' => ['nullable', 'string', Rule::in(RecordStockMovementAction::TYPES)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $tenantId = (int) $request->user()->tenant_id;
        $itemId = InventoryItem::query()->where('tenant_id', $tenantId)->findOrFail($item)->id;

        $rows = InventoryMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('inventory_item_id', $itemId)
            ->when($v['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->orderByDesc('id')
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return InventoryMovementResource::collection($rows);
    }

    public function store(RecordStockMovementRequest $request, int $item, RecordStockMovementAction $action): JsonResponse
    {
        $v = $request->validated();

        $movement = $action->execute(
            $request->user(),
            $item,
            (string) $v['type'],
            (string) $v['quantity'],
            $v['reference'] ?? null,
            $v['note'] ?? null,
        );

        return (new InventoryMovementResource($movement))->response()->setStatusCode(201);
    }
}
