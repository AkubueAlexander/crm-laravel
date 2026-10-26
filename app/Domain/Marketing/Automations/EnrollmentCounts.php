<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Automations;

use Illuminate\Support\Facades\DB;

/**
 * 9.6c-3: enrollment counts per automation and status, for the API. One grouped query for any number of
 * automations, with an explicit tenant predicate.
 */
final class EnrollmentCounts
{
    /** @return array{active: int, completed: int, cancelled: int, failed: int, total: int} */
    public static function empty(): array
    {
        return ['active' => 0, 'completed' => 0, 'cancelled' => 0, 'failed' => 0, 'total' => 0];
    }

    /**
     * @param  list<int|string>  $automationIds
     * @return array<int, array{active: int, completed: int, cancelled: int, failed: int, total: int}> keyed by automation id
     */
    public static function forAutomations(int $tenantId, array $automationIds): array
    {
        $out = [];
        foreach ($automationIds as $id) {
            $out[(int) $id] = self::empty();
        }
        if ($out === []) {
            return $out;
        }

        $rows = DB::table('automation_enrollments')
            ->where('tenant_id', $tenantId)
            ->whereIn('automation_id', array_keys($out))
            ->selectRaw('automation_id, status, count(*) as n')
            ->groupBy('automation_id', 'status')
            ->get();

        foreach ($rows as $row) {
            $id = (int) $row->automation_id;
            $status = (string) $row->status;
            if (isset($out[$id]) && $status !== 'total' && array_key_exists($status, $out[$id])) {
                $out[$id][$status] = (int) $row->n;
                $out[$id]['total'] += (int) $row->n;
            }
        }

        return $out;
    }
}