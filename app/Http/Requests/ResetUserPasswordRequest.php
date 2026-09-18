<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('rbac.manage');
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => '请输入您自己的当前密码。',
            'current_password.current_password' => '您输入的管理员密码不正确。',
            'password.required' => '请输入临时密码。',
            'password.confirmed' => '两次输入的临时密码不一致。',
            'password.min' => '临时密码至少需要 8 个字符。',
        ];
    }
}
