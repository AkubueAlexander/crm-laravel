<?php

namespace App\Http\Requests\Products;

use App\Domain\Products\ProductRules;
use Illuminate\Foundation\Http\FormRequest;

class SaveProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ProductRules::all();
    }
}