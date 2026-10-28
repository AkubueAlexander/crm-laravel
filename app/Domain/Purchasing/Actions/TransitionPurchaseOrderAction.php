<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Exceptions\PurchaseOrderTransitionException;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 11.2a: the ONLY place a purchase order changes status by a workflow move (state-machine pattern 3.0).
 * The client sends { to_status, lock_version, note? }. lock_version is bumped on every transition; a mismatch is
 * PurchaseOrderTransitionException (409 with the current PO). Receiving is NOT a transition here: it has its own
 * Action (ReceivePurchaseOrderAction) because it moves stock.
 *
 * draft -> ordered | cancelled ; ordered -> cancelled. A PO with any stock received can no longer be cancelled.
 */
final class TransitionPurchaseOrderAction
{
    public const TRANSITIONS = [
        'draft' => ['ordered', 'cancelled'],
        'ordered' => ['cancelled'],
    ];

    public function execute(User $actor, int $poId, string $toStatus, int $lockVersion, ?string $note = null): PurchaseOrder
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        if (! in_array($toStatus, PurchaseOrder::STATUSES, true)) {
            throw ValidationException::withMessages(['to_status' => ['Unknown status.']]);
        }

        Gate::forUser($actor)->authorize('inventory.manage');

        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        if ($note !== null && mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['note' => ['Note must be 2000 characters or fewer.']]);
        }

        return DB::transaction(function () use ($actor, $poId, $tenantId, $toStatus, $lockVersion, $note): PurchaseOrder {
            // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
            $po = PurchaseOrder::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($poId);

            if ($po->lock_version !== $lockVersion) {
                throw PurchaseOrderTransitionException::stale($po);
            }

            if (! in_array($toStatus, self::TRANSITIONS[$po->status] ?? [], true)) {
                throw ValidationException::withMessages(['to_status' => ["A purchase order that is {$po->status} cannot become {$toStatus}."]]);
            }

            $changes = ['status' => $toStatus, 'lock_version' => $po->lock_version + 1];

            if ($toStatus === 'ordered') {
                $changes += ['ordered_at' => now(), 'ordered_by' => $actor->id];
            } else {
                if ($note === null) {
                    throw ValidationException::withMessages(['note' => ['Give a reason for cancelling.']]);
                }
                $changes += ['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => $note];
            }

            $po->forceFill($changes)->save();

            return $po;
        });
    }
}
