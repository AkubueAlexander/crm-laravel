<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\StockQuantity;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 11.0a: the ONLY writer of on_hand. Appends one signed row to the ledger and moves the item's on_hand in the same
 * transaction, under a pessimistic row lock (lockForUpdate), so two concurrent movements can never lose an update or
 * drive stock negative. Types: receipt (adds), issue (removes), adjustment (signed correction, reason required).
 * All arithmetic is integer thousandths; the client never sends a balance.
 */
final class RecordStockMovementAction
{
    public const TYPES = ['receipt', 'issue', 'adjustment'];

    public function execute(User $actor, int $itemId, string $type, string $quantity, ?string $reference = null, ?string $note = null): InventoryMovement
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('inventory.manage');

        $reference = $reference === null ? null : trim($reference);
        $reference = $reference === '' ? null : $reference;
        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        $errors = [];
        $milli = 0;

        if (! in_array($type, self::TYPES, true)) {
            $errors['type'] = ['Unknown movement type.'];
        } else {
            $signed = $type === 'adjustment';
            $parsed = StockQuantity::parse($quantity, $signed);

            if ($parsed === null) {
                $errors['quantity'] = ['Enter a valid quantity (up to 3 decimals).'];
            } elseif ($signed ? $parsed === 0 : $parsed <= 0) {
                $errors['quantity'] = [$signed ? 'An adjustment cannot be zero.' : 'Quantity must be greater than zero.'];
            } else {
                $milli = $parsed;
            }

            if ($signed && $note === null) {
                $errors['note'] = ['Give a reason for the adjustment.'];
            }
        }

        if ($reference !== null && mb_strlen($reference) > 120) {
            $errors['reference'] = ['Reference must be 120 characters or fewer.'];
        }

        if ($note !== null && mb_strlen($note) > 1000) {
            $errors['note'] = ['Note must be 1000 characters or fewer.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $delta = $type === 'issue' ? -$milli : $milli;

        return DB::transaction(function () use ($actor, $itemId, $tenantId, $type, $delta, $reference, $note): InventoryMovement {
            // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
            $item = InventoryItem::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($itemId);

            if (! $item->is_active) {
                throw ValidationException::withMessages(['item' => ['This item is inactive and takes no stock movements.']]);
            }

            $before = StockQuantity::fromDecimal($item->on_hand);
            $after = $before + $delta;

            if ($after < 0) {
                throw ValidationException::withMessages(['quantity' => ['Only '.StockQuantity::format($before).' on hand.']]);
            }

            if ($after > StockQuantity::MAX_MILLI) {
                throw ValidationException::withMessages(['quantity' => ['Stock would exceed the maximum the system can hold.']]);
            }

            $movement = new InventoryMovement();
            $movement->forceFill([
                'tenant_id' => $tenantId,
                'inventory_item_id' => $item->id,
                'type' => $type,
                'quantity_delta' => StockQuantity::format($delta),
                'balance_after' => StockQuantity::format($after),
                'reference' => $reference,
                'note' => $note,
                'recorded_by' => $actor->id,
            ])->save();

            $item->forceFill(['on_hand' => StockQuantity::format($after)])->save();

            return $movement;
        });
    }
}
