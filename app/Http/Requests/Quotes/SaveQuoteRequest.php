<?php

namespace App\Http\Requests\Quotes;

use App\Domain\Quotes\QuoteRules;
use Illuminate\Foundation\Http\FormRequest;

class SaveQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('quotes.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return QuoteRules::all();
    }
}