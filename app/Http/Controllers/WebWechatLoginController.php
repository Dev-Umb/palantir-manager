<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\WechatLogin;
use App\Support\WechatWebLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WebWechatLoginController extends Controller
{
    public function create(Request $request, WechatLogin $wechat, WechatWebLogin $login): JsonResponse
    {
        abort_unless($wechat->configured(), 503, '微信登录暂不可用。');
        $id = bin2hex(random_bytes(16));
        $image = $login->image($id);
        if ($old = $request->session()->get('web_wechat_challenge')) {
            Cache::forget($login->key($old));
        }
        $request->session()->put('web_wechat_challenge', $id);
        Cache::put($login->key($id), ['status' => 'pending', 'expires' => now()->addMinutes(3)->timestamp], 180);

        return response()->json(['id' => $id, 'image' => $image, 'expires_in' => 180])->header('Cache-Control', 'no-store');
    }

    public function status(Request $request, string $id, WechatWebLogin $login): JsonResponse
    {
        abort_unless($request->session()->get('web_wechat_challenge') === $id, 404);

        return response()->json(['status' => $login->read($id)['status']])->header('Cache-Control', 'no-store');
    }

    public function inspect(Request $request, string $id, WechatWebLogin $login): JsonResponse
    {
        return response()->json(['status' => $login->read($id)['status'], 'account' => $request->user()->name, 'site' => '鑫源昌智造中枢'])->header('Cache-Control', 'no-store');
    }

    public function decide(Request $request, string $id, WechatWebLogin $login): JsonResponse
    {
        $data = $request->validate(['confirm' => ['required', 'boolean']]);
        $binding = $request->session()->get('wechat_binding');
        abort_unless($binding && DB::table('wechat_account_bindings')->where('id', $binding['id'])->where('version', $binding['version'])->where('appid', config('wechat.appid'))->where('user_id', $request->user()->id)->whereNotNull('openid')->exists(), 403, '请先使用已绑定微信登录。');

        return Cache::lock($login->key($id).':lock', 10)->block(3, function () use ($request, $id, $login, $data, $binding): JsonResponse {
            $state = $login->read($id);
            abort_unless($state['status'] === 'pending', 409, '此登录请求已处理，请返回电脑端。');
            $state['status'] = $data['confirm'] ? 'confirmed' : 'cancelled';
            $state['binding'] = $binding;
            $state['user_id'] = $request->user()->id;
            Cache::put($login->key($id), $state, max(1, $state['expires'] - now()->timestamp));

            return response()->json(['status' => $state['status']])->header('Cache-Control', 'no-store');
        });
    }

    public function consume(Request $request, string $id, WechatWebLogin $login, AuthController $auth): JsonResponse
    {
        abort_unless($request->session()->get('web_wechat_challenge') === $id, 404);

        return Cache::lock($login->key($id).':lock', 10)->block(3, function () use ($request, $id, $login, $auth): JsonResponse {
            $state = $login->read($id);
            abort_unless($state['status'] === 'confirmed', 409, '请先在微信确认登录。');
            $binding = $state['binding'];
            abort_unless(DB::table('wechat_account_bindings')->where('id', $binding['id'])->where('version', $binding['version'])->where('appid', config('wechat.appid'))->where('user_id', $state['user_id'])->whereNotNull('openid')->exists(), 403, '微信绑定已失效，请重新扫码。');
            $user = User::findOrFail($state['user_id']);
            Cache::forget($login->key($id));
            $request->session()->forget('web_wechat_challenge');
            Auth::login($user);
            $request->session()->regenerate();
            $request->session()->put('wechat_binding', $binding);

            return response()->json(['redirect' => $auth->loginFallback($user)])->header('Cache-Control', 'no-store');
        });
    }
}
