<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Exceptions\LeadNotConvertibleException;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * 8a.2: converts a Lead into a Contact (+ Account when the lead has a company)
 * and a Deal, then stamps the lead as converted. Single entry point; the
 * 'converted' status is written here directly and is deliberately not
 * assignable through StoreLeadRequest/UpdateLeadRequest.
 *
 * Everything runs in one transaction on a row-locked lead, so a double-click
 * or two racing requests cannot create duplicate records: the loser sees an
 * already-converted lead and gets a 409.
 *
 * Duplicate detection (5.0) is intentionally not run: a person already chose
 * to convert this specific lead.
 *
 * 9.9: the lead's attribution (source, lead form, UTM tags) is copied onto the
 * deal at creation, so revenue can be traced back to where the lead came from.
 */
final class ConvertLeadAction
{
    /**
     * @param  array{deal_name?: ?string, amount?: int|float|string|null, expected_close_date?: ?string}  $data
     *
     * @throws LeadNotConvertibleException
     */
    public function execute(User $actor, Lead $lead, array $data = []): Lead
    {
        // 2.4: server-side re-validation, independent of the request layer.
        Gate::forUser($actor)->authorize('leads.convert');

        return DB::transaction(function () use ($actor, $lead, $data): Lead {
            $locked = Lead::query()->lockForUpdate()->findOrFail($lead->id);

            if ($locked->status === 'converted') {
                throw LeadNotConvertibleException::alreadyConverted($locked);
            }

            // contacts.last_name is NOT NULL; leads.last_name is nullable.
            $lastName = trim((string) $locked->last_name);
            if ($lastName === '') {
                throw LeadNotConvertibleException::missingLastName($locked);
            }

            $account = $this->resolveAccount($locked);

            $contact = Contact::create([
                'account_id' => $account?->id,
                'first_name' => $locked->first_name,
                'last_name' => $lastName,
                'email' => $this->blankToNull($locked->email),
                'phone' => $this->blankToNull($locked->phone),
            ]);

            // board_id is deliberately not set: the deals migration reviewed for
            // this chunk has no such column. `state` is left to DealState's
            // configured default (Lead) rather than guessing its stored key.
            $deal = new Deal([
                'account_id' => $account?->id,
                'contact_id' => $contact->id,
                'name' => Str::limit($this->dealName($locked, $lastName, $data), 255, ''),
                'owner_id' => $locked->owner_id ?? $actor->id,
                'amount' => $data['amount'] ?? null,
                'expected_close_date' => $data['expected_close_date'] ?? null,
            ]);

            // 9.9: attribution is not mass-assignable on Deal; it is copied from the lead row, never from request data.
            $deal->forceFill($this->attribution($locked))->save();

            // Written directly (not via request validation): this is the only
            // place a lead becomes 'converted'.
            $locked->forceFill([
                'status' => 'converted',
                'converted_at' => now(),
                'converted_contact_id' => $contact->id,
                'converted_account_id' => $account?->id,
                'converted_deal_id' => $deal->id,
            ])->save();

            // 9.7: the status changed to converted.
            \App\Domain\Leads\Scoring\LeadScoring::applyTo($locked);

            return $locked;
        });
    }

    /** @return array{source: ?string, lead_form_id: ?int, utm_source: ?string, utm_medium: ?string, utm_campaign: ?string} */
    private function attribution(Lead $lead): array
    {
        return [
            'source' => $this->blankToNull($lead->source),
            'lead_form_id' => $lead->lead_form_id !== null ? (int) $lead->lead_form_id : null,
            'utm_source' => $this->blankToNull($lead->utm_source),
            'utm_medium' => $this->blankToNull($lead->utm_medium),
            'utm_campaign' => $this->blankToNull($lead->utm_campaign),
        ];
    }

    /** Reuses a same-named account in the current tenant, otherwise creates one. */
    private function resolveAccount(Lead $lead): ?Account
    {
        $company = trim((string) $lead->company);

        if ($company === '') {
            return null;
        }

        // The BelongsToTenant global scope keeps this lookup inside the tenant.
        $existing = Account::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($company)])
            ->first();

        return $existing ?? Account::create([
            'name' => $company,
            'owner_id' => $lead->owner_id,
        ]);
    }

    /** @param  array{deal_name?: ?string}  $data */
    private function dealName(Lead $lead, string $lastName, array $data): string
    {
        $custom = trim((string) ($data['deal_name'] ?? ''));

        if ($custom !== '') {
            return $custom;
        }

        $person = trim($lead->first_name.' '.$lastName);
        $company = trim((string) $lead->company);

        return $company !== '' ? $company.' - '.$person : $person;
    }

    private function blankToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
