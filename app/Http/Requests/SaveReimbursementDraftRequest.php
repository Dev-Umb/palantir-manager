<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveReimbursementDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('reimbursements.submit') === true && ! $this->user()->isTimebookOperator();
    }

    public function rules(): array
    {
        return self::draftRules();
    }

    public static function draftRules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:0'],
            'payload' => ['required', 'array:title,type,note,items,cc,reimbursement_id,expected_version'],
            'payload.title' => ['nullable', 'string', 'max:120'],
            'payload.type' => ['nullable', Rule::in(config('reimbursements.types'))],
            'payload.note' => ['nullable', 'string', 'max:4000'],
            'payload.items' => ['present', 'array', 'max:50'],
            'payload.items.*' => ['array:description,amount,invoice_date,attachments'],
            'payload.items.*.description' => ['nullable', 'string', 'max:500'],
            'payload.items.*.amount' => ['nullable', 'string', 'max:32'],
            'payload.items.*.invoice_date' => ['nullable', 'string', 'max:32'],
            'payload.items.*.attachments' => ['present', 'array', 'max:10'],
            'payload.items.*.attachments.*' => ['uuid', 'distinct'],
            'payload.cc' => ['present', 'array', 'max:50'],
            'payload.cc.*' => ['integer', 'distinct'],
            'payload.reimbursement_id' => ['nullable', 'integer'],
            'payload.expected_version' => ['required_with:payload.reimbursement_id', 'integer', 'min:1'],
        ];
    }
}
