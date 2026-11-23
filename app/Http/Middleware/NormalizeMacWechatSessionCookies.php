<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeMacWechatSessionCookies
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('miniapp/session', 'miniapp/wechat/login') && str_contains((string) $request->userAgent(), 'MiniProgramEnv/Mac')) {
            // The native app reads its CSRF token from JSON and needs the session cookie across requests.
            $response->headers->removeCookie('XSRF-TOKEN', config('session.path'), config('session.domain'));
        }

        return $response;
    }
}
