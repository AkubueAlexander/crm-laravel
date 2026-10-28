<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\StockQuantity;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 11.0a: creates or edits the descriptive side of an item (SKU, name, unit, reorder level, active flag). Stock is
 * NEVER set here: on_hand only moves through RecordStockMovementAction. The SKU is stored upper-case, is unique per
 * tenant, and cannot change after creation (other documents will reference it).
 */
final class SaveInventoryItemAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $actor, ?int $itemId, array $data): InventoryItem
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('inventory.manage');

        $sku = strtoupper(trim((string) ($data['sku'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $unit = trim((string) ($data['unit'] ?? ''));
        $unit = $unit === '' ? 'unit' : $unit;
        $reorder = trim((string) ($data['reorder_level'] ?? ''));
        $reorder = $reorder === '' ? '0' : $reorder;
        $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;

        $errors = [];

        if (! preg_match('/^[A-Z0-9][A-Z0-9._-]{0,63}$/', $sku)) {
            $errors['sku'] = ['SKU must be 1-64 letters, digits, dots, dashes or underscores.'];
        }

        if ($name === '' || mb_strlen($name) > 160) {
            $errors['name'] = ['Enter a name (max 160 characters).'];
        }

        if (mb_strlen($unit) > 24) {
            $errors['unit'] = ['Unit must be 24 characters or fewer.'];
        }

        $reorderMilli = StockQuantity::parse($reorder);
        if ($reorderMilli === null) {
            $errors['reorder_level'] = ['Enter a valid reorder level (zero or more, up to 3 decimals).'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            return DB::transaction(function () use ($actor, $itemId, $tenantId, $sku, $name, $unit, $reorderMilli, $active): InventoryItem {
                if ($itemId === null) {
                    // Friendly pre-check, then the unique index as the race guard (caught below).
                    if (InventoryItem::query()->where('tenant_id', $tenantId)->where('sku', $sku)->exists()) {
                        throw ValidationException::withMessages(['sku' => ['This SKU already exists.']]);
                    }

                    $item = new InventoryItem();
                    $item->forceFill([
                        'tenant_id' => $tenantId,
                        'sku' => $sku,
                        'name' => $name,
                        'unit' => $unit,
                        'reorder_level' => StockQuantity::format((int) $reorderMilli),
                        'is_active' => $active,
                        'created_by' => $actor->id,
                    ])->save();

                    return $item;
                }

                // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
                $item = InventoryItem::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($itemId);

                if ($sku !== $item->sku) {
                    throw ValidationException::withMessages(['sku' => ['The SKU cannot be changed once the item exists.']]);
                }

                $item->forceFill([
                    'name' => $name,
                    'unit' => $unit,
                    'reorder_level' => StockQuantity::format((int) $reorderMilli),
                    'is_active' => $active,
                ])->save();

                return $item;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => ['This SKU already exists.']]);
        }
    }
}
