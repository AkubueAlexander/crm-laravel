<?php

namespace App\Domain\Quotes\Actions;

use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;


final class DeleteQuoteAction
{
    public function execute(User $actor, int $quoteId): void
    {
        Gate::forUser($actor)->authorize('quotes.manage');


        DB::transaction(function () use ($quoteId): void {
            $quote = Quote::query()->lockForUpdate()->findOrFail($quoteId);

            if (! $quote->isDraft()) {
                throw ValidationException::withMessages(['status' => ['Only draft quotes can be deleted.']]);
            }

            $quote->delete();
        });
    }
}
