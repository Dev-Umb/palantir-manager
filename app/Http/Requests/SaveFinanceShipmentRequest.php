<?php

namespace App\Http\Requests;

use App\Support\FinanceShipmentAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveFinanceShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(FinanceShipmentAccess::class)->allows($this->user());
    }

    public function rules(): array
    {
        return self::ledgerRules();
    }

    public static function ledgerRules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:0'],
            'orders' => ['present', 'array', 'max:3000'],
            'orders.*' => ['array:id,date,vehicle,rows,source'],
            'orders.*.id' => ['required', 'uuid', 'distinct'],
            'orders.*.date' => ['nullable', 'date_format:Y-m-d'],
            'orders.*.vehicle' => ['nullable', 'string', 'max:200'],
            'orders.*.source' => ['nullable', 'string', 'max:500'],
            'orders.*.rows' => ['present', 'array', 'max:500'],
            'orders.*.rows.*' => ['array:id,date,vehicle,name,unit,quantity,price,note,subtotal_starts,net_price,processing_fee'],
            'orders.*.rows.*.id' => ['required', 'uuid', 'distinct'],
            'orders.*.rows.*.date' => ['nullable', 'date_format:Y-m-d'],
            'orders.*.rows.*.vehicle' => ['nullable', 'string', 'max:200'],
            'orders.*.rows.*.name' => ['nullable', 'string', 'max:300'],
            'orders.*.rows.*.unit' => ['nullable', Rule::in(['吨', '件', '台', '米'])],
            'orders.*.rows.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:99999.999', 'decimal:0,3'],
            'orders.*.rows.*.net_price' => ['nullable', 'numeric', 'min:0', 'max:99999.99', 'decimal:0,2'],
            'orders.*.rows.*.processing_fee' => ['nullable', 'numeric', 'min:0', 'max:99999.99', 'decimal:0,2'],
            'orders.*.rows.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999.99', 'decimal:0,2'],
            'orders.*.rows.*.subtotal_starts' => ['sometimes', 'array', 'max:100'],
            'orders.*.rows.*.subtotal_starts.*' => ['required', 'uuid'],
            'orders.*.rows.*.note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
