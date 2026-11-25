<?php

namespace App\Domain\Contacts\Actions;

use App\Domain\Tenant\TenantOwnership;
use App\Jobs\EnrollForTriggerJob;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CreateContactAction
{
    public const KEYS = ['first_name', 'last_name', 'email', 'phone', 'job_title', 'account_id'];

    /** @param array<string, mixed> $data Format rules live in StoreContactRequest; invariants are enforced here. */
    public function execute(User $actor, array $data): Contact
    {
        Gate::forUser($actor)->authorize('contacts.create');

        $data = Arr::only($data, self::KEYS);

        TenantOwnership::assertAccount('account_id', $data['account_id'] ?? null, (int) $actor->tenant_id);

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