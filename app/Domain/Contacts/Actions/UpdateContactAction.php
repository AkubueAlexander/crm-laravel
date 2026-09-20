<?php

namespace App\Domain\Contacts\Actions;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class UpdateContactAction
{
    /** @param array<string, mixed> $data Already validated by UpdateContactRequest. */
    public function execute(User $actor, Contact $contact, array $data): Contact
    {
        Gate::forUser($actor)->authorize('contacts.update');

        $contact->update($data);

        return $contact;
    }
}
