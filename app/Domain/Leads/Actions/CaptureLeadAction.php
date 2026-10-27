<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Forms\LeadFormDsl;
use App\Domain\Leads\Scoring\LeadScoring;
use App\Jobs\EnrollForTriggerJob;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * 9.8: creates a lead from a PUBLIC form submission. CreateLeadAction authorizes a signed-in user, which a public
 * visitor is not, so this is the system-side twin: same post-create steps (lead_created automations, scoring),
 * no user gate. The caller (SubmitLeadFormAction) has already validated the data and set TenantContext.
 *
 * Only the whitelisted form fields are ever read; status, owner, tenant and score are decided here, not by the visitor.
 * An email that already belongs to a live lead in the tenant creates nothing (returns null): the visitor still sees
 * success, and a bot cannot flood the tenant with duplicates.
 */
final class CaptureLeadAction
{
    /** @param array<string, mixed> $data validated form values */
    public function execute(int $tenantId, array $data, ?string $source): ?Lead
    {
        $clean = array_intersect_key($data, LeadFormDsl::FIELDS);
        $email = isset($clean['email']) ? trim((string) $clean['email']) : '';

        if ($email !== '' && DB::table('leads')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->exists()) {
            return null;
        }

        $source = $source !== null && trim($source) !== '' ? trim($source) : 'web';

        $lead = new Lead();
        $lead->fill($clean + []);
        $lead->fill(['source' => $source, 'status' => 'new']);
        $lead->tenant_id = $tenantId;
        $lead->save();

        EnrollForTriggerJob::fire((int) $lead->tenant_id, 'lead_created', null, (int) $lead->id);

        LeadScoring::applyTo($lead);

        return $lead;
    }
}