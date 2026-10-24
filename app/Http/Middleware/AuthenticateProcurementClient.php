<?php

namespace App\Http\Middleware;

use App\Actions\PublishProcurementBatch;
use App\Models\ProcurementClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateProcurementClient
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(strlen($request->getContent()) > 2200000, 413);
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 32, 401);
        $client = ProcurementClient::where('token_hash', hash('sha256', $token))->first();
        abort_unless($client, 401);
        app(PublishProcurementBatch::class)->authorize($client);
        $request->attributes->set('procurement_client', $client);

        return $next($request);
    }
}
