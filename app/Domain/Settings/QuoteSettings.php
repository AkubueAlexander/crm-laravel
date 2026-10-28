<?php

namespace App\Domain\Settings;

use Illuminate\Support\Facades\DB;

/**
 * 10.3c: the editable quote settings of one tenant. approval_threshold is a decimal string ('100.00') or null:
 * a quote whose total is at or below it is auto-approved on submit (TransitionQuoteAction); null means every
 * quote needs a human approver. The explicit tenant predicate keeps the read inside the actor's tenant (1.3).
 */
final class QuoteSettings
{
    /** @return array{approval_threshold: string|null} */
    public function forTenant(int $tenantId): array
    {
        $value = DB::table('tenants')->where('id', $tenantId)->value('quote_approval_threshold');

        return [
            'approval_threshold' => $value === null ? null : number_format((float) $value, 2, '.', ''),
        ];
    }
}
