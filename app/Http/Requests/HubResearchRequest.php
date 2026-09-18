<?php

namespace App\Http\Requests;

use App\Support\HubAccess;
use Illuminate\Foundation\Http\FormRequest;

class HubResearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return HubAccess::canRead($this->user());
    }

    public function rules(): array
    {
        return ['query' => ['required_without:notice_id', 'nullable', 'string', 'max:300'], 'notice_id' => ['nullable', 'integer', 'exists:hub_notices,id']];
    }
}
