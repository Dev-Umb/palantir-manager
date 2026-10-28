<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveReimbursementWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('reimbursements.configure') === true;
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:0'],
            'nodes' => ['required', 'array', 'min:1', 'max:20'],
            'nodes.*.name' => ['required', 'string', 'max:80'],
            'nodes.*.mode' => ['required', Rule::in(['any', 'all'])],
            'nodes.*.people' => ['required', 'array', 'min:1', 'max:30'],
            'nodes.*.people.*' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'cc' => ['present', 'array', 'max:50'],
            'cc.*' => ['integer', 'distinct', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'triggers' => ['present', 'array', 'max:23'],
            'triggers.*' => ['string', 'distinct', Rule::in(['submitted', 'approved', 'rejected', ...array_map(fn ($i) => 'node:'.$i, range(0, count(is_array($this->input('nodes')) ? $this->input('nodes') : []) - 1))])],
        ];
    }
}
