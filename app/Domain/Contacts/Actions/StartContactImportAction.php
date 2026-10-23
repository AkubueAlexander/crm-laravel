<?php

namespace App\Domain\Contacts\Actions;

use App\Domain\Contacts\Import\ContactCsvReader;
use App\Jobs\ImportContactsJob;
use App\Models\ContactImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class StartContactImportAction
{
    public function execute(User $actor, UploadedFile $file): ContactImport
    {
        Gate::forUser($actor)->authorize('contacts.create');

        // Reject a bad header or an oversized file synchronously, with a clear message,
        // instead of failing silently later on a queue worker.
        try {
            $total = ContactCsvReader::inspect((string) $file->getRealPath());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        $path = $file->storeAs("contact-imports/{$actor->tenant_id}", Str::uuid()->toString().'.csv', ContactImport::DISK);

        if ($path === false) {
            throw new RuntimeException('The CSV could not be stored.');
        }

        // tenant_id is stamped from the request's tenant context by BelongsToTenant.
        $import = ContactImport::create([
            'user_id' => $actor->id,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'status' => ContactImport::STATUS_PENDING,
            'total_rows' => $total,
        ]);

        ImportContactsJob::dispatch($import->id);

        return $import;
    }
}
