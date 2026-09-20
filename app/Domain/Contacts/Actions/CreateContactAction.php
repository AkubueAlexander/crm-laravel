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

        // tenant_id is stamped by BelongsToTenant from TenantContext, never from $data.
        return Contact::create($data);
    }
}
