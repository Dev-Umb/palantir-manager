<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductionWorkspaceMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('material-arrivals.view') && $this->user()->canDo('template-accessories.view');
    }

    public function rules(): array
    {
        return ['operation' => ['required', 'in:save,restore,undo'], 'expected_revision' => ['required', 'integer:strict', 'min:0'], 'request_id' => ['required', 'uuid'], 'checksum' => ['required_if:operation,restore', 'string', 'size:64'], 'dataset_json' => ['required_unless:operation,undo', 'string', 'json', 'max:52428800']];
    }
}
