<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateAccountPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(8)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => '请输入当前密码。',
            'current_password.current_password' => '当前密码不正确。',
            'password.required' => '请输入新密码。',
            'password.confirmed' => '两次输入的新密码不一致。',
            'password.different' => '新密码不能与当前密码相同。',
            'password.min' => '新密码至少需要 8 个字符。',
        ];
    }
}
