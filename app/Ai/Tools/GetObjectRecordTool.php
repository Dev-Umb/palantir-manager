<?php

namespace App\Ai\Tools;

use App\Ai\XycDataAccess;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetObjectRecordTool implements Tool
{
    public function __construct(private User $user) {}

    public function name(): string
    {
        return 'get_object_record';
    }

    public function description(): Stringable|string
    {
        return 'Read one visible record by business object key and record id.';
    }

    public function handle(Request $request): Stringable|string
    {
        return json_encode(
            app(XycDataAccess::class)->getRecord(
                $this->user,
                (string) $request->string('object'),
                (string) $request->string('id'),
            ),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object' => $schema->string()->required()->description('Business object key.'),
            'id' => $schema->string()->required()->description('Record UUID.'),
        ];
    }
}
