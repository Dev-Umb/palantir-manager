<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TemplateAccessoryMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user()?->fresh();
        $action = in_array($this->input('operation'), ['restore', 'undo'], true) ? 'recover' : 'manage';

        return $user?->canDo('template-accessories.view') && $user->canDo('template-accessories.'.$action);
    }

    public function rules(): array
    {
        return [
            'operation' => ['required', Rule::in(['save', 'delete', 'import', 'restore', 'undo'])],
            'expected_revision' => ['required', 'integer:strict', 'min:0'],
            'request_id' => ['required', 'uuid'],
            'entries' => ['required_if:operation,save', 'array', 'list', 'min:1', 'max:50000'],
            'entries.*.record' => ['required', 'array'],
            'entries.*.version' => ['nullable', 'integer:strict', 'min:1'],
            'kind' => ['required_if:operation,import', Rule::in(['台车模板', '开挖台架', '液压配件'])],
            'replace' => ['required_if:operation,import', 'boolean:strict'],
            'version' => ['nullable', 'integer:strict', 'min:1'],
            'id' => ['required_if:operation,delete', 'string', 'max:150'],
            'deleted' => ['required_if:operation,delete', 'boolean:strict'],
            'rows' => ['required_if:operation,import', 'array', 'list', 'max:50000'],
            'rows.*' => ['array'],
            'hash' => ['required_if:operation,import', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'name' => ['required_if:operation,import', 'string', 'max:500'],
            'dataset_json' => ['required_if:operation,restore', 'string', 'max:52428800', 'json'],
            'checksum' => ['required_if:operation,restore', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }
}
