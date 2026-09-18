<?php

namespace App\Http\Requests;

use App\Support\BusinessWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreAiContractIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $workspace = app(BusinessWorkspace::class);

        return $user && $user->canDo('object.project.update') && $user->canDo('object.contract.view')
            && ($workspace->isAdmin($user) || $workspace->isBusiness($user));
    }

    public function rules(): array
    {
        return ['file' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(20 * 1024)]];
    }
}
