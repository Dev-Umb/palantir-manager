<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        abort_unless($request->user()?->canDo($permission), 403);

        return $next($request);
    }
}
