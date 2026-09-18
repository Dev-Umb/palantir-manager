<?php

namespace App\Http\Requests;

use App\Support\HubAccess;
use Illuminate\Foundation\Http\FormRequest;

class HubSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return HubAccess::canRead($this->user());
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'filters' => ['required', 'array:q,region,kind'], 'filters.q' => ['required', 'string', 'max:100'], 'filters.region' => ['nullable', 'string', 'max:100'], 'filters.kind' => ['nullable', 'in:notice,amendment,candidate,award,termination,intent']];
    }
}
