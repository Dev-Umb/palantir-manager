<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupportLibraryImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user()?->fresh();

        return $user?->canDo('support-library.view') && $user->canDo('support-library.manage');
    }

    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'file' => ['required', 'file', 'extensions:xlsx', 'max:10240'],
        ];
    }
}
