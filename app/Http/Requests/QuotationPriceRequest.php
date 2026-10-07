<?php

namespace App\Http\Requests;

use App\Support\QuotationAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuotationPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return QuotationAccess::allows($this->user());
    }

    public function rules(): array
    {
        $rules = QuotationCalculateRequest::parameterRules();
        unset($rules['params.quantity'], $rules['params.days']);

        return [
            ...$rules,
            'kind' => ['required', Rule::in(['fee', 'steel'])],
            'source' => ['required', Rule::in(['user', 'history', 'internet'])],
            'amount' => ['required_if:source,user', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10000000'],
            'source_note' => ['required_if:source,user', 'nullable', 'string', 'max:500'],
            'suggestion_token' => ['required_unless:source,user', 'nullable', 'string', 'max:12000'],
            'confirmed' => ['accepted'],
        ];
    }
}
