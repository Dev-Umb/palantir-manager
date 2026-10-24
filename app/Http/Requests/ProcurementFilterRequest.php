<?php

namespace App\Http\Requests;

use App\Support\HubAccess;
use App\Support\ProcurementQuery;
use Illuminate\Foundation\Http\FormRequest;

class ProcurementFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return HubAccess::canRead($this->user());
    }

    public function rules(): array
    {
        return ProcurementQuery::rules();
    }
}
