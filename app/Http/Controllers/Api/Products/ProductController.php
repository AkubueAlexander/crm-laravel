<?php

namespace App\Http\Controllers\Api\Products;

use App\Domain\Products\Actions\DeleteProductAction;
use App\Domain\Products\Actions\SaveProductAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\ListProductsRequest;
use App\Http\Requests\Products\SaveProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

// {product} is a plain id on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
final class ProductController extends Controller
{
    public function index(ListProductsRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $tenantId = (int) $request->user()->tenant_id;
        $q = trim((string) ($v['q'] ?? ''));

        $products = Product::query()
            ->where('tenant_id', $tenantId)
            ->withCount('variants')
            ->when($q !== '', function ($query) use ($q, $tenantId) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $query->where(function ($w) use ($like, $tenantId) {
                    $w->where('sku', 'ilike', $like)
                        ->orWhere('name', 'ilike', $like)
                        ->orWhereIn('id', DB::table('product_variants')
                            ->where('tenant_id', $tenantId)
                            ->where('sku', 'ilike', $like)
                            ->select('product_id'));
                });
            })
            ->when(($v['status'] ?? null) === 'active', fn ($query) => $query->where('is_active', true))
            ->when(($v['status'] ?? null) === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($v['tax_category'] ?? null, fn ($query, $category) => $query->where('tax_category', $category))
            ->orderBy($v['sort'] ?? 'name', $v['direction'] ?? 'asc')
            ->orderBy('id', $v['direction'] ?? 'asc') // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function show(Request $request, int $product): ProductResource
    {
        Gate::authorize('products.view');

        return new ProductResource(
            Product::query()
                ->where('tenant_id', (int) $request->user()->tenant_id)
                ->with('variants')
                ->findOrFail($product),
        );
    }

    public function store(SaveProductRequest $request, SaveProductAction $action): JsonResponse
    {
        $product = $action->execute($request->user(), null, $request->validated());

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function update(SaveProductRequest $request, int $product, SaveProductAction $action): ProductResource
    {
        return new ProductResource($action->execute($request->user(), $product, $request->validated()));
    }

    public function destroy(Request $request, int $product, DeleteProductAction $action): Response
    {
        $action->execute($request->user(), $product);

        return response()->noContent();
    }
}
