<?php

namespace App\Domain\Marketing\Segments;

use App\Models\Contact;
use App\Models\Segment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * 9.2: turns validated, normalized conditions into a tenant-scoped contacts query.
 *
 * Plain domain code with no request or session dependency, so the 9.4 send job can call it from a queue
 * worker: the tenant is an explicit argument (defense in depth; the caller must also have set TenantContext, as
 * jobs do, because the global tenant scope still applies and matches nothing with no ambient tenant). Field expressions
 * come from SegmentDsl constants; every value is a bound parameter. An operator it does not know throws
 * (UnhandledMatchError) instead of being skipped, so a bad definition fails closed.
 */
final class SegmentQueryBuilder
{
    /**
     * @param  list<array{field: string, operator: string, value: string|int|null}>  $conditions
     * @return Builder<Contact>
     */
    public static function contactsFor(int $tenantId, string $matchMode, array $conditions): Builder
    {
        $boolean = $matchMode === Segment::MATCH_ANY ? 'or' : 'and';

        return Contact::query()
            ->where('contacts.tenant_id', $tenantId)
            // One nested group, so "any" can never escape the tenant predicate above.
            ->where(function (Builder $group) use ($conditions, $boolean): void {
                foreach ($conditions as $condition) {
                    [$sql, $bindings] = self::clause($condition);
                    $group->whereRaw($sql, $bindings, $boolean);
                }
            });
    }

    /** @param list<array{field: string, operator: string, value: string|int|null}> $conditions */
    public static function count(int $tenantId, string $matchMode, array $conditions): int
    {
        return self::contactsFor($tenantId, $matchMode, $conditions)->count();
    }

    /**
     * @param  array{field: string, operator: string, value: string|int|null}  $condition
     * @return array{0: string, 1: list<string|int>}
     */
    private static function clause(array $condition): array
    {
        $definition = SegmentDsl::definition($condition['field']);
        $expr = $definition['expr'];
        $value = $condition['value'] ?? null;
        $presence = in_array($definition['type'], ['presence', 'number'], true);

        return match ($condition['operator']) {
            'contains' => ["{$expr} like ?", [self::like((string) $value, true)]],
            'not_contains' => ["coalesce({$expr}, '') not like ?", [self::like((string) $value, true)]],
            'starts_with' => ["{$expr} like ?", [self::like((string) $value, false)]],
            'equals' => ["{$expr} = ?", [mb_strtolower((string) $value)]],
            'not_equals' => ["coalesce({$expr}, '') <> ?", [mb_strtolower((string) $value)]],
            'is_empty' => [$presence ? "{$expr} is null" : "coalesce(trim({$expr}), '') = ''", []],
            'is_not_empty' => [$presence ? "{$expr} is not null" : "coalesce(trim({$expr}), '') <> ''", []],
            'before' => ["{$expr} < ?", [CarbonImmutable::parse((string) $value)->startOfDay()->toDateTimeString()]],
            'after' => ["{$expr} >= ?", [CarbonImmutable::parse((string) $value)->addDay()->startOfDay()->toDateTimeString()]],
            'in_last_days' => ["{$expr} >= ?", [now()->subDays((int) $value)->toDateTimeString()]],
            'gte' => ["{$expr} >= ?", [(int) $value]],
            'lte' => ["{$expr} <= ?", [(int) $value]],
            'num_equals' => ["{$expr} = ?", [(int) $value]],
        };
    }

    /** A LIKE pattern with the user's %, _ and \ escaped, so they match literally. */
    private static function like(string $value, bool $leadingWildcard): string
    {
        return ($leadingWildcard ? '%' : '').addcslashes(mb_strtolower($value), '\\%_').'%';
    }
}
