<?php

namespace App\Mcp\Tools;

use App\Actions\BuildAiWriteProposal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Validate and prepare a create proposal for a supported business object. This tool never commits the record or approves its own proposal.')]
class PrepareObjectRecordCreateTool extends Tool
{
    public function __construct(private BuildAiWriteProposal $proposals) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        if (! $request->user()) {
            return Response::error('Authentication required.');
        }

        try {
            $result = $this->proposals->handle(
                $request->user(),
                (string) $request->get('object'),
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
            'message' => 'Proposal prepared. The authenticated user must confirm it in the management assistant.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object' => $schema->string()
                ->enum(BuildAiWriteProposal::WRITABLE_OBJECTS)
                ->required(),
            'payload' => $schema->object(fn (JsonSchema $schema) => [
                'name' => $schema->string()->nullable(),
                'phone' => $schema->string()->nullable(),
                'customer_id' => $schema->string()->nullable(),
                'address' => $schema->string()->nullable(),
                'level' => $schema->string()->nullable(),
                'cooperation_history' => $schema->string()->nullable(),
                'spec' => $schema->string()->nullable(),
                'length_mm' => $schema->number()->nullable(),
                'width_mm' => $schema->number()->nullable(),
                'status' => $schema->string()->nullable(),
                'unit_weight_type' => $schema->string()->nullable(),
                'unit_weight' => $schema->number()->nullable(),
                'remark' => $schema->string()->nullable(),
                'requester' => $schema->string()->nullable(),
                'material_id' => $schema->string()->nullable(),
                'qty' => $schema->number()->nullable(),
                'unit' => $schema->string()->nullable(),
                'project_id' => $schema->string()->nullable(),
                'urgency' => $schema->string()->nullable(),
                'reason' => $schema->string()->nullable(),
                'team_id' => $schema->string()->nullable(),
                'process' => $schema->string()->nullable(),
                'completed_qty' => $schema->number()->nullable(),
                'exception_type' => $schema->string()->nullable(),
                'work_date' => $schema->string()->nullable(),
                'part_name' => $schema->string()->nullable(),
                'shortage_material_id' => $schema->string()->nullable(),
                'shortage_qty' => $schema->number()->nullable(),
                'shortage_unit' => $schema->string()->nullable(),
            ])->required(),
        ];
    }
}
