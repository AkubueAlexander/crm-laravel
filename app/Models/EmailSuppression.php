<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * 9.4b: an address this tenant must never be mailed again. Written through EmailSuppressions::suppress().
 */
class EmailSuppression extends Model
{
    use BelongsToTenant;

    protected $fillable = [];
}