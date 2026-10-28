<?php

namespace App\Domain\Quotes\Actions;

use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/** 10.1: only a DRAFT quote can be deleted; anything that left draft is history. Lines go with it (FK cascade). */
final class DeleteQuoteAction
{
    public function execute(User $actor, int $quoteId): void
    {
        Gate::forUser($actor)->authorize('quotes.manage');

        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        DB::transaction(function () use ($quoteId, $tenantId): void {
            $quote = Quote::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($quoteId);

            if (! $quote->isDraft()) {
                throw ValidationException::withMessages(['status' => ['Only draft quotes can be deleted.']]);
            }

            $quote->delete();
        });
    }
}
