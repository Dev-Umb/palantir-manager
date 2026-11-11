<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SteelGirderMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('steel-girder-projects.view') && $this->user()->canDo('steel-girder-projects.manage');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reviews_json')) {
            $decoded = json_decode((string) $this->input('reviews_json'), true);
            $this->merge(['reviews' => $decoded]);
        }
    }

    public function rules(): array
    {
        if ($this->routeIs('steel-girder-projects.create')) {
            return ['id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:100']];
        }
        $rules = ['expected_revision' => ['required', 'integer', 'min:1']];
        if ($this->routeIs('steel-girder-projects.drawing.upload')) {
            $rules['file'] = ['required', 'file', 'min:0.001', 'max:204800', 'extensions:pdf,dwg'];
        }
        if ($this->routeIs('steel-girder-projects.material')) {
            $rules += [
                'file' => ['sometimes', 'file', 'min:0.001', 'max:10240', 'extensions:xlsx'],
                'material_id' => ['nullable', 'uuid'],
                'reviews' => ['present', 'array', 'max:100'],
                'reviews.*' => ['array:id,checked,note,signature'],
                'reviews.*.id' => ['required', 'string', 'in:design,net,studs,bolts,inside,outside,deck', 'distinct'],
                'reviews.*.checked' => ['required', 'boolean'],
                'reviews.*.note' => ['present', 'nullable', 'string', 'max:2000'],
                'reviews.*.signature' => ['required', 'string', 'max:100000'],
                'unit' => ['required', 'in:t,kg'],
            ];
        }

        return $rules;
    }
}
