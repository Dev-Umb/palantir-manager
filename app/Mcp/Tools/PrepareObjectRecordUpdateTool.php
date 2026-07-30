<?php

namespace App\Mcp\Tools;

use App\Actions\BuildAiUpdateProposal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Validate and prepare an update proposal for an existing customer, customer contact, or material record. This tool never commits the update or approves its own proposal.')]
class PrepareObjectRecordUpdateTool extends Tool
{
    public function __construct(private BuildAiUpdateProposal $proposals) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        if (! $request->user()) {
            return Response::error('Authentication required.');
        }

        try {
            $result = $this->proposals->handle(
                $request->user(),
                (string) $request->get('object'),
                (string) $request->get('record_id'),
                (array) $request->get('payload', []),
            );
        } catch (AuthorizationException $exception) {
            return Response::error($exception->getMessage());
        } catch (ValidationException $exception) {
            return Response::error(collect($exception->errors())->flatten()->implode('；'));
        }

        return Response::structured([
            'ok' => true,
            'proposal' => $result['artifact'],
            'message' => 'Update proposal prepared. The authenticated user must confirm it in the management assistant.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object' => $schema->string()
                ->enum(BuildAiUpdateProposal::UPDATABLE_OBJECTS)
                ->required(),
            'record_id' => $schema->string()
                ->description('Exact record UUID from an authorized query.')
                ->required(),
            'payload' => $schema->object(fn (JsonSchema $schema) => [
                'name' => $schema->string()->nullable(),
                'phone' => $schema->string()->nullable(),
                'address' => $schema->string()->nullable(),
                'level' => $schema->string()->nullable(),
                'cooperation_history' => $schema->string()->nullable(),
                'spec' => $schema->string()->nullable(),
                'length_mm' => $schema->number()->nullable(),
                'width_mm' => $schema->number()->nullable(),
                'unit_weight_type' => $schema->string()->nullable(),
                'unit_weight' => $schema->number()->nullable(),
                'remark' => $schema->string()->nullable(),
            ])->required(),
        ];
    }
}
