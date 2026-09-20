<?php

namespace App\Models;


use App\Domain\Tenant\Concerns\BelongsToTenant;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    // tenant_id is deliberately NOT fillable; BelongsToTenant stamps it from context (1.3).
    protected $fillable = [
        'account_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'job_title',
    ];

    // Generated matching columns are storage detail, never serialized.
    protected $hidden = ['name_normalized', 'email_normalized', 'phone_key'];

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(
            fn () => trim(($this->first_name ?? '').' '.$this->last_name)
        );
    }
}
