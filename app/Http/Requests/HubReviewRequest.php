<?php

namespace App\Http\Requests;

use App\Support\HubAccess;
use Illuminate\Foundation\Http\FormRequest;

class HubReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return HubAccess::canManage($this->user());
    }

    public function rules(): array
    {
        return ['action' => 'required|in:withdraw,research', 'note' => 'required|string|max:2000'];
    }
}
