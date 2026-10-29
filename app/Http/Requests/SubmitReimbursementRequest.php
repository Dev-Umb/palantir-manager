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
            'draft_id' => ['nullable', 'uuid'],
            'draft_revision' => ['required_with:draft_id', 'integer', 'min:1'],
            'submission_key' => ['required', 'uuid'],
            'reimbursement_id' => ['nullable', 'integer'],
            'expected_version' => ['required_with:reimbursement_id', 'integer', 'min:1'],
            'batch_review_confirmed' => ['sometimes', 'boolean'],
            'title' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(config('reimbursements.types'))],
            'note' => ['required', 'string', 'max:4000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.invoice_date' => ['nullable', 'date_format:Y-m-d'],
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
