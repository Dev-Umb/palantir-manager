<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountEmailRequest;
use App\Http\Requests\UpdateAccountPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AccountSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'updateEmailUrl' => route('settings.email'),
            'updatePasswordUrl' => route('settings.password'),
        ]);
    }

    public function updateEmail(UpdateAccountEmailRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->email = $request->validated('email');
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        $user->save();
        $request->session()->regenerate();

        return to_route('settings.index')->with('status', '登录邮箱已更新，下次登录请使用新邮箱。');
    }

    public function updatePassword(UpdateAccountPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->password = $request->validated('password');
        $user->is_password_changed = true;
        $user->setRememberToken(Str::random(60));
        $user->save();
        $request->session()->regenerate();

        return to_route('settings.index')->with('status', '密码已修改。');
    }
}
