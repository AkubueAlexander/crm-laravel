<?php

namespace App\Http\Controllers\Api\Contacts;

use App\Domain\Contacts\Actions\CreateContactAction;
use App\Domain\Contacts\Actions\DeleteContactAction;
use App\Domain\Contacts\Actions\UpdateContactAction;
use App\Domain\Contacts\Matching\DuplicateDetectionService;
use App\Domain\Contacts\Matching\DuplicateMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\CheckContactDuplicatesRequest;
use App\Http\Requests\Contacts\ListContactsRequest;
use App\Http\Requests\Contacts\StoreContactRequest;
use App\Http\Requests\Contacts\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Models\ContactMatchSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;

final class ContactController extends Controller
{
    public function index(ListContactsRequest $request): AnonymousResourceCollection
    {
        $v = $request->validated();
        $q = trim($v['q'] ?? '');

        $contacts = Contact::query()
            ->when($q !== '', function ($query) use ($q) {
                // Escape LIKE wildcards; the trigram GIN index serves LIKE on name_normalized.
                $like = '%'.addcslashes(mb_strtolower($q), '\\%_').'%';
                $query->where(fn ($w) => $w
                    ->where('name_normalized', 'like', $like)
                    ->orWhere('email_normalized', 'like', $like));
            })
            ->orderBy($v['sort'] ?? 'last_name', $v['direction'] ?? 'asc')
            ->orderBy('id') // stable tiebreaker so pages never overlap
            ->paginate($v['per_page'] ?? 25)
            ->withQueryString();

        return ContactResource::collection($contacts);
    }

    public function show(Contact $contact): ContactResource
    {
        Gate::authorize('contacts.view');

        return new ContactResource($contact);
    }

    public function store(StoreContactRequest $request, CreateContactAction $action): ContactResource
    {
        // A freshly created model makes Laravel respond 201 automatically.
        return new ContactResource($action->execute($request->user(), $request->validated()));
    }

    public function update(UpdateContactRequest $request, Contact $contact, UpdateContactAction $action): ContactResource
    {
        return new ContactResource($action->execute($request->user(), $contact, $request->validated()));
    }

    public function destroy(Request $request, Contact $contact, DeleteContactAction $action): Response
    {
        $action->execute($request->user(), $contact);

        return response()->noContent();
    }

    public function checkDuplicates(
        CheckContactDuplicatesRequest $request,
        DuplicateDetectionService $service,
    ): JsonResponse {
        $threshold = ContactMatchSettings::currentThreshold();

        $matches = $service->findDuplicates(
            $request->candidate(),
            $request->excludeContactId(),
            $threshold,
        );

        return response()->json([
            'data' => $matches->map(fn (DuplicateMatch $match) => [
                'contact' => new ContactResource($match->contact),
                'score' => $match->score->score,
                'reasons' => $match->score->reasons,
            ])->all(),
            'threshold' => $threshold,
        ]);
    }
}
