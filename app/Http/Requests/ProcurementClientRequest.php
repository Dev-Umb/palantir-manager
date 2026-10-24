<?php

namespace App\Http\Requests;

use App\Support\HubAccess;
use Illuminate\Foundation\Http\FormRequest;

class ProcurementClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return HubAccess::canManage($this->user());
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'user_id' => ['required', 'integer', 'exists:users,id'], 'days' => ['required', 'integer', 'min:1', 'max:365']];
    }
}
