<?php

namespace App\Http\Requests;

use App\Support\HubAccess;
use Illuminate\Foundation\Http\FormRequest;

class HubImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return HubAccess::canManage($this->user());
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel', 'max:1024']];
    }
}
