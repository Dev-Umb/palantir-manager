<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\WechatLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WechatAccountController extends Controller
{
    public function login(Request $request, WechatLogin $wechat, MiniappSessionController $session): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:256'], 'binding_code' => ['nullable', 'string', 'regex:/^[A-Fa-f0-9]{16}$/']]);
        $row = $wechat->authenticate($wechat->identity($data['code']), $data['binding_code'] ?? null);
        Auth::login(User::query()->findOrFail($row->user_id));
        $request->session()->regenerate();
        $request->session()->put('wechat_binding', ['id' => $row->id, 'version' => $row->version]);

        return $session->show($request);
    }

    public function issue(Request $request, WechatLogin $wechat): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'string', 'current_password']]);

        return response()->json($wechat->issue($request->user()))->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, WechatLogin $wechat): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'string', 'current_password']]);
        $wechat->unbind($request->user(), $request->user());

        return back()->with('status', '微信已解绑，小程序需重新绑定后登录。');
    }

    public function adminDestroy(Request $request, User $user, WechatLogin $wechat): RedirectResponse
    {
        abort_unless($request->user()->canDo('rbac.manage'), 403);
        $wechat->unbind($user, $request->user());

        return back()->with('status', '微信已解绑。');
    }
}
