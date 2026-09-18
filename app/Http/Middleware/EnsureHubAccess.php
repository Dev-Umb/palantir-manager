<?php

namespace App\Http\Middleware;

use App\Support\HubAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHubAccess
{
    public function handle(Request $request, Closure $next, string $mode = 'read'): Response
    {
        abort_unless($mode === 'manage' ? HubAccess::canManage($request->user()) : HubAccess::canRead($request->user()), 403);

        return $next($request);
    }
}
