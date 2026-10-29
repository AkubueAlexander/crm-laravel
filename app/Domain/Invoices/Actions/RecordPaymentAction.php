<?php

namespace App\Domain\Invoices\Actions;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 10.6a: records a payment on an ISSUED invoice. Partial payments are fine; overpaying is not. The balance check
 * and the update run under the invoice row lock, so two cashiers can never overpay together. The invoice's
 * lock_version is bumped on every payment (other open screens get a 409 on their next action). When the balance
 * reaches zero the invoice moves to paid. All amounts are integer cents; the client never sends totals.
 */
final class RecordPaymentAction
{
    public const METHODS = ['bank_transfer', 'card', 'cash', 'cheque', 'other'];

    public function execute(User $actor, int $invoiceId, string $amount, string $method, ?string $paidOn = null, ?string $reference = null, ?string $note = null): Invoice
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('invoices.manage');

        $amount = trim($amount);
        $reference = $reference === null ? null : trim($reference);
        $reference = $reference === '' ? null : $reference;
        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;
        $paidOn = $paidOn === null || trim($paidOn) === '' ? Carbon::today()->toDateString() : trim($paidOn);

        $errors = [];

        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount)) {
            $errors['amount'] = ['Enter a valid amount (up to 2 decimals).'];
        } elseif ($this->cents($amount) <= 0) {
            $errors['amount'] = ['Amount must be greater than zero.'];
        }

        if (! in_array($method, self::METHODS, true)) {
            $errors['method'] = ['Unknown payment method.'];
        }

        $d = \DateTime::createFromFormat('Y-m-d', $paidOn);
        if (! $d || $d->format('Y-m-d') !== $paidOn) {
            $errors['paid_on'] = ['Use a valid date (YYYY-MM-DD).'];
        } elseif ($paidOn > Carbon::today()->toDateString()) {
            $errors['paid_on'] = ['The payment date cannot be in the future.'];
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

        $amountCents = $this->cents($amount);

        return DB::transaction(function () use ($actor, $invoiceId, $tenantId, $amountCents, $method, $paidOn, $reference, $note): Invoice {
            // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
            $invoice = Invoice::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($invoiceId);

            if ($invoice->status !== 'issued') {
                throw ValidationException::withMessages(['invoice' => ['Payments can only be recorded on issued invoices.']]);
            }

            $paidCents = $this->cents($invoice->amount_paid);
            $balanceCents = $this->cents($invoice->total) - $paidCents;

            if ($amountCents > $balanceCents) {
                throw ValidationException::withMessages(['amount' => ['Amount exceeds the outstanding balance of '.$this->money($balanceCents).'.']]);
            }

            $recorded = (new Payment())->forceFill([
                'tenant_id' => $tenantId,
                'invoice_id' => $invoice->id,
                'amount' => $this->money($amountCents),
                'method' => $method,
                'paid_on' => $paidOn,
                'reference' => $reference,
                'note' => $note,
                'recorded_by' => $actor->id,
            ]);
            $recorded->save();

            $newPaid = $paidCents + $amountCents;
            $changes = ['amount_paid' => $this->money($newPaid), 'lock_version' => $invoice->lock_version + 1];

            if ($newPaid >= $this->cents($invoice->total)) {
                $changes += ['status' => 'paid', 'paid_at' => now(), 'paid_by' => $actor->id];
            }

            $invoice->forceFill($changes)->save();

            app(\App\Domain\Accounting\Posting\InvoiceLedgerPoster::class)->paymentRecorded($actor, $recorded, $invoice);

            return $invoice->load('payments');
        });
    }

    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
