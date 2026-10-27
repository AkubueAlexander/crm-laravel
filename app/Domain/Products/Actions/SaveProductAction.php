<?php

namespace App\Domain\Products\Actions;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 10.0: create ($productId null) or update a product together with its variants, in one transaction.
 * Variants are synced: rows with a known id are updated, rows without one are created, absent rows are deleted.
 * Tenant comes from the actor (1.3); every rule is re-checked here independent of the Form Request (2.4).
 */
final class SaveProductAction
{
    private const MAX_VARIANTS = 50;

    /**
     * @param array{sku: string, name: string, description?: string|null, unit_price: int|float|string, tax_category?: string, is_active?: bool, variants?: list<array<string, mixed>>} $data
     */
    public function execute(User $actor, ?int $productId, array $data): Product
    {
        Gate::forUser($actor)->authorize('products.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        $variants = array_values($data['variants'] ?? []);
        $this->assertFields($data, $variants);
        $this->assertSkusFree($tenantId, $productId, trim((string) $data['sku']), $variants);

        try {
            return DB::transaction(function () use ($tenantId, $productId, $data, $variants): Product {
                $product = $productId === null
                    ? new Product()
                    : Product::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($productId);

                $description = trim((string) ($data['description'] ?? ''));

                $product->forceFill([
                    'tenant_id' => $tenantId,
                    'sku' => trim((string) $data['sku']),
                    'name' => trim((string) $data['name']),
                    'description' => $description === '' ? null : $description,
                    'unit_price' => $this->money($data['unit_price']),
                    'tax_category' => $data['tax_category'] ?? 'standard',
                    'is_active' => (bool) ($data['is_active'] ?? true),
                ])->save();

                $this->syncVariants($product, $tenantId, $variants);

                return $product->load('variants');
            });
        } catch (UniqueConstraintViolationException) {
            // Race guard: the friendly pre-check above lost to a concurrent writer.
            throw ValidationException::withMessages(['sku' => ['This SKU is already in use.']]);
        }
    }

    /** @param list<array<string, mixed>> $variants */
    private function syncVariants(Product $product, int $tenantId, array $variants): void
    {
        $existing = ProductVariant::query()
            ->where('tenant_id', $tenantId)
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
                'tenant_id' => $tenantId,
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
            ->where('tenant_id', $tenantId)
            ->where('product_id', $product->id)
            ->whereNotIn('id', $keep)
            ->delete();
    }

    /** @param list<array<string, mixed>> $variants */
    private function assertFields(array $data, array $variants): void
    {
        $errors = [];

        $sku = trim((string) ($data['sku'] ?? ''));
        if ($sku === '' || mb_strlen($sku) > 64) {
            $errors['sku'] = ['SKU is required (max 64 characters).'];
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            $errors['name'] = ['Name is required (max 160 characters).'];
        }

        if (! in_array($data['tax_category'] ?? 'standard', Product::TAX_CATEGORIES, true)) {
            $errors['tax_category'] = ['Unknown tax category.'];
        }

        if (! $this->validMoney($data['unit_price'] ?? null)) {
            $errors['unit_price'] = ['Enter a valid price.'];
        }

        if (count($variants) > self::MAX_VARIANTS) {
            $errors['variants'] = ['A product can have at most '.self::MAX_VARIANTS.' variants.'];
        }

        foreach ($variants as $i => $v) {
            $vsku = trim((string) ($v['sku'] ?? ''));
            if ($vsku === '' || mb_strlen($vsku) > 64) {
                $errors["variants.$i.sku"] = ['SKU is required (max 64 characters).'];
            }

            $vname = trim((string) ($v['name'] ?? ''));
            if ($vname === '' || mb_strlen($vname) > 160) {
                $errors["variants.$i.name"] = ['Name is required (max 160 characters).'];
            }

            $price = $v['unit_price'] ?? null;
            if ($price !== null && $price !== '' && ! $this->validMoney($price)) {
                $errors["variants.$i.unit_price"] = ['Enter a valid price.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Friendly pre-check (the unique indexes are the real guard): duplicates in the payload and in the tenant. */
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

    private function validMoney(mixed $value): bool
    {
        return is_numeric($value) && (float) $value >= 0 && (float) $value <= 9999999999.99;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
