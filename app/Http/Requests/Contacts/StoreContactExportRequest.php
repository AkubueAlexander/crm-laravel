<?php

namespace App\Http\Requests\Contacts;

use App\Domain\Contacts\Export\ContactsExport;
use App\Models\ContactExport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contacts.view') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', Rule::in(ContactExport::FORMATS)],
            'q' => ['nullable', 'string', 'max:255'],
            // Whitelisted: this value reaches ORDER BY.
            'sort' => ['nullable', 'string', Rule::in(ContactsExport::SORTABLE)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
