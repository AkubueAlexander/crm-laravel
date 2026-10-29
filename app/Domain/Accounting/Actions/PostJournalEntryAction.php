<?php

namespace App\Domain\Accounting\Actions;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 12.0a: the ONLY writer of the journal. Posts one balanced, append-only entry: at least two lines, each exactly one
 * of a debit or a credit (cents, never floats), every account in the actor's tenant and active, and total debits ==
 * total credits (refused with the two totals otherwise). Numbering (JE-0001, ...) is allocated per tenant under an
 * advisory lock. $reversesEntryId is set only by ReverseJournalEntryAction (inactive accounts are then allowed so
 * history can always be undone). Needs accounting.manage.
 *
 * $lines: list of { account_id, debit, credit, description? } where debit/credit are decimal strings (one empty or 0).
 */
final class PostJournalEntryAction
{
    public const MAX_LINES = 100;

    /** @param list<array<string, mixed>> $lines */
    public function execute(
        User $actor,
        string $entryDate,
        ?string $memo,
        array $lines,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $reversesEntryId = null,
        bool $authorize = true,
    ): JournalEntry {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        // $authorize = false is for system postings (InvoiceLedgerPoster) whose caller already passed its own permission check.
        if ($authorize) {
            Gate::forUser($actor)->authorize('accounting.manage');
        }

        $errors = [];

        $entryDate = trim($entryDate);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $entryDate);
        if ($d === false || $d->format('Y-m-d') !== $entryDate) {
            $errors['entry_date'] = ['Use the format YYYY-MM-DD.'];
        }

        $memo = $memo === null ? null : trim($memo);
        $memo = $memo === '' ? null : $memo;
        if ($memo !== null && mb_strlen($memo) > 255) {
            $errors['memo'] = ['Memo must be 255 characters or fewer.'];
        }

        $sourceType = $sourceType === null || trim($sourceType) === '' ? null : trim($sourceType);
        if ($sourceType !== null && mb_strlen($sourceType) > 32) {
            $errors['source_type'] = ['Source type must be 32 characters or fewer.'];
        }

        $parsed = [];
        $debitTotal = 0;
        $creditTotal = 0;

        if (count($lines) < 2) {
            $errors['lines'] = ['A journal entry needs at least two lines.'];
        } elseif (count($lines) > self::MAX_LINES) {
            $errors['lines'] = ['A journal entry can have at most '.self::MAX_LINES.' lines.'];
        } else {
            foreach (array_values($lines) as $i => $row) {
                $row = is_array($row) ? $row : [];
                $accountId = (int) ($row['account_id'] ?? 0);
                $debit = $this->cents($row['debit'] ?? null);
                $credit = $this->cents($row['credit'] ?? null);
                $description = trim((string) ($row['description'] ?? ''));
                $description = $description === '' ? null : $description;

                if ($accountId <= 0) {
                    $errors["lines.$i.account_id"] = ['Choose an account.'];
                }
                if ($debit === null) {
                    $errors["lines.$i.debit"] = ['Enter an amount with up to 2 decimals.'];
                }
                if ($credit === null) {
                    $errors["lines.$i.credit"] = ['Enter an amount with up to 2 decimals.'];
                }
                if ($description !== null && mb_strlen($description) > 255) {
                    $errors["lines.$i.description"] = ['Description must be 255 characters or fewer.'];
                }

                if ($debit !== null && $credit !== null) {
                    if ($debit > 0 && $credit > 0) {
                        $errors["lines.$i.debit"] = ['A line is either a debit or a credit, not both.'];
                    } elseif ($debit === 0 && $credit === 0) {
                        $errors["lines.$i.debit"] = ['Enter a debit or a credit amount.'];
                    } else {
                        $debitTotal += $debit;
                        $creditTotal += $credit;
                    }
                }

                $parsed[$i] = ['account_id' => $accountId, 'debit' => $debit ?? 0, 'credit' => $credit ?? 0, 'description' => $description];
            }

            if ($errors === [] && $debitTotal !== $creditTotal) {
                $errors['lines'] = ['Debits ('.$this->money($debitTotal).') must equal credits ('.$this->money($creditTotal).').'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($actor, $tenantId, $entryDate, $memo, $sourceType, $sourceId, $reversesEntryId, $parsed): JournalEntry {
            $accounts = LedgerAccount::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', array_column($parsed, 'account_id'))
                ->get()
                ->keyBy('id');

            $lineErrors = [];
            foreach ($parsed as $i => $line) {
                $account = $accounts->get($line['account_id']);
                if ($account === null || ($reversesEntryId === null && ! $account->is_active)) {
                    $lineErrors["lines.$i.account_id"] = ['Choose an active account.'];
                }
            }
            if ($lineErrors !== []) {
                throw ValidationException::withMessages($lineErrors);
            }

            // Serialise numbering per tenant; the unique index is the backstop.
            DB::select('select pg_advisory_xact_lock(?, ?)', [7103, $tenantId]);
            $seq = ((int) JournalEntry::query()->where('tenant_id', $tenantId)->max('entry_seq')) + 1;

            $entry = new JournalEntry();
            $entry->forceFill([
                'tenant_id' => $tenantId,
                'entry_seq' => $seq,
                'entry_number' => 'JE-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                'entry_date' => $entryDate,
                'memo' => $memo,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reverses_entry_id' => $reversesEntryId,
                'posted_by' => $actor->id,
            ])->save();

            foreach ($parsed as $position => $line) {
                (new JournalLine())->forceFill([
                    'tenant_id' => $tenantId,
                    'journal_entry_id' => $entry->id,
                    'ledger_account_id' => $line['account_id'],
                    'position' => $position,
                    'debit' => $this->money($line['debit']),
                    'credit' => $this->money($line['credit']),
                    'description' => $line['description'],
                ])->save();
            }

            return $entry->load('lines');
        });
    }

    /** Cents from a decimal string (up to 10 integer digits, 2 decimals); empty = 0; null when malformed. */
    private function cents(mixed $value): ?int
    {
        $v = trim((string) ($value ?? ''));
        if ($v === '') {
            return 0;
        }
        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $v)) {
            return null;
        }
        [$whole, $frac] = array_pad(explode('.', $v, 2), 2, '');

        return ((int) $whole) * 100 + (int) str_pad($frac, 2, '0');
    }

    private function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
