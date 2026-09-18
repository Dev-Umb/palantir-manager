<?php

namespace App\Http\Requests;

use App\Models\ObjectRecord;
use App\Support\ProjectVisibility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConvertTenderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $record = $this->route('record');

        return $record instanceof ObjectRecord
            && $record->businessObject?->key === 'tender'
            && (bool) $this->user()?->canDo('object.tender.update')
            && app(ProjectVisibility::class)->allowsRecordWrite($this->user(), $record);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'assignee_user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
