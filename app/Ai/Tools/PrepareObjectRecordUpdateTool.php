<?php

namespace App\Ai\Tools;

use App\Actions\BuildAiUpdateProposal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PrepareObjectRecordUpdateTool implements Tool
{
    public function __construct(
        private User $user,
        private BuildAiUpdateProposal $proposals,
    ) {}

    public function name(): string
    {
        return 'prepare_object_record_update';
    }

    public function description(): Stringable|string
    {
        return 'Prepare a user-confirmed patch for an existing customer, customer_contact, or material record. The record UUID must come from a prior query. This tool never updates the record.';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $result = $this->proposals->handle(
                $this->user,
                (string) $request->string('object'),
                (string) $request->string('record_id'),
                (array) ($request['payload'] ?? []),
            );

            return json_encode([
                'ok' => true,
                'artifact' => $result['artifact'],
                'message' => '修改草稿已准备，等待用户核对前后差异并确认。',
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
                ->enum(BuildAiUpdateProposal::UPDATABLE_OBJECTS)
                ->required()
                ->description('The exact business object key.'),
            'record_id' => $schema->string()
                ->required()
                ->description('Exact record UUID returned by query_object_records or get_object_record.'),
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
            ])->required()->description('Patch only. Include changed ordinary fields and omit all other fields.'),
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
