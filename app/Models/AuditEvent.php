<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 8c.3: one administrative event. Like UserInvitation it is deliberately NOT BelongsToTenant:
 * it is written from places where the tenant context may be set or cleared (invitation accept),
 * so tenant_id is always passed explicitly by AuditRecorder and every read adds its own predicate.
 */
class AuditEvent extends Model
{
    public $timestamps = false;

    protected $table = 'audit_events';

    protected $guarded = ['*'];

    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
    ];
}
