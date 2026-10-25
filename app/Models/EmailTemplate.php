<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Markdown email template (9.3). Tenant scoped through BelongsToTenant.
 * tenant_id and created_by are only ever set by the Actions (never mass assigned).
 */
class EmailTemplate extends Model
{
    use BelongsToTenant;

    public const MAX_NAME = 160;
    public const MAX_SUBJECT = 200;
    public const MAX_BODY = 50000;

    protected $fillable = ['name', 'subject', 'body'];
}