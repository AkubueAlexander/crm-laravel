<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 8c.2c: an administrator's invitation for an email address to join a tenant with a role.
 *
 * Deliberately NOT BelongsToTenant: the public accept endpoint finds an invitation by its token
 * before any tenant is known, so (like User) every query here carries an explicit tenant_id
 * predicate, and the token hash is the only public lookup key.
 */
class UserInvitation extends Model
{
    /** Never mass-assigned; Actions use forceFill with values they computed. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
