<?php

namespace App\Domain\Contacts\Actions;

use App\Jobs\EnrollForTriggerJob;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CreateContactAction
{
    /** @param array<string, mixed> $data Already validated by StoreContactRequest. */
    public function execute(User $actor, array $data): Contact
    {
        Gate::forUser($actor)->authorize('contacts.create');

        return DB::transaction(function () use ($data) {
            $contact = Contact::create($data);

            EnrollForTriggerJob::fire(
                (int) $contact->tenant_id,
                'contact_created',
                (int) $contact->id,
                null
            );

            return $contact;
        });
    }
}
