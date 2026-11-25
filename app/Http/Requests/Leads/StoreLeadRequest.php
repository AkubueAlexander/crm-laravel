<?php

namespace App\Http\Requests\Leads;

use App\Domain\Leads\LeadRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeadRequest extends FormRequest
{
    public const ASSIGNABLE_STATUSES = LeadRules::ASSIGNABLE_STATUSES;

    public function authorize(): bool
    {
        return $this->user()?->can('leads.create') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return LeadRules::store((int) $this->user()?->tenant_id);
    }
}