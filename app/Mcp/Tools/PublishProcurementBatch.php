<?php

namespace App\Mcp\Tools;

use App\Actions\PublishProcurementBatch as Publisher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class PublishProcurementBatch extends Tool
{
    protected string $name = 'publish_procurement_batch';

    protected string $description = 'JSON-only procurement publish_procurement_batch; uses scoped collector identity. No ERP writes or source fetching.';

    public function schema(JsonSchema $schema): array
    {
        return ['validation_id' => $schema->string()->required(), 'idempotency_key' => $schema->string()->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $client = request()->attributes->get('procurement_client');
        if (! $client) {
            return Response::error('需要专用采集端凭证。');
        }
        $request->validate(['validation_id' => ['required', 'uuid'], 'idempotency_key' => ['required', 'string', 'min:16', 'max:120']]);
        $action = app(Publisher::class);

        return Response::structured($action->publish($client, $request->get('validation_id'), $request->get('idempotency_key')));
    }
}
