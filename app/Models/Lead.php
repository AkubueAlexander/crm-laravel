<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'company',
        'source',
        'status',
        'owner_id',
        'converted_at',
        'converted_contact_id',
        'converted_account_id',
        'converted_deal_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function convertedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'converted_contact_id');
    }

    public function convertedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'converted_account_id');
    }
}