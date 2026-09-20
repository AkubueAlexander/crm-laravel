<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use BelongsToTenant, HasApiTokens, HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    // Auth resolves a User BEFORE tenant context exists (EloquentUserProvider::retrieveById/ByCredentials
    // runs pre-ResolveTenant). This model opts out of the tenant query-time filter for that reason --
    // the tenant_id column, tenant() relation, and save-time guard are untouched.
    protected static bool $skipTenantQueryScope = true;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Spatie teams-mode uses this to scope roles/permissions to the tenant â€”
     * see 2.0. Configured as `team_foreign_key => tenant_id` in config/permission.php.
     */
    public function getTeamIdAttribute(): int|string|null
    {
        return $this->tenant_id;
    }
}

