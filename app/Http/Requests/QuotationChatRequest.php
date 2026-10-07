<?php

namespace App\Http\Requests;

use App\Support\QuotationAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class QuotationChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return QuotationAccess::allows($this->user());
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $files = array_filter($this->file('attachments', []), fn ($file) => $file instanceof UploadedFile);
            if (array_sum(array_map(fn ($file) => $file->getSize(), $files)) > 16 * 1024 * 1024) {
                $validator->errors()->add('attachments', '附件总大小不得超过16MB。');
            }
        }];
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:4000'],
            'context' => ['nullable', 'array', 'max:24'],
            'context.*' => ['array:role,content'],
            'context.*.role' => ['required', 'in:user,assistant'],
            'context.*.content' => ['required', 'string', 'max:4000'],
            'params' => ['nullable', 'array', 'max:22'],
            'params.*' => ['nullable', 'string', 'max:1500'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:8192'],
        ];
    }
}
