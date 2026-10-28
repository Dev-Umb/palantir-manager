<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupportLibraryQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user()?->fresh();

        return $user?->canDo('support-library.view') && $user->canDo('support-library.view');
    }

    public function rules(): array
    {
        return [
            'library_revision' => ['required', 'integer:strict', 'min:1'],
            'request_fingerprint' => ['required', 'string', 'max:200'],
            'entries' => ['required', 'array', 'min:1', 'max:20'],
            'entries.*.expression' => ['required', 'string', 'max:20000'],
            'entries.*.criteria' => ['required', 'array'],
            'entries.*.criteria.support_use' => ['required', 'in:吊装,顶推'],
            'entries.*.criteria.diameter_mm' => ['required', 'integer:strict', 'in:273,530'],
            'entries.*.criteria.column_brace_model' => ['present', 'nullable', 'in:C14A,C16A'],
        ];
    }
}
