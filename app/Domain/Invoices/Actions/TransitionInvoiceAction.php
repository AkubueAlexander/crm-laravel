<?php

namespace App\Domain\Invoices\Actions;

use App\Domain\Invoices\Exceptions\InvoiceTransitionException;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 10.4a: the ONLY place an invoice changes status (state-machine pattern 3.0). The client sends { to_status,
 * lock_version, note? }; the server decides everything else. lock_version is bumped on every transition and a
 * mismatch is a 409 carrying the current invoice.
 *
 * draft -> issued | void ; issued -> paid | void ; paid and void are terminal. Issuing stamps issue_date (today) and
 * due_date (today + payment terms). Marking paid is manual until 10.6 adds real payment records.
 */
final class TransitionInvoiceAction
{
    public const TRANSITIONS = [
        'draft' => ['issued', 'void'],
        'issued' => ['paid', 'void'],
    ];

    public function execute(User $actor, int $invoiceId, string $toStatus, int $lockVersion, ?string $note = null): Invoice
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        if (! in_array($toStatus, Invoice::STATUSES, true)) {
            throw ValidationException::withMessages(['to_status' => ['Unknown status.']]);
        }

        Gate::forUser($actor)->authorize('invoices.manage');

        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        return DB::transaction(function () use ($actor, $invoiceId, $tenantId, $toStatus, $lockVersion, $note): Invoice {
            // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
            $invoice = Invoice::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($invoiceId);

            if ($invoice->lock_version !== $lockVersion) {
                throw InvoiceTransitionException::stale($invoice);
            }

            if (! in_array($toStatus, self::TRANSITIONS[$invoice->status] ?? [], true)) {
                throw InvoiceTransitionException::invalid($invoice->status, $toStatus);
            }

            $now = now();
            $changes = ['status' => $toStatus, 'lock_version' => $invoice->lock_version + 1];

            switch ($toStatus) {
                case 'issued':
                    $today = Carbon::today();
                    $changes += [
                        'issued_at' => $now,
                        'issued_by' => $actor->id,
                        'issue_date' => $today->toDateString(),
                        'due_date' => $today->copy()->addDays((int) $invoice->payment_terms_days)->toDateString(),
                    ];
                    break;

                case 'paid':
                    $changes += ['paid_at' => $now, 'paid_by' => $actor->id];
                    break;

                case 'void':
                    if ($note === null) {
                        throw InvoiceTransitionException::noteRequired();
                    }
                    $changes += ['voided_at' => $now, 'voided_by' => $actor->id, 'void_reason' => $note];
                    break;
            }

            $invoice->forceFill($changes)->save();

            return $invoice;
        });
    }
}
