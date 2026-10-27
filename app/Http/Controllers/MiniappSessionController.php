<?php

namespace App\Http\Controllers;

use App\Http\Middleware\HandleInertiaRequests;
use App\Support\WechatLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class MiniappSessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'wechatConfigured' => app(WechatLogin::class)->configured(),
            'csrfToken' => $request->session()->token(),
            'version' => app(HandleInertiaRequests::class)->version($request),
            'dashboardPath' => route('dashboard', absolute: false),
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles()->get(['roles.id', 'name', 'label']),
                'permissions' => $user->permissionKeys(),
            ] : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => '账号或密码不正确。']);
        }

        $request->session()->regenerate();

        return $this->show($request);
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->show($request);
    }
}
