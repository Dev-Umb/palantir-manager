<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaterialArrivalPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user()?->fresh();

        return $user?->canDo('material-arrivals.view')
            && ($this->input('key') !== 'draft' || $user->canDo('material-arrivals.manage'))
            && ($this->input('key') !== 'backup' || $user->canDo('material-arrivals.export'));
    }

    public function rules(): array
    {
        return [
            'key' => ['required', Rule::in(['draft', 'filters', 'backup'])],
            'value' => ['nullable', 'string', 'max:524288', 'json'],
            'expected_revision' => ['required', 'integer:strict', 'min:0'],
        ];
    }
}
