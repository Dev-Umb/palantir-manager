<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupportLibraryCommitRequest extends FormRequest
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
            'preview_token' => ['required', 'uuid'],
            'request_id' => ['required', 'uuid'],
        ];
    }
}
