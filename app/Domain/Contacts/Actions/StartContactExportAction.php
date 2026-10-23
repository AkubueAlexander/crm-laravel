<?php

namespace App\Domain\Contacts\Actions;

use App\Domain\Contacts\Export\ContactsExport;
use App\Jobs\ExportContactsJob;
use App\Models\ContactExport;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class StartContactExportAction
{
    /** @param array<string, mixed> $input validated: format, q, sort, direction */
    public function execute(User $actor, array $input): ContactExport
    {
        Gate::forUser($actor)->authorize('contacts.view');

        $filters = ContactsExport::normalizeFilters($input);

        // Reject an empty or oversized export synchronously, with a clear message.
        $total = ContactsExport::queryFor((int) $actor->tenant_id, $filters)->reorder()->count();

        if ($total === 0) {
            throw ValidationException::withMessages([
                'q' => ['No contacts match this search, so there is nothing to export.'],
            ]);
        }

        if ($total > ContactExport::maxRows()) {
            throw ValidationException::withMessages([
                'q' => [sprintf(
                    'This export would have %s rows; the limit is %s. Narrow it with a search.',
                    number_format($total),
                    number_format(ContactExport::maxRows()),
                )],
            ]);
        }

        // tenant_id is stamped from the request's tenant context by BelongsToTenant.
        $export = ContactExport::create([
            'user_id' => $actor->id,
            'format' => (string) $input['format'],
            'filters' => $filters,
            'status' => ContactExport::STATUS_PENDING,
            'row_count' => $total,
        ]);

        ExportContactsJob::dispatch($export->id);

        return $export;
    }
}
