<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactExport extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const FORMAT_CSV = 'csv';
    public const FORMAT_XLSX = 'xlsx';
    public const FORMATS = [self::FORMAT_CSV, self::FORMAT_XLSX];

    /** Export files live on a private disk and are only reachable through the signed download route. */
    public const DISK = 'local';

    public const MAX_ROWS = 50000;

    public const RETENTION_HOURS = 24;

    /** Overridable through config('crm.contact_export_max_rows') so tests need not create 50k rows. */
    public static function maxRows(): int
    {
        return max(1, (int) config('crm.contact_export_max_rows', self::MAX_ROWS));
    }

    // tenant_id is deliberately NOT fillable; BelongsToTenant stamps it from context (1.3).
    protected $fillable = [
        'user_id',
        'format',
        'filters',
        'status',
        'row_count',
        'path',
        'error',
        'expires_at',
        'started_at',
        'finished_at',
    ];

    /** Mirror the column defaults: Postgres defaults are not on the in-memory model after insert (see 8b.3c). */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'row_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->path !== null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_COMPLETED && ! $this->isDownloadable();
    }
}
