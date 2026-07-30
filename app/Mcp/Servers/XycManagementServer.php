<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\PrepareObjectRecordCreateTool;
use App\Mcp\Tools\PrepareObjectRecordUpdateTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Xyc Management Server')]
#[Version('1.2.0')]
#[Instructions('Prepare validated create and update proposals for supported Xinyuanchang business objects. Tools never approve or directly commit records; the authenticated user must confirm in the management assistant.')]
class XycManagementServer extends Server
{
    protected array $tools = [
        PrepareObjectRecordCreateTool::class,
        PrepareObjectRecordUpdateTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
