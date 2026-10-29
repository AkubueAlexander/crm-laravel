<?php

namespace App\Domain\Invoices\Actions;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 10.6a: reverses a payment (the row stays, marked voided, with who/when/why). Allowed while the invoice is issued
 * or paid; reversing a payment on a paid invoice reopens it (paid -> issued), a correction rather than a workflow
 * move. Runs under the invoice row lock and bumps lock_version.
 */
final class VoidPaymentAction
{
    public function execute(User $actor, int $paymentId, string $reason): Invoice
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('invoices.manage');

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => ['Give a reason (max 1000 characters) for reversing a payment.']]);
        }

        return DB::transaction(function () use ($actor, $paymentId, $tenantId, $reason): Invoice {
            // Explicit tenant predicates on top of the global scope: another tenant's id is a 404.
            $payment = Payment::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($paymentId);
            $invoice = Invoice::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($payment->invoice_id);

            if ($payment->voided_at !== null) {
                throw ValidationException::withMessages(['payment' => ['This payment was already reversed.']]);
            }

            if (! in_array($invoice->status, ['issued', 'paid'], true)) {
                throw ValidationException::withMessages(['invoice' => ['Payments can only be reversed on issued or paid invoices.']]);
            }

            $payment->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason])->save();

            $newPaid = max(0, (int) round(((float) $invoice->amount_paid) * 100) - (int) round(((float) $payment->amount) * 100));
            $changes = ['amount_paid' => number_format($newPaid / 100, 2, '.', ''), 'lock_version' => $invoice->lock_version + 1];

            if ($invoice->status === 'paid') {
                $changes += ['status' => 'issued', 'paid_at' => null, 'paid_by' => null];
            }

            $invoice->forceFill($changes)->save();

            app(\App\Domain\Accounting\Posting\InvoiceLedgerPoster::class)->paymentVoided($actor, $payment, $invoice);

            return $invoice->load('payments');
        });
    }
}
