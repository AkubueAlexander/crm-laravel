<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 9.2: a stored, re-evaluatable filter over contacts. match_mode and conditions are never mass-assigned:
 * Actions validate them with SegmentDsl, normalize them, and forceFill the result.
 */
class Segment extends Model
{
    use BelongsToTenant;

    public const MATCH_ALL = 'all';

    public const MATCH_ANY = 'any';

    public const MATCH_MODES = ['all', 'any'];

    protected $fillable = ['name', 'description'];

    // Postgres column defaults are not on a freshly inserted in-memory model.
    protected $attributes = [
        'match_mode' => self::MATCH_ALL,
    ];

    protected $casts = [
        'conditions' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
