<?php

namespace App\Domain\Accounting\Actions;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 12.0a: corrects a posted entry the only way an append-only ledger allows: by posting a NEW entry with every line's
 * debit and credit swapped, linked to the original (reverses_entry_id, unique). An entry can be reversed once, and a
 * reversal cannot itself be reversed (post a fresh entry instead). A reason is required. Needs accounting.manage.
 */
final class ReverseJournalEntryAction
{
    public function execute(User $actor, int $entryId, string $reason, ?string $entryDate = null): JournalEntry
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('accounting.manage');

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['reason' => ['Give a reason (max 200 characters).']]);
        }

        try {
            return DB::transaction(function () use ($actor, $entryId, $tenantId, $reason, $entryDate): JournalEntry {
                // Explicit tenant predicate on top of the global scope: another tenant's id is a 404.
                $entry = JournalEntry::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($entryId);

                if ($entry->reverses_entry_id !== null) {
                    throw ValidationException::withMessages(['entry' => ['A reversal cannot itself be reversed. Post a new entry instead.']]);
                }

                $already = JournalEntry::query()->where('tenant_id', $tenantId)->where('reverses_entry_id', $entry->id)->exists();
                if ($already) {
                    throw ValidationException::withMessages(['entry' => ['This entry has already been reversed.']]);
                }

                $lines = JournalLine::query()
                    ->where('tenant_id', $tenantId)
                    ->where('journal_entry_id', $entry->id)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (JournalLine $l) => [
                        'account_id' => $l->ledger_account_id,
                        'debit' => $l->credit,
                        'credit' => $l->debit,
                        'description' => $l->description,
                    ])
                    ->all();

                return app(PostJournalEntryAction::class)->execute(
                    $actor,
                    $entryDate ?? now()->toDateString(),
                    "Reversal of {$entry->entry_number}: {$reason}",
                    $lines,
                    null,
                    null,
                    $entry->id,
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['entry' => ['This entry has already been reversed.']]);
        }
    }
}
