<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureWechatBinding
{
    public function handle(Request $request, Closure $next): Response
    {
        $binding = $request->session()->get('wechat_binding');
        if ($binding && (! $request->user() || ! DB::table('wechat_account_bindings')->where('id', $binding['id'])->where('version', $binding['version'])->where('user_id', $request->user()->id)->where('appid', config('wechat.appid'))->whereNotNull('openid')->exists())) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => '微信绑定已失效，请重新登录。'], 401);
        }

        return $next($request);
    }
}
