<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domain\Inventory\Actions\SaveInventoryItemAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ListInventoryItemsRequest;
use App\Http\Requests\Inventory\SaveInventoryItemRequest;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

// {item} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
final class InventoryItemController extends Controller
{
    public function index(ListInventoryItemsRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $tenantId = (int) $request->user()->tenant_id;
        $q = trim((string) ($v['q'] ?? ''));
        $direction = $v['direction'] ?? 'asc';
        $column = ListInventoryItemsRequest::SORT_COLUMNS[$v['sort'] ?? 'sku'];

        $items = InventoryItem::query()
            ->where('tenant_id', $tenantId)
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($w) use ($like) {
                    $w->where('sku', 'ilike', $like)->orWhere('name', 'ilike', $like);
                });
            })
            ->when(isset($v['active']), fn ($query) => $query->where('is_active', filter_var($v['active'], FILTER_VALIDATE_BOOLEAN)))
            ->when(isset($v['low_stock']) && filter_var($v['low_stock'], FILTER_VALIDATE_BOOLEAN), function ($query) {
                $query->where('is_active', true)->where('reorder_level', '>', 0)->whereColumn('on_hand', '<=', 'reorder_level');
            })
            ->orderBy($column, $direction)
            ->orderBy('id', $direction) // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return InventoryItemResource::collection($items);
    }

    public function show(Request $request, int $item): InventoryItemResource
    {
        Gate::authorize('inventory.view');

        return new InventoryItemResource(
            InventoryItem::query()->where('tenant_id', (int) $request->user()->tenant_id)->findOrFail($item),
        );
    }

    public function store(SaveInventoryItemRequest $request, SaveInventoryItemAction $action): JsonResponse
    {
        $item = $action->execute($request->user(), null, $request->validated());

        return (new InventoryItemResource($item))->response()->setStatusCode(201);
    }

    public function update(SaveInventoryItemRequest $request, int $item, SaveInventoryItemAction $action): InventoryItemResource
    {
        return new InventoryItemResource($action->execute($request->user(), $item, $request->validated()));
    }
}
