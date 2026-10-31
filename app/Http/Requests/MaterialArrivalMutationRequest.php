<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaterialArrivalMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user()?->fresh();
        $action = in_array($this->input('operation'), ['restore', 'undo'], true) ? 'recover' : 'manage';

        return $user?->canDo('material-arrivals.view') && $user->canDo('material-arrivals.'.$action);
    }

    public function rules(): array
    {
        return [
            'operation' => ['required', Rule::in(['save', 'delete', 'import', 'restore', 'undo'])],
            'expected_revision' => ['required', 'integer:strict', 'min:0'],
            'request_id' => ['required', 'uuid'],
            'record' => ['required_if:operation,save', 'array'],
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
