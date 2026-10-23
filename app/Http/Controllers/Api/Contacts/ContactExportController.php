<?php

namespace App\Http\Controllers\Api\Contacts;

use App\Domain\Contacts\Actions\StartContactExportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\StoreContactExportRequest;
use App\Http\Resources\ContactExportResource;
use App\Models\ContactExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ContactExportController extends Controller
{
    public function store(StoreContactExportRequest $request, StartContactExportAction $action): JsonResponse
    {
        $export = $action->execute($request->user(), $request->validated());

        // 202: accepted for background processing; progress arrives over the user channel.
        return (new ContactExportResource($export))->response()->setStatusCode(202);
    }

    public function show(Request $request, ContactExport $export): ContactExportResource
    {
        $this->authorizeOwner($request, $export);

        return new ContactExportResource($export);
    }

    /** Reached only through a signed URL, and still requires the owner to be authenticated. */
    public function download(Request $request, ContactExport $export): StreamedResponse
    {
        $this->authorizeOwner($request, $export);

        abort_unless($export->status === ContactExport::STATUS_COMPLETED, 404);

        $disk = Storage::disk(ContactExport::DISK);

        if (! $export->isDownloadable() || ! $disk->exists((string) $export->path)) {
            abort(410, 'This export has expired. Start a new one.');
        }

        $name = 'contacts-'.$export->created_at->format('Ymd-His').'.'.$export->format;

        return $disk->download($export->path, $name);
    }

    private function authorizeOwner(Request $request, ContactExport $export): void
    {
        Gate::authorize('contacts.view');

        // Tenant scoping already 404s another tenant's export; an export is also private to its starter.
        abort_unless((int) $export->user_id === (int) $request->user()->id, 404);
    }
}
