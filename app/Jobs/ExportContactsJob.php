<?php

namespace App\Jobs;

use App\Domain\Contacts\Export\ContactsExport;
use App\Domain\Tenant\TenantContext;
use App\Events\ContactExportProgress;
use App\Models\ContactExport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

/**
 * 8b.4: generation stays server-side and queued. Runs AS the requesting user: contacts.view
 * is re-checked here (it may have been revoked while the job waited), tenant context is set
 * from the row, and the query carries an explicit tenant predicate because the BelongsToTenant
 * global scope is skipped in queue workers.
 */
class ExportContactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    private bool $broadcastBroken = false;

    public function __construct(public readonly int $exportId)
    {
    }

    public function handle(): void
    {
        // System work: bypass the tenant scope explicitly; tenant context is set from the row below.
        $export = ContactExport::withoutTenantScope()->find($this->exportId);

        // Idempotent: a retry, a double dispatch or a finished export is a no-op.
        if ($export === null || $export->status !== ContactExport::STATUS_PENDING) {
            return;
        }

        $user = User::query()->find($export->user_id);
        $tenant = $user?->tenant;

        if ($user === null || $tenant === null || (int) $tenant->id !== (int) $export->tenant_id) {
            $this->markFailed($export, 'The user who started this export is no longer available.');

            return;
        }

        TenantContext::set($tenant);
        setPermissionsTeamId($tenant->id);

        $written = null;

        try {
            $this->process($export, $user, $written);
        } catch (AuthorizationException) {
            $this->markFailed($export, 'You no longer have permission to export contacts.', $written);
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($export, 'The export stopped unexpectedly. Please try again.', $written);
        } finally {
            TenantContext::clear();
        }
    }

    /** Timeouts and worker crashes land here; handle()'s catch does not run for those. */
    public function failed(Throwable $e): void
    {
        $export = ContactExport::withoutTenantScope()->find($this->exportId);

        if ($export !== null && in_array($export->status, [ContactExport::STATUS_PENDING, ContactExport::STATUS_PROCESSING], true)) {
            $this->markFailed($export, 'The export was interrupted. Please try again.');
        }
    }

    private function process(ContactExport $export, User $user, ?string &$written): void
    {
        Gate::forUser($user)->authorize('contacts.view');

        $query = ContactsExport::queryFor((int) $export->tenant_id, $export->filters ?? []);
        $total = (clone $query)->reorder()->count();

        if ($total > ContactExport::maxRows()) {
            $this->markFailed($export, 'The data grew past the export limit while this was queued. Narrow the search.');

            return;
        }

        $export->update([
            'status' => ContactExport::STATUS_PROCESSING,
            'started_at' => now(),
            'row_count' => $total,
        ]);
        $this->broadcast($export);

        $path = sprintf('contact-exports/%d/%s.%s', $export->tenant_id, Str::uuid()->toString(), $export->format);
        $writerType = $export->format === ContactExport::FORMAT_XLSX ? 'Xlsx' : 'Csv';

        $written = $path;
        $stored = Excel::store(new ContactsExport($query), $path, ContactExport::DISK, $writerType);

        if ($stored !== true) {
            throw new RuntimeException('The export file could not be written.');
        }

        $export->update([
            'status' => ContactExport::STATUS_COMPLETED,
            'path' => $path,
            'finished_at' => now(),
            'expires_at' => now()->addHours(ContactExport::RETENTION_HOURS),
        ]);
        $this->broadcast($export);
    }

    private function markFailed(ContactExport $export, string $message, ?string $partialPath = null): void
    {
        $export->update([
            'status' => ContactExport::STATUS_FAILED,
            'error' => $message,
            'path' => null,
            'finished_at' => now(),
        ]);

        $this->broadcast($export);

        if ($partialPath !== null) {
            try {
                Storage::disk(ContactExport::DISK)->delete($partialPath);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** A broadcast outage must never stop the export, so the first failure silences the rest. */
    private function broadcast(ContactExport $export): void
    {
        if ($this->broadcastBroken) {
            return;
        }

        try {
            event(new ContactExportProgress($export));
        } catch (Throwable $e) {
            $this->broadcastBroken = true;
            report($e);
        }
    }
}
