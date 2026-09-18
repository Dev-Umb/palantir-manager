<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountEmailRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class)->ignore($this->user())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => '请输入当前密码。',
            'current_password.current_password' => '当前密码不正确。',
            'email.required' => '请输入登录邮箱。',
            'email.email' => '请输入有效的邮箱地址。',
            'email.max' => '邮箱不能超过 255 个字符。',
            'email.unique' => '该邮箱已被其他账号使用。',
        ];
    }
}
