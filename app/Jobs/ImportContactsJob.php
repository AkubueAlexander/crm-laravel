<?php

namespace App\Jobs;

use App\Domain\Contacts\Actions\CreateContactAction;
use App\Domain\Contacts\Import\ContactCsvReader;
use App\Domain\Contacts\Matching\ContactCandidate;
use App\Domain\Contacts\Matching\DuplicateDetectionService;
use App\Domain\Tenant\TenantContext;
use App\Events\ContactImportProgress;
use App\Http\Requests\Contacts\StoreContactRequest;
use App\Models\ContactImport;
use App\Models\ContactMatchSettings;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * 8b.3: heavy lifting stays server-side and queued (a large CSV scored inline would
 * time out an HTTP request). Runs AS the importing user: contacts.create is re-checked
 * through CreateContactAction, rows are validated with StoreContactRequest's own rules,
 * and likely duplicates (5.0) are skipped and reported rather than created.
 */
class ImportContactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    private const FLUSH_EVERY = 25;

    private const REPORT_CAP = 200;

    private bool $broadcastBroken = false;

    public function __construct(public readonly int $importId)
    {
    }

    public function handle(CreateContactAction $create, DuplicateDetectionService $duplicates): void
    {
        // System work: bypass the tenant scope explicitly; tenant context is set from the row below.
        $import = ContactImport::withoutTenantScope()->find($this->importId);

        // Idempotent: a retry, a double dispatch or a finished import is a no-op.
        if ($import === null || $import->status !== ContactImport::STATUS_PENDING) {
            return;
        }

        $user = User::query()->find($import->user_id);
        $tenant = $user?->tenant;

        if ($user === null || $tenant === null || (int) $tenant->id !== (int) $import->tenant_id) {
            $this->markFailed($import, 'The user who started this import is no longer available.');

            return;
        }

        TenantContext::set($tenant);
        setPermissionsTeamId($tenant->id);

        try {
            $this->process($import, $user, $create, $duplicates);
        } catch (AuthorizationException) {
            $this->markFailed($import, 'You no longer have permission to import contacts.');
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($import, 'The import stopped unexpectedly. Rows already imported were kept.');
        } finally {
            TenantContext::clear();
        }
    }

    /** Timeouts and worker crashes land here; handle()'s catch does not run for those. */
    public function failed(Throwable $e): void
    {
        $import = ContactImport::withoutTenantScope()->find($this->importId);

        if ($import !== null && in_array($import->status, [ContactImport::STATUS_PENDING, ContactImport::STATUS_PROCESSING], true)) {
            $this->markFailed($import, 'The import was interrupted. Rows already imported were kept.');
        }
    }

    private function process(
        ContactImport $import,
        User $user,
        CreateContactAction $create,
        DuplicateDetectionService $duplicates,
    ): void {
        // Fail fast if the permission was revoked while the job waited in the queue.
        Gate::forUser($user)->authorize('contacts.create');

        $import->update(['status' => ContactImport::STATUS_PROCESSING, 'started_at' => now()]);
        $this->broadcast($import);

        // Same rules as the single-contact form (14.2); account_id is not importable.
        $rules = Arr::except((new StoreContactRequest())->rules(), ['account_id']);

        // Explicit tenant predicate: queue workers are console processes, so the
        // threshold lookup must not depend on the global scope being active.
        $threshold = (int) (ContactMatchSettings::query()
            ->where('tenant_id', $import->tenant_id)
            ->value('match_threshold') ?? ContactMatchSettings::DEFAULT_THRESHOLD);

        $path = Storage::disk(ContactImport::DISK)->path($import->path);
        $report = [];

        foreach (ContactCsvReader::rows($path) as [$rowNumber, $data]) {
            $import->processed_rows++;

            $validator = Validator::make($data, $rules);

            if ($validator->fails()) {
                $import->invalid_rows++;
                $this->note($report, [
                    'row' => $rowNumber,
                    'type' => 'invalid',
                    'message' => $validator->errors()->first(),
                ]);
            } else {
                $matches = $duplicates->findDuplicates(
                    new ContactCandidate(
                        $data['first_name'] ?? null,
                        $data['last_name'] ?? null,
                        $data['email'] ?? null,
                        $data['phone'] ?? null,
                    ),
                    null,
                    $threshold,
                );

                if ($matches->isNotEmpty()) {
                    $best = $matches->first();
                    $import->duplicate_rows++;
                    $this->note($report, [
                        'row' => $rowNumber,
                        'type' => 'duplicate',
                        'message' => sprintf('Possible duplicate (%d%% match: %s)', $best->score->score, implode(', ', $best->score->reasons)),
                        'matched_contact_id' => $best->contact->id,
                    ]);
                } else {
                    $create->execute($user, Arr::only($data, ['first_name', 'last_name', 'email', 'phone', 'job_title']));
                    $import->created_rows++;
                }
            }

            if ($import->processed_rows % self::FLUSH_EVERY === 0) {
                $this->flush($import, $report);
            }
        }

        $import->status = ContactImport::STATUS_COMPLETED;
        $import->finished_at = now();
        $this->flush($import, $report);
        $this->cleanup($import);
    }

    /** @param list<array<string, mixed>> $report */
    private function note(array &$report, array $entry): void
    {
        if (count($report) < self::REPORT_CAP) {
            $report[] = $entry;
        }
    }

    /** @param list<array<string, mixed>> $report */
    private function flush(ContactImport $import, array $report): void
    {
        $import->report = $report;
        $import->save();
        $this->broadcast($import);
    }

    private function markFailed(ContactImport $import, string $message): void
    {
        $import->update([
            'status' => ContactImport::STATUS_FAILED,
            'error' => $message,
            'finished_at' => now(),
        ]);

        $this->broadcast($import);
        $this->cleanup($import);
    }

    /** A broadcast outage must never stop the import, so the first failure silences the rest. */
    private function broadcast(ContactImport $import): void
    {
        if ($this->broadcastBroken) {
            return;
        }

        try {
            event(new ContactImportProgress($import));
        } catch (Throwable $e) {
            $this->broadcastBroken = true;
            report($e);
        }
    }

    private function cleanup(ContactImport $import): void
    {
        try {
            Storage::disk(ContactImport::DISK)->delete($import->path);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
