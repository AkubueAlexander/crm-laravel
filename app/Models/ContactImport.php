<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactImport extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /** Uploaded files live on a private disk and are deleted once the import finishes. */
    public const DISK = 'local';

    // tenant_id is deliberately NOT fillable; BelongsToTenant stamps it from context (1.3).
    protected $fillable = [
        'user_id',
        'original_name',
        'path',
        'status',
        'total_rows',
        'processed_rows',
        'created_rows',
        'duplicate_rows',
        'invalid_rows',
        'report',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'report' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
