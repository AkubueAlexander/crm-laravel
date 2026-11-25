<?php

namespace App\Domain\Contacts\Actions;

use App\Domain\Tenant\TenantOwnership;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

final class UpdateContactAction
{
    /** @param array<string, mixed> $data Format rules live in UpdateContactRequest; invariants are enforced here. */
    public function execute(User $actor, Contact $contact, array $data): Contact
    {
        Gate::forUser($actor)->authorize('contacts.update');

        $data = Arr::only($data, CreateContactAction::KEYS);

        TenantOwnership::assertAccount('account_id', $data['account_id'] ?? null, (int) $actor->tenant_id);

        $contact->update($data);

        return $contact;
    }
}