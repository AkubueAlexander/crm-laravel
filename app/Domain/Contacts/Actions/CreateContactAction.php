<?php

namespace App\Domain\Contacts\Actions;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class CreateContactAction
{
    /** @param array<string, mixed> $data Already validated by StoreContactRequest. */
    public function execute(User $actor, array $data): Contact
    {
        Gate::forUser($actor)->authorize('contacts.create');


        $contact = Contact::create($data);

        // 9.6c-1: let active "contact_created" automations enroll the new contact (queued after commit).
        \App\Jobs\EnrollForTriggerJob::fire((int) $contact->tenant_id, 'contact_created', (int) $contact->id, null);

        return $contact;
    }
}
