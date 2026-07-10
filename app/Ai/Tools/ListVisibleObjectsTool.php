<?php

namespace App\Ai\Tools;

use App\Ai\XycDataAccess;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListVisibleObjectsTool implements Tool
{
    public function __construct(private User $user) {}

    public function name(): string
    {
        return 'list_visible_objects';
    }

    public function description(): Stringable|string
    {
        return 'List the business objects and fields visible to the current user.';
    }

    public function handle(Request $request): Stringable|string
    {
        return json_encode([
            'ok' => true,
            'objects' => app(XycDataAccess::class)->visibleObjects($this->user),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
