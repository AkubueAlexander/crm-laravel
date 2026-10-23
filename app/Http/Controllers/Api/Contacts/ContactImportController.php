<?php

namespace App\Http\Controllers\Api\Contacts;

use App\Domain\Contacts\Actions\StartContactImportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\StoreContactImportRequest;
use App\Http\Resources\ContactImportResource;
use App\Models\ContactImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ContactImportController extends Controller
{
    public function store(StoreContactImportRequest $request, StartContactImportAction $action): JsonResponse
    {
        $import = $action->execute($request->user(), $request->file('file'));

        // 202: accepted for background processing; progress arrives over the user channel.
        return (new ContactImportResource($import))->response()->setStatusCode(202);
    }

    public function show(Request $request, ContactImport $import): ContactImportResource
    {
        Gate::authorize('contacts.create');

        // Tenant scoping already 404s another tenant's import; an import is also private to its starter.
        abort_unless((int) $import->user_id === (int) $request->user()->id, 404);

        return new ContactImportResource($import);
    }
}
