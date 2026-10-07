<?php

namespace App\Http\Requests;

use App\Support\QuotationAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuotationCalculateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return QuotationAccess::allows($this->user());
    }

    public static function parameterRules(): array
    {
        return [
            'params' => ['required', 'array:project_id,project_name,customer,product,spec,mode,unit,quantity,days,tax_rate,tax_basis,shipping,destination,terms,extra_fee,price_date,market,material,steel_spec'],
            'params.project_id' => ['nullable', 'uuid'],
            'params.project_name' => ['required', 'string', 'max:180'],
            'params.customer' => ['required', 'string', 'max:180'],
            'params.product' => ['required', 'string', 'max:180'],
            'params.spec' => ['required', 'string', 'max:600'],
            'params.mode' => ['required', Rule::in(['total', 'unit'])],
            'params.unit' => ['required', Rule::in(['吨', '套', '吨日'])],
            'params.quantity' => ['nullable', 'required_if:params.mode,total', 'numeric', 'decimal:0,3', 'gt:0', 'max:100000'],
            'params.days' => ['nullable', 'required_if:params.unit,吨日', 'integer', 'min:1', 'max:3650'],
            'params.tax_rate' => ['required', 'integer', Rule::in([0, 1, 3, 6, 9, 13])],
            'params.tax_basis' => ['required', Rule::in(['含税', '未税'])],
            'params.shipping' => ['required', Rule::in(['含运费', '不含运费'])],
            'params.destination' => ['required', 'string', 'max:300'],
            'params.terms' => ['required', 'string', 'max:1500'],
            'params.extra_fee' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
            'params.price_date' => ['required', 'date_format:Y-m-d'],
            'params.market' => ['required', 'string', 'max:80'],
            'params.material' => ['required', 'string', 'max:80'],
            'params.steel_spec' => ['required', 'string', 'max:150'],
        ];
    }

    public function rules(): array
    {
        return [...self::parameterRules(), 'fee_token' => ['required', 'string', 'max:12000'], 'steel_token' => ['required', 'string', 'max:12000'], 'params_confirmed' => ['accepted']];
    }
}
