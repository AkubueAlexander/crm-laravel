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
            // 8c.2: set = the account is deactivated (not deleted). Never mass-assignable.
            'deactivated_at' => 'datetime',
        ];
    }


    /**
     * Broadcast notifications go to the tenant-scoped private channel (1.5), not
     * the tenant-blind App.Models.User.{id} default.
     */
    public function receivesBroadcastNotificationsOn(\Illuminate\Notifications\Notification $notification): string
    {
        return 'tenant.' . $this->tenant_id . '.user.' . $this->getKey();
    }

    public function getTeamIdAttribute(): int|string|null
    {
        return $this->tenant_id;
    }
}

