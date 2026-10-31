<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        $user = $request->user();
        $basicPanel = $permission === 'dashboard.view'
            && $request->routeIs('home', 'dashboard', 'visualization')
            && $user?->isWorkbenchTechnician()
            && ($user->canDo('material-arrivals.view') || $user->canDo('template-accessories.view'));
        abort_unless($basicPanel || $user?->canDo($permission), 403);

        return $next($request);
    }
}
