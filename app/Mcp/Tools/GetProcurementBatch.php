<?php

namespace App\Mcp\Tools;

use App\Actions\PublishProcurementBatch as Publisher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetProcurementBatch extends Tool
{
    protected string $name = 'get_procurement_batch';

    protected string $description = 'JSON-only procurement get_procurement_batch; uses scoped collector identity. No ERP writes or source fetching.';

    public function schema(JsonSchema $schema): array
    {
        return ['batch_id' => $schema->string()->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $client = request()->attributes->get('procurement_client');
        if (! $client) {
            return Response::error('需要专用采集端凭证。');
        }
        $request->validate(['batch_id' => ['required', 'uuid']]);
        $action = app(Publisher::class);

        return Response::structured($action->result($client, $request->get('batch_id')));
    }
}
