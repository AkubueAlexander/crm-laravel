<?php

namespace App\Models;

use App\Domain\Deals\States\DealState;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\ModelStates\HasStates;

class Deal extends Model
{
    use BelongsToTenant, HasFactory, HasStates, SoftDeletes;

    protected $fillable = [
        'board_id',
        'name',
        'owner_id',
        'amount',
        'expected_close_date',
        'state',
    ];

    protected $casts = [
        'state' => DealState::class,
        'amount' => 'decimal:2',
        'expected_close_date' => 'date',
        'lock_version' => 'integer',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(DealStageAuditLog::class);
    }
}
