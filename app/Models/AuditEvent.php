<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


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
