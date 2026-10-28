<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Inventory\Actions\RecordStockMovementAction;
use App\Domain\Inventory\StockQuantity;
use App\Domain\Purchasing\Exceptions\PurchaseOrderTransitionException;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 11.2a: receives goods against an ordered / partially received PO. $receipts is a list of { line_id, quantity }.
 * Everything happens in ONE transaction: each receipt is a ledger movement written by RecordStockMovementAction
 * (the only writer of on_hand; type "receipt", reference = the PO number), the line's received_quantity moves, and
 * the PO becomes partially_received or received. If anything fails (e.g. an inactive item) nothing is written.
 * Over-receiving a line is refused. lock_version is checked and bumped like every other PO change.
 */
final class ReceivePurchaseOrderAction
{
    /** @param list<array{line_id: mixed, quantity: mixed}> $receipts */
    public function execute(User $actor, int $poId, int $lockVersion, array $receipts, ?string $note = null): PurchaseOrder
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('inventory.manage');

        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        $errors = [];
        $parsed = [];

        if ($receipts === []) {
            $errors['receipts'] = ['Enter a quantity for at least one line.'];
        }

        $seen = [];
        foreach (array_values($receipts) as $i => $row) {
            $row = is_array($row) ? $row : [];
            $lineId = (int) ($row['line_id'] ?? 0);
            $qty = StockQuantity::parse((string) ($row['quantity'] ?? ''));

            if ($lineId <= 0) {
                $errors["receipts.$i.line_id"] = ['Choose a line.'];
            } elseif (isset($seen[$lineId])) {
                $errors["receipts.$i.line_id"] = ['Each line can be received only once per receipt.'];
            }
            $seen[$lineId] = true;

            if ($qty === null || $qty <= 0) {
                $errors["receipts.$i.quantity"] = ['Quantity must be greater than zero (up to 3 decimals).'];
            } else {
                $parsed[$i] = ['line_id' => $lineId, 'qty' => $qty];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($actor, $poId, $tenantId, $lockVersion, $parsed, $note): PurchaseOrder {
            // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
            $po = PurchaseOrder::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($poId);

            if ($po->lock_version !== $lockVersion) {
                throw PurchaseOrderTransitionException::stale($po);
            }

            if (! in_array($po->status, ['ordered', 'partially_received'], true)) {
                throw ValidationException::withMessages(['po' => ['Only ordered purchase orders can be received.']]);
            }

            $lines = PurchaseOrderLine::query()
                ->where('tenant_id', $tenantId)
                ->where('purchase_order_id', $po->id)
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $errors = [];
            foreach ($parsed as $i => $r) {
                $line = $lines->get($r['line_id']);
                if ($line === null) {
                    $errors["receipts.$i.line_id"] = ['This line is not on the purchase order.'];
                    continue;
                }

                $remaining = StockQuantity::fromDecimal($line->quantity) - StockQuantity::fromDecimal($line->received_quantity);
                if ($r['qty'] > $remaining) {
                    $errors["receipts.$i.quantity"] = ['Only '.StockQuantity::format($remaining).' still to receive on this line.'];
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $movements = app(RecordStockMovementAction::class);

            foreach ($parsed as $r) {
                $line = $lines->get($r['line_id']);

                try {
                    $movements->execute($actor, (int) $line->inventory_item_id, 'receipt', StockQuantity::format($r['qty']), $po->po_number, $note);
                } catch (ValidationException $e) {
                    $sku = InventoryItem::query()->where('tenant_id', $tenantId)->whereKey($line->inventory_item_id)->value('sku');
                    throw ValidationException::withMessages(['lines' => [($sku ?? 'Item').': '.implode(' ', Arr::flatten($e->errors()))]]);
                }

                $line->forceFill([
                    'received_quantity' => StockQuantity::format(StockQuantity::fromDecimal($line->received_quantity) + $r['qty']),
                ])->save();
            }

            $complete = $lines->every(fn (PurchaseOrderLine $l) => StockQuantity::fromDecimal($l->received_quantity) >= StockQuantity::fromDecimal($l->quantity));

            $po->forceFill([
                'status' => $complete ? 'received' : 'partially_received',
                'lock_version' => $po->lock_version + 1,
                'received_at' => $complete ? now() : null,
            ])->save();

            return $po->load('lines');
        });
    }
}
