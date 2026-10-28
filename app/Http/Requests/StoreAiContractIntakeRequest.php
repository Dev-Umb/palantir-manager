<?php

namespace App\Http\Requests;

use App\Support\BusinessWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreAiContractIntakeRequest extends FormRequest
{
    public const MAX_FILE_KILOBYTES = 100 * 1024;

    public function authorize(): bool
    {
        $user = $this->user();
        $workspace = app(BusinessWorkspace::class);

        return $user && $user->canDo('object.project.update') && $user->canDo('object.contract.view')
            && ($workspace->isAdmin($user) || $workspace->isBusiness($user));
    }

    public function rules(): array
    {
        return ['file' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(self::MAX_FILE_KILOBYTES)]];
    }

    public function messages(): array
    {
        return ['file.max' => '合同文件不能超过 100 MB。', 'file.uploaded' => '合同文件上传失败，请检查文件大小（最大 100 MB）后重试。'];
    }
}
