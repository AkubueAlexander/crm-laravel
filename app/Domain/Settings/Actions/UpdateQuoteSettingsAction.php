<?php

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\QuoteSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * 10.3c: sets (or clears) the quote auto-approval threshold of the actor's own tenant. The tenant comes from the
 * actor, never from the client (1.3). Server-side re-validation of permission and format (2.4, 14.2).
 */
final class UpdateQuoteSettingsAction
{
    public function __construct(private readonly QuoteSettings $settings)
    {
    }

    /** @return array{approval_threshold: string|null} */
    public function execute(User $actor, ?string $approvalThreshold): array
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('settings.manage');

        $value = $approvalThreshold === null ? null : trim($approvalThreshold);
        $value = $value === '' ? null : $value;

        if ($value !== null && ! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages([
                'approval_threshold' => ['Enter an amount like 500 or 500.00 (up to 2 decimals), or leave it empty.'],
            ]);
        }

        DB::table('tenants')->where('id', $tenantId)->update(['quote_approval_threshold' => $value]);

        return $this->settings->forTenant($tenantId);
    }
}
