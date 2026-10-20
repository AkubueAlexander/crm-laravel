<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Exceptions\LeadAlreadyConvertedException;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConvertLeadAction
{
    /**
     * Convert a Lead into a Contact + Deal in a single transaction (8a.2).
     *
     * tenant_id is intentionally never set here â€” BelongsToTenant's creating
     * hook derives it from the authenticated session (1.3), same as every
     * other Action in this app.
     *
     * Field mapping assumes Contact has name/email/phone/company_name/owner_id
     * columns mirroring Lead's own fields â€” adjust here if your Contact
     * schema (5.0) differs.
     *
     * board_id is hardcoded to 1 as a placeholder: there's no tenant
     * "default board" setting yet (would live alongside 8c.1's pipeline
     * customization). Wire that up before this ships past a demo.
     *
     * @return array{contact: Contact, deal: Deal}
     */
    public function execute(Lead $lead, User $actingUser): array
    {
        if ($lead->status === 'converted' || $lead->converted_at !== null) {
            throw new LeadAlreadyConvertedException($lead);
        }

        return DB::transaction(function () use ($lead, $actingUser) {
            $contact = Contact::create([
                'name' => $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'company_name' => $lead->company,
                'owner_id' => $lead->owner_id ?? $actingUser->id,
            ]);

            $deal = Deal::create([
                'board_id' => 1,
                'name' => trim($lead->name.' â€” '.($lead->company ?: 'Deal')),
                'owner_id' => $lead->owner_id ?? $actingUser->id,
                'amount' => null,
                'expected_close_date' => null,
                'state' => 'lead',
            ]);

            $lead->forceFill([
                'status' => 'converted',
                'converted_at' => now(),
                'contact_id' => $contact->id,
                'deal_id' => $deal->id,
            ])->save();

            return ['contact' => $contact, 'deal' => $deal];
        });
    }
}