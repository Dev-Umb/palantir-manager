<?php

namespace App\Http\Requests;

use App\Support\QuotationAccess;
use Illuminate\Foundation\Http\FormRequest;

class QuotationAdoptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return QuotationAccess::allows($this->user());
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:180'], 'adoption_key' => ['required', 'uuid'], 'preview_token' => ['required', 'string', 'max:100000']];
    }
}
