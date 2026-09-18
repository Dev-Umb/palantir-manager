<?php

namespace App\Http\Requests;

use App\Support\TimebookName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TimebookEntryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->canDo('timebook.view')
            && $this->user()->canDo($this->route('entry') ? 'timebook.update' : 'timebook.create');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => TimebookName::normalize($this->input('name'))]);
        }
        $this->merge([
            'days' => $this->input('days', 1), 'overtime' => $this->input('overtime', 0),
            'project' => $this->input('project') ?? '', 'note' => $this->input('note') ?? '',
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40'],
            'day' => ['required', 'date_format:Y-m-d'],
            'days' => ['required', 'numeric', 'in:0,0.5,1'],
            'overtime' => ['required', 'numeric', 'between:0,24'],
            'project' => ['present', 'string', 'max:80'],
            'note' => ['present', 'string', 'max:500'],
            'version' => [$this->route('entry') ? 'required' : 'sometimes', 'integer:strict', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['days', 'overtime'] as $field) {
                $value = $this->input($field);
                if (is_bool($value) || ! is_numeric($value) || ! is_finite((float) $value)) {
                    $validator->errors()->add($field, '请输入有效数值。');
                }
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $minutes = (float) $this->input('overtime') * 60;
            if (abs($minutes - round($minutes)) > 0.0000001) {
                $validator->errors()->add('overtime', '加班必须能准确换算为整数分钟。');
            }
            if ((float) $this->input('days') === 0.0 && $minutes === 0.0) {
                $validator->errors()->add('days', '工日和加班不能同时为零。');
            }
        }];
    }
}
