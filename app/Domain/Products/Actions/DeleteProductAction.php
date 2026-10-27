<?php

namespace App\Domain\Products\Actions;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * 10.0: soft-deletes a product (history stays for quotes/invoices, 10.1+) and removes its variants so their SKUs
 * are free again. Another tenant's id is a 404. A "product is on a quote" guard is added with the quote tables (10.1).
 */
final class DeleteProductAction
{
    public function execute(User $actor, int $productId): void
    {
        Gate::forUser($actor)->authorize('products.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        DB::transaction(function () use ($productId, $tenantId): void {
            $product = Product::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($productId);

            ProductVariant::query()->where('tenant_id', $tenantId)->where('product_id', $product->id)->delete();
            $product->delete();
        });
    }
}
