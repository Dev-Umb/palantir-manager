<?php

namespace App\Http\Middleware;

use App\Support\QuotationAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureQuotationAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(QuotationAccess::allows($request->user()), 403);

        return $next($request);
    }
}
