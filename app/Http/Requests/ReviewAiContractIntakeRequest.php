<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ReviewAiContractIntakeRequest extends StoreAiContractIntakeRequest
{
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'uuid'],
            'contract_id' => ['nullable', 'uuid'],
            'fields' => ['required', 'array:amount,ctype,signed_date,contract_qty'],
            'fields.amount' => ['required', 'numeric'],
            'fields.ctype' => ['nullable', Rule::in(['销售合同', '加工合同', '补充协议'])],
            'fields.signed_date' => ['nullable', 'date_format:Y-m-d'],
            'fields.contract_qty' => ['nullable', 'numeric', 'min:0'],
            'update_project_amount' => ['required', 'boolean'],
            'project_weight' => ['nullable', 'numeric', 'min:0'],
            'unpaid_amount' => ['prohibited'],
            'paid_amount' => ['prohibited'],
            'occurred_amount' => ['prohibited'],
        ];
    }
}
