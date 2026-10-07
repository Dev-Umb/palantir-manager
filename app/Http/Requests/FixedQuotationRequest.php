<?php

namespace App\Http\Requests;

use App\Support\QuotationAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FixedQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return QuotationAccess::allows($this->user());
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:20', 'regex:/^[^\r\n\t]+$/u'],
            'date' => ['required', 'date_format:Y-m-d'],
            'contact' => ['required', 'string', 'max:12', 'regex:/^[^\r\n\t]+$/u'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+() -]+$/'],
            'tax_rate' => ['required', 'in:13'],
            'shipping' => ['required', 'in:含运费'],
            'items' => ['required', 'array', 'min:1', 'max:3'],
            'items.*' => ['array:name,unit,price,material_price,processing_price'],
            'items.*.name' => ['required', 'string', 'max:24', 'regex:/^[^\r\n\t]+$/u'],
            'items.*.unit' => ['required', 'string', 'in:吨,套,吨日,件,台,米,平方米'],
            'items.*.price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'items.*.material_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'items.*.processing_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => '请填写 :attribute。', 'max' => ':attribute 超出模板可填写范围。',
            'numeric' => ':attribute 必须为数字。', 'min' => ':attribute 不能小于零。',
            'decimal' => ':attribute 最多保留两位小数。', 'date_format' => '请选择有效报价日期。',
            'in' => ':attribute 与固定模板口径不符，请核对。', 'regex' => ':attribute 格式不正确，请核对。',
            'array' => '报价明细格式不正确。',
        ];
    }

    public function attributes(): array
    {
        return ['title' => '报价标题', 'date' => '报价日期', 'contact' => '联系人', 'phone' => '联系电话', 'tax_rate' => '税率', 'shipping' => '运费', 'items' => '产品明细', 'items.*.name' => '物资名称', 'items.*.price' => '综合单价', 'items.*.unit' => '计价单位', 'items.*.material_price' => '材料费', 'items.*.processing_price' => '加工费'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! is_array($this->input('items'))) {
                return;
            }
            foreach ($this->input('items', []) as $index => $item) {
                if (! is_array($item) || ! is_numeric($item['price'] ?? null)) {
                    continue;
                }
                $material = $item['material_price'] ?? null;
                $processing = $item['processing_price'] ?? null;
                if (is_numeric($material) && is_numeric($processing) && (int) round((float) $material * 100) + (int) round((float) $processing * 100) !== (int) round((float) $item['price'] * 100)) {
                    $validator->errors()->add("items.{$index}.price", '材料费与加工费之和应等于综合单价，请核对。');
                }
            }
        }];
    }
}
