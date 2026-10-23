<?php

namespace App\Http\Requests\Search;

use Illuminate\Foundation\Http\FormRequest;

final class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authentication is the route middleware; per-group authorization is in SearchService.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }

    public function term(): string
    {
        return (string) $this->validated()['q'];
    }
}
