<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DealStageAuditLog extends Model
{
    use BelongsToTenant;

    protected $table = 'deal_stage_audit_log';

    protected $fillable = [
        'deal_id',
        'user_id',
        'from_state',
        'to_state',
        'transitioned_at',
    ];

    protected $casts = [
        'transitioned_at' => 'datetime',
    ];

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
