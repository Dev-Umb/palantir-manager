<?php

namespace App\Ai\Tools;

use App\Actions\BuildAiWriteProposal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PrepareObjectRecordCreateTool implements Tool
{
    public function __construct(
        private User $user,
        private BuildAiWriteProposal $proposals,
    ) {}

    public function name(): string
    {
        return 'prepare_object_record_create';
    }

    public function description(): Stringable|string
    {
        return 'Validate and prepare a user-confirmed create proposal for requisition, team_log, customer, customer_contact, or material. This tool never writes the business record. Use exact record UUIDs for relation fields.';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $result = $this->proposals->handle(
                $this->user,
                (string) $request->string('object'),
                (array) ($request['payload'] ?? []),
            );

            return json_encode([
                'ok' => true,
                'artifact' => $result['artifact'],
                'message' => '写入草稿已准备，等待用户在卡片中确认。',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (AuthorizationException $exception) {
            return $this->error('forbidden', $exception->getMessage());
        } catch (ValidationException $exception) {
            return $this->error('validation_failed', collect($exception->errors())->flatten()->implode('；'), $exception->errors());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object' => $schema->string()
                ->enum(BuildAiWriteProposal::WRITABLE_OBJECTS)
                ->required()
                ->description('The exact business object key.'),
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
            ])->required()->description('Proposed field values. Omit unknown optional values; never invent relation UUIDs.'),
        ];
    }

    private function error(string $code, string $message, array $errors = []): string
    {
        return json_encode([
            'ok' => false,
            'error' => $code,
            'message' => $message,
            'errors' => $errors,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
