<?php

namespace App\Domain\Contacts\Actions;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteContactAction
{
    /** Soft delete; the row stays for audit and later restore. */
    public function execute(User $actor, Contact $contact): void
    {
        Gate::forUser($actor)->authorize('contacts.delete');

        $contact->delete();
    }
}
