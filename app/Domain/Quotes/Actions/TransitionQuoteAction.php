<?php

namespace App\Domain\Quotes\Actions;

use App\Domain\Quotes\Exceptions\QuoteTransitionException;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 10.3a: the ONLY place a quote changes status (state-machine pattern 3.0). The client sends { to_status,
 * lock_version, note? }; the server decides everything else. lock_version is bumped on every transition and a
 * mismatch is a 409 carrying the current quote. Editing lines is a draft-only concern of SaveQuoteAction.
 *
 * Submit (draft -> pending_approval) auto-approves when the tenant's quote_approval_threshold covers the total;
 * the returned quote then already says "approved", so the client reconciles with the response.
 */
final class TransitionQuoteAction
{
    /** from => allowed targets. Anything not listed is terminal (accepted, declined, expired, rejected). */
    public const TRANSITIONS = [
        'draft' => ['pending_approval'],
        'pending_approval' => ['approved', 'rejected', 'draft'],
        'approved' => ['sent'],
        'sent' => ['accepted', 'declined', 'expired'],
    ];

    /** Permission required to move INTO a status; everything else needs quotes.manage. */
    private const APPROVER_TARGETS = ['approved', 'rejected'];

    public function execute(User $actor, int $quoteId, string $toStatus, int $lockVersion, ?string $note = null): Quote
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        if (! in_array($toStatus, Quote::STATUSES, true)) {
            throw ValidationException::withMessages(['to_status' => ['Unknown status.']]);
        }

        Gate::forUser($actor)->authorize(in_array($toStatus, self::APPROVER_TARGETS, true) ? 'quotes.approve' : 'quotes.manage');

        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        return DB::transaction(function () use ($actor, $quoteId, $tenantId, $toStatus, $lockVersion, $note): Quote {
            // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
            $quote = Quote::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($quoteId);

            if ($quote->lock_version !== $lockVersion) {
                throw QuoteTransitionException::stale($quote);
            }

            if (! in_array($toStatus, self::TRANSITIONS[$quote->status] ?? [], true)) {
                throw QuoteTransitionException::invalid($quote->status, $toStatus);
            }

            $now = now();
            $changes = ['status' => $toStatus, 'lock_version' => $quote->lock_version + 1];

            switch ($toStatus) {
                case 'pending_approval':
                    $hasLines = QuoteLine::query()->where('tenant_id', $tenantId)->where('quote_id', $quote->id)->exists();
                    if (! $hasLines) {
                        throw QuoteTransitionException::emptyQuote();
                    }

                    $changes += ['submitted_at' => $now, 'submitted_by' => $actor->id, 'decided_at' => null, 'decided_by' => null, 'decision_note' => null];

                    $threshold = DB::table('tenants')->where('id', $tenantId)->value('quote_approval_threshold');
                    if ($threshold !== null && (int) round(((float) $quote->total) * 100) <= (int) round(((float) $threshold) * 100)) {
                        $changes['status'] = 'approved';
                        $changes['decided_at'] = $now;
                        $changes['decision_note'] = 'Auto-approved: the total is within the approval threshold.';
                    }
                    break;

                case 'approved':
                    $changes += ['decided_at' => $now, 'decided_by' => $actor->id, 'decision_note' => $note];
                    break;

                case 'rejected':
                    if ($note === null) {
                        throw QuoteTransitionException::noteRequired();
                    }
                    $changes += ['decided_at' => $now, 'decided_by' => $actor->id, 'decision_note' => $note];
                    break;

                case 'draft': // withdrawn by the submitter
                    $changes += ['submitted_at' => null, 'submitted_by' => null, 'decided_at' => null, 'decided_by' => null, 'decision_note' => null];
                    break;

                case 'sent':
                    $changes['sent_at'] = $now;
                    break;
            }

            $quote->forceFill($changes)->save();

            return $quote;
        });
    }
}
