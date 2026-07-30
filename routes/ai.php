<?php

use App\Mcp\Servers\XycManagementServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/xyc-management', XycManagementServer::class)
    ->middleware('auth');
