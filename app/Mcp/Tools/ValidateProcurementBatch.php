<?php

namespace App\Mcp\Tools;

use App\Actions\PublishProcurementBatch as Publisher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ValidateProcurementBatch extends Tool
{
    protected string $name = 'validate_procurement_batch';

    protected string $description = 'JSON-only procurement validate_procurement_batch; uses scoped collector identity. No ERP writes or source fetching.';

    public function schema(JsonSchema $schema): array
    {
        return ['payload' => $schema->object()->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $client = request()->attributes->get('procurement_client');
        if (! $client) {
            return Response::error('需要专用采集端凭证。');
        }
        $request->validate(['payload' => ['required', 'array']]);
        $action = app(Publisher::class);

        return Response::structured($action->validate($client, $request->get('payload')));
    }
}
