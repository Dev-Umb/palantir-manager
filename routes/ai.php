<?php

use App\Http\Middleware\AuthenticateProcurementClient;
use App\Mcp\Servers\ProcurementPublishingServer;
use App\Mcp\Servers\XycManagementServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/procurement', ProcurementPublishingServer::class)
    ->middleware([AuthenticateProcurementClient::class, 'throttle:60,1']);

Mcp::web('/mcp/xyc-management', XycManagementServer::class)
    ->middleware('auth');
