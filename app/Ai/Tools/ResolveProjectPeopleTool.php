<?php

namespace App\Ai\Tools;

use App\Models\BusinessObject;
use App\Models\User;
use App\Support\ProjectVisibility;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ResolveProjectPeopleTool implements Tool
{
    public function __construct(private User $user) {}

    public function name(): string
    {
        return 'resolve_project_people';
    }

    public function description(): Stringable|string
    {
        return 'Resolve a salesperson name to account IDs referenced by projects visible to the current user. Multiple matches require clarification. This is not a customer search.';
    }

    public function handle(Request $request): Stringable|string
    {
        $user = $this->user->fresh();
        $project = BusinessObject::where('key', 'project')->first();
        if (! $user || ! $project || ! $user->canDo('object.project.view')) {
            return json_encode(['ok' => false, 'error' => 'forbidden', 'people' => []]);
        }

        $name = trim((string) $request->string('name'));
        $ids = app(ProjectVisibility::class)->scope($project->records(), $user)
            ->pluck('payload')->map(fn ($payload) => is_array($payload) ? $payload : json_decode($payload, true))
            ->pluck('business_owner_user_id')->filter()->unique()->values();
        $people = User::withTrashed()->whereIn('id', $ids)->get(['id', 'name'])
            ->filter(fn (User $person): bool => $name !== '' && str_contains(mb_strtolower($person->name), mb_strtolower($name)))
            ->values();
        $exact = $people->filter(fn (User $person): bool => $person->name === $name);
        if ($exact->isNotEmpty()) {
            $people = $exact->values();
        }

        return json_encode([
            'ok' => true,
            'people' => $people->map(fn (User $person): array => ['account_id' => (string) $person->id, 'name' => $person->name])->all(),
            'requires_clarification' => $people->count() > 1,
            'scope' => '仅包含当前可见项目的负责业务员',
        ], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['name' => $schema->string()->required()->description('Salesperson name supplied by the user, never a guessed ID.')];
    }
}
