<?php

namespace App\Http\Requests;

use App\Support\HubAccess;
use App\Support\HubSourceAdapters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HubSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return HubAccess::canManage($this->user());
    }

    protected function prepareForValidation(): void
    {
        foreach (['allowed_hosts', 'keywords'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => array_values(array_filter(array_map('trim', preg_split('/[,，\n]+/u', $this->input($field)))))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'], 'url' => ['required', 'url:http,https', 'max:2048'],
            'allowed_hosts' => ['required', 'array', 'min:1', 'max:10'],
            'allowed_hosts.*' => ['required', 'string', 'regex:/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/'],
            'keywords' => ['nullable', 'array', 'max:30'], 'keywords.*' => ['string', 'max:50'],
            'adapter' => ['required', Rule::in([...HubSourceAdapters::SUPPORTED, 'unverified'])], 'enabled' => ['required', 'boolean'], 'interval_minutes' => ['required', 'integer', 'min:60', 'max:10080'],
        ];
    }
}
