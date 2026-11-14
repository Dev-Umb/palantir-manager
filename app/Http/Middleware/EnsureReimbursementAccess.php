<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureReimbursementAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()->isTimebookOperator(), 403);

        abort_if($request->user()->isCashier() && ! $request->isMethodSafe() && ! $request->routeIs('reimbursements.notices.read'), 403);

        return $next($request);
    }
}
