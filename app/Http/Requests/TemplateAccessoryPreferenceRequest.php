<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TemplateAccessoryPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user()?->fresh();

        return $user?->canDo('template-accessories.view')
            && (! in_array($this->input('key'), ['draft', 'inline'], true) || $user->canDo('template-accessories.manage'))
            && ($this->input('key') !== 'backup' || $user->canDo('template-accessories.export'));
    }

    public function rules(): array
    {
        return [
            'key' => ['required', Rule::in(['draft', 'inline', 'filters', 'backup'])],
            'value' => ['nullable', 'string', 'max:524288', 'json'],
            'expected_revision' => ['required', 'integer:strict', 'min:0'],
        ];
    }
}
