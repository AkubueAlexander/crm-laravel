<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Domain\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;

class Activity extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** @var list<string> */
    public const TYPES = ['call', 'email', 'meeting', 'task', 'note'];

    /**
     * The ONLY records an activity may attach to. A client sends the alias; it can
     * never name a class. Deliberately a non-enforcing morph map: enforceMorphMap()
     * would also break Spatie roles and Sanctum tokens, which morph to User.
     *
     * @var array<string, class-string<Model>>
     */
    public const TARGETS = [
        'lead' => Lead::class,
        'contact' => Contact::class,
        'account' => Account::class,
        'deal' => Deal::class,
    ];

    // tenant_id and completed_at are deliberately NOT fillable.
    // BelongsToTenant stamps the tenant; CompleteActivityAction owns completed_at.
    /** @var list<string> */
    protected $fillable = [
        'activityable_type',
        'activityable_id',
        'type',
        'subject',
        'body',
        'due_at',
        'owner_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activityable_id' => 'integer',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public static function registerMorphMap(): void
    {
        Relation::morphMap(self::TARGETS);
    }

    /** @return class-string<Model>|null */
    public static function targetClass(string $alias): ?string
    {
        return self::TARGETS[$alias] ?? null;
    }

    /** The permission needed to see a target record (leads.view, contacts.view, ...). */
    public static function targetViewPermission(string $alias): string
    {
        return $alias . 's.view';
    }

    /** Owner must be a user of the current tenant (explicit check, not just the scope). */
    public static function ownerBelongsToTenant(int $userId): bool
    {
        if (! TenantContext::hasTenant()) {
            return false;
        }

        return User::query()
            ->whereKey($userId)
            ->where('tenant_id', TenantContext::id())
            ->exists();
    }

    public function activityable(): MorphTo
    {
        return $this->morphTo();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
