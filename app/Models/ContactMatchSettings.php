<?php

namespace App\Models;


use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ContactMatchSettings extends Model
{
    use BelongsToTenant;

    public const DEFAULT_THRESHOLD = 75;

    protected $table = 'contact_match_settings';

    protected $fillable = ['match_threshold'];

    protected function casts(): array
    {
        return ['match_threshold' => 'integer'];
    }

    /**
     * Threshold for the current tenant. Reads through the tenant global scope,
     * so no tenant id is passed around and cross-tenant reads are impossible.
     */
    public static function currentThreshold(): int
    {
        return (int) (static::query()->value('match_threshold') ?? self::DEFAULT_THRESHOLD);
    }
}
