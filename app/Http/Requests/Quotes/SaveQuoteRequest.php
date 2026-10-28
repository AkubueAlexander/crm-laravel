<?php

namespace App\Http\Requests\Quotes;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and update share one rule set. The body says what is sold, never what it costs: totals, number, version
 * and status are not accepted (unknown keys are dropped by validated()). `lines` must be present so an absent key
 * can never mean "wipe every line". Ownership of account/contact/product/variant and the per-line catalog rules
 * are re-checked in SaveQuoteAction (2.4).
 */
class SaveQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('quotes.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'account_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'lines' => ['present', 'array', 'max:100'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.variant_id' => ['nullable', 'integer'],
            'lines.*.name' => ['nullable', 'string', 'max:160'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.tax_category' => ['nullable', 'string', Rule::in(Product::TAX_CATEGORIES)],
        ];
    }
}
