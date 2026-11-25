<?php

namespace App\Domain\Products\Actions;

use App\Domain\Products\ProductRules;
use App\Domain\Tenant\TenantContext;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveProductAction
{
    /**
     * @param array<string, mixed> $data see ProductRules::all() for the accepted shape
     */
    public function execute(User $actor, ?int $productId, array $data): Product
    {
        Gate::forUser($actor)->authorize('products.manage');

        $tenantId = (int) $actor->tenant_id;

        // BelongsToTenant stamps tenant_id from the resolved context, so the actor and the
        // context must agree; otherwise a row would silently land in the wrong tenant.
        if ($tenantId <= 0 || ! TenantContext::hasTenant() || (int) TenantContext::id() !== $tenantId) {
            throw ValidationException::withMessages([
                'tenant' => ['A valid tenant context is required.'],
            ]);
        }

        // Re-validate here: the Form Request is UX, this is the boundary (2.4).
        $data = Validator::make($data, ProductRules::all())->validate();

        $variants = array_values($data['variants']);

        $this->assertSkusFree($tenantId, $productId, trim((string) $data['sku']), $variants);

        try {
            return DB::transaction(function () use ($productId, $data, $variants): Product {
                $product = $productId === null
                    ? new Product()
                    : Product::query()->lockForUpdate()->findOrFail($productId);

                $description = trim((string) ($data['description'] ?? ''));

                $product->forceFill([
                    'sku' => trim((string) $data['sku']),
                    'name' => trim((string) $data['name']),
                    'description' => $description === '' ? null : $description,
                    'unit_price' => $this->money($data['unit_price']),
                    'tax_category' => $data['tax_category'],
                    'is_active' => (bool) ($data['is_active'] ?? true),
                ])->save();

                $this->syncVariants($product, $variants);

                return $product->load('variants');
            });
        } catch (UniqueConstraintViolationException) {
            // Race guard: the friendly pre-check above lost to a concurrent writer.
            throw ValidationException::withMessages(['sku' => ['This SKU is already in use.']]);
        }
    }

    /** @param list<array<string, mixed>> $variants */
    private function syncVariants(Product $product, array $variants): void
    {
        // Tenant scope applies: another tenant's variant id is simply "unknown".
        $existing = ProductVariant::query()
            ->where('product_id', $product->id)
            ->get()
            ->keyBy('id');

        $keep = [];

        foreach ($variants as $i => $v) {
            $id = isset($v['id']) ? (int) $v['id'] : null;

            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages(["variants.$i.id" => ['Unknown variant.']]);
            }

            $variant = $id !== null ? $existing->get($id) : new ProductVariant();
            $price = $v['unit_price'] ?? null;

            $variant->forceFill([
                'product_id' => $product->id,
                'sku' => trim((string) $v['sku']),
                'name' => trim((string) $v['name']),
                'unit_price' => ($price === null || $price === '') ? null : $this->money($price),
                'is_active' => (bool) ($v['is_active'] ?? true),
                'sort_order' => $i,
            ])->save();

            $keep[] = $variant->id;
        }

        ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereNotIn('id', $keep)
            ->delete();
    }

    /**
     * Friendly pre-check (the unique indexes are the real guard): duplicates in the payload
     * and in the tenant.
     *
     * @param list<array<string, mixed>> $variants
     */
    private function assertSkusFree(int $tenantId, ?int $productId, string $sku, array $variants): void
    {
        $errors = [];
        $productSku = mb_strtolower($sku);
        $variantSkus = array_map(fn (array $v) => mb_strtolower(trim((string) $v['sku'])), $variants);
        $candidates = array_values(array_unique(array_merge([$productSku], $variantSkus)));

        $takenByProducts = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->when($productId !== null, fn ($q) => $q->where('id', '!=', $productId))
            ->whereIn(DB::raw('lower(sku)'), $candidates)
            ->selectRaw('lower(sku) as s')
            ->pluck('s')
            ->all();

        $takenByVariants = DB::table('product_variants')
            ->where('tenant_id', $tenantId)
            ->when($productId !== null, fn ($q) => $q->where('product_id', '!=', $productId))
            ->whereIn(DB::raw('lower(sku)'), $candidates)
            ->selectRaw('lower(sku) as s')
            ->pluck('s')
            ->all();

        $taken = array_merge($takenByProducts, $takenByVariants);

        if (in_array($productSku, $taken, true)) {
            $errors['sku'] = ['This SKU is already in use.'];
        }

        $seen = [$productSku => true];
        foreach ($variantSkus as $i => $vsku) {
            if (isset($seen[$vsku])) {
                $errors["variants.$i.sku"] = ['Duplicate SKU in this product.'];
            } elseif (in_array($vsku, $taken, true)) {
                $errors["variants.$i.sku"] = ['This SKU is already in use.'];
            }
            $seen[$vsku] = true;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}