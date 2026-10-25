<?php

namespace App\Http\Requests\Marketing;

use App\Domain\Marketing\Segments\SegmentDsl;
use App\Models\Segment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** A definition to evaluate without saving it: same match_mode and conditions rules as a save, no name. */
class PreviewSegmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('marketing.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'match_mode' => ['required', 'string', Rule::in(Segment::MATCH_MODES)],
            'conditions' => ['present', 'array'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('conditions')) {
                    return;
                }

                foreach (SegmentDsl::errors($this->input('conditions')) as $key => $message) {
                    $validator->errors()->add($key, $message);
                }
            },
        ];
    }
}
