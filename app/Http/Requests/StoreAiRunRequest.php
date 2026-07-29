<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAiRunRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => trim($this->input('message'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'string', 'max:36'],
            'client_request_id' => ['required', 'uuid'],
            'retry_parent_id' => ['nullable', 'uuid'],
        ];
    }
}
