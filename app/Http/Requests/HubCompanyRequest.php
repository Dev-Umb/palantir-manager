<?php

namespace App\Http\Requests;

use App\Support\HubAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HubCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return HubAccess::canManage($this->user());
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['capability', 'history'])],
            'name' => ['required', 'string', 'max:200'],
            'data' => ['required', 'array:product,qualifications,valid_until,capacity,regions,buyer,group_name,project_code,lot,outcome,amount,unit,tax,freight,reference,notes'],
            'data.*' => ['nullable', 'string', 'max:3000'],
            'data.valid_until' => ['nullable', 'date'],
            'data.outcome' => ['nullable', Rule::in(['submitted', 'won', 'lost', 'unknown'])],
        ];
    }
}
