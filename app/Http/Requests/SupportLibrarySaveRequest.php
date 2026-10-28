<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupportLibrarySaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user()?->fresh();

        return $user?->canDo('support-library.view') && $user->canDo('support-library.manage');
    }

    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer:strict', 'min:1'],
            'request_id' => ['required', 'uuid'],
            'parts' => ['present', 'array', 'max:20000'],
            'frames' => ['present', 'array', 'max:20000'],
            'composition' => ['present', 'array', 'max:20000'],
        ];
    }
}
