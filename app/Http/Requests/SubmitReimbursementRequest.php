<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitReimbursementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('reimbursements.submit') === true && ! $this->user()->isTimebookOperator();
    }

    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'reimbursement_id' => ['nullable', 'integer'],
            'expected_version' => ['required_with:reimbursement_id', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['差旅交通', '客户招待', '办公采购', '其他'])],
            'note' => ['required', 'string', 'max:4000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.amount' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/'],
            'items.*.attachments' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.attachments.*' => ['required', 'uuid'],
            'cc' => ['present', 'array', 'max:50'],
            'cc.*' => ['integer', 'distinct', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'wechat_subscribed' => ['sometimes', 'boolean'],
        ];
    }
}
