<?php

namespace App\Domain\Products\Actions;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;


final class DeleteProductAction
{
    public function execute(User $actor, int $productId): void
    {
        Gate::forUser($actor)->authorize('products.manage');



        DB::transaction(function () use ($productId): void {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);

            ProductVariant::query()->where('product_id', $product->id)->delete();
            $product->delete();
        });
    }
}
