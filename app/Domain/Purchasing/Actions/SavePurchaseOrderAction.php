<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Inventory\StockQuantity;
use App\Domain\Purchasing\Exceptions\PurchaseOrderTransitionException;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 11.2a: creates a draft purchase order or rewrites an existing DRAFT (lines are replaced wholesale). The server
 * computes every total from integer thousandths / cents; the client never sends one. Editing needs the current
 * lock_version (stale = PurchaseOrderTransitionException, 409 at the API layer).
 *
 * $data: supplier_id, currency?, expected_date?, notes?, lock_version (edit only),
 *        lines: list of { inventory_item_id, quantity, unit_cost }.
 */
final class SavePurchaseOrderAction
{
    public const MAX_LINES = 100;

    private const MAX_CENTS = 99999999999999;

    /** @param array<string, mixed> $data */
    public function execute(User $actor, ?int $poId, array $data): PurchaseOrder
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('inventory.manage');

        $errors = [];

        $supplierId = (int) ($data['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            $errors['supplier_id'] = ['Choose a supplier.'];
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        $currency = $currency === '' ? 'USD' : $currency;
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency'] = ['Use a 3-letter currency code.'];
        }

        $expected = trim((string) ($data['expected_date'] ?? ''));
        $expected = $expected === '' ? null : $expected;
        if ($expected !== null) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $expected);
            if ($d === false || $d->format('Y-m-d') !== $expected) {
                $errors['expected_date'] = ['Use the format YYYY-MM-DD.'];
            }
        }

        $notes = trim((string) ($data['notes'] ?? ''));
        $notes = $notes === '' ? null : $notes;
        if ($notes !== null && mb_strlen($notes) > 2000) {
            $errors['notes'] = ['Notes must be 2000 characters or fewer.'];
        }

        $lockVersion = null;
        if ($poId !== null) {
            if (! isset($data['lock_version']) || ! is_numeric($data['lock_version']) || (int) $data['lock_version'] < 0) {
                $errors['lock_version'] = ['lock_version is required.'];
            } else {
                $lockVersion = (int) $data['lock_version'];
            }
        }

        $rawLines = $data['lines'] ?? null;
        $lines = [];
        $subtotal = 0;

        if (! is_array($rawLines) || $rawLines === []) {
            $errors['lines'] = ['Add at least one line.'];
        } elseif (count($rawLines) > self::MAX_LINES) {
            $errors['lines'] = ['A purchase order can have at most '.self::MAX_LINES.' lines.'];
        } else {
            $seen = [];
            foreach (array_values($rawLines) as $i => $row) {
                $row = is_array($row) ? $row : [];
                $itemId = (int) ($row['inventory_item_id'] ?? 0);
                $qty = StockQuantity::parse((string) ($row['quantity'] ?? ''));
                $costRaw = trim((string) ($row['unit_cost'] ?? ''));
                $cents = null;

                if ($itemId <= 0) {
                    $errors["lines.$i.inventory_item_id"] = ['Choose an item.'];
                } elseif (isset($seen[$itemId])) {
                    $errors["lines.$i.inventory_item_id"] = ['Each item can appear on a purchase order only once.'];
                }
                $seen[$itemId] = true;

                if ($qty === null || $qty <= 0) {
                    $errors["lines.$i.quantity"] = ['Quantity must be greater than zero (up to 3 decimals).'];
                }

                if (preg_match('/^\d{1,9}(\.\d{1,2})?$/', $costRaw)) {
                    [$whole, $frac] = array_pad(explode('.', $costRaw, 2), 2, '');
                    $cents = ((int) $whole) * 100 + (int) str_pad($frac, 2, '0');
                } else {
                    $errors["lines.$i.unit_cost"] = ['Enter a unit cost (zero or more, up to 2 decimals).'];
                }

                if ($qty !== null && $qty > 0 && $cents !== null) {
                    if ($cents > 0 && $qty > intdiv(PHP_INT_MAX - 500, $cents)) {
                        $errors["lines.$i.quantity"] = ['This line total is too large.'];
                        continue;
                    }
                    $total = intdiv($qty * $cents + 500, 1000);
                    if ($total > self::MAX_CENTS) {
                        $errors["lines.$i.quantity"] = ['This line total is too large.'];
                        continue;
                    }
                    $subtotal += $total;
                    $lines[] = ['item_id' => $itemId, 'qty' => $qty, 'cents' => $cents, 'total' => $total];
                }
            }

            if ($subtotal > self::MAX_CENTS) {
                $errors['lines'] = ['The purchase order total is too large.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($actor, $poId, $tenantId, $supplierId, $currency, $expected, $notes, $lockVersion, $lines, $subtotal): PurchaseOrder {
            $po = null;

            if ($poId !== null) {
                // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
                $po = PurchaseOrder::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($poId);

                if ($po->lock_version !== $lockVersion) {
                    throw PurchaseOrderTransitionException::stale($po);
                }

                if ($po->status !== 'draft') {
                    throw ValidationException::withMessages(['po' => ['Only draft purchase orders can be edited.']]);
                }
            }

            $supplier = Supplier::query()->where('tenant_id', $tenantId)->find($supplierId);
            if ($supplier === null || (! $supplier->is_active && ($po === null || $po->supplier_id !== $supplier->id))) {
                throw ValidationException::withMessages(['supplier_id' => ['Choose an active supplier.']]);
            }

            $items = InventoryItem::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', array_column($lines, 'item_id'))
                ->get()
                ->keyBy('id');

            $lineErrors = [];
            foreach ($lines as $i => $line) {
                $item = $items->get($line['item_id']);
                if ($item === null || ! $item->is_active) {
                    $lineErrors["lines.$i.inventory_item_id"] = ['Choose an active item.'];
                }
            }
            if ($lineErrors !== []) {
                throw ValidationException::withMessages($lineErrors);
            }

            $values = [
                'supplier_id' => $supplier->id,
                'currency' => $currency,
                'expected_date' => $expected,
                'notes' => $notes,
                'subtotal' => $this->money($subtotal),
            ];

            if ($po === null) {
                // Serialise numbering per tenant; the unique index is the backstop.
                DB::select('select pg_advisory_xact_lock(?, ?)', [7102, $tenantId]);
                $seq = ((int) PurchaseOrder::query()->where('tenant_id', $tenantId)->max('po_seq')) + 1;

                $po = new PurchaseOrder();
                $values += [
                    'tenant_id' => $tenantId,
                    'po_seq' => $seq,
                    'po_number' => 'PO-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                    'status' => 'draft',
                    'lock_version' => 0,
                    'created_by' => $actor->id,
                ];
            } else {
                $values['lock_version'] = $po->lock_version + 1;
                PurchaseOrderLine::query()->where('tenant_id', $tenantId)->where('purchase_order_id', $po->id)->delete();
            }

            $po->forceFill($values)->save();

            foreach ($lines as $position => $line) {
                (new PurchaseOrderLine())->forceFill([
                    'tenant_id' => $tenantId,
                    'purchase_order_id' => $po->id,
                    'position' => $position,
                    'inventory_item_id' => $line['item_id'],
                    'quantity' => StockQuantity::format($line['qty']),
                    'unit_cost' => $this->money($line['cents']),
                    'received_quantity' => '0.000',
                    'line_total' => $this->money($line['total']),
                ])->save();
            }

            return $po->load('lines');
        });
    }

    private function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
