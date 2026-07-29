<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PresentUserChoiceTool implements Tool
{
    public function name(): string
    {
        return 'present_user_choice';
    }

    public function description(): Stringable|string
    {
        return 'Present 2 to 4 concise click-to-answer choices when required information is missing or several records match. Do not use this tool for final write confirmation.';
    }

    public function handle(Request $request): Stringable|string
    {
        $options = collect($request['options'] ?? [])
            ->filter(fn (mixed $option) => is_array($option) && filled($option['label'] ?? null))
            ->take(4)
            ->values()
            ->all();

        return json_encode([
            'ok' => count($options) >= 2,
            'artifact' => [
                'id' => (string) Str::uuid7(),
                'type' => 'choice',
                'title' => (string) $request->string('title'),
                'revision' => 1,
                'data' => [
                    'question' => (string) $request->string('question'),
                    'options' => $options,
                ],
            ],
            'message' => count($options) >= 2 ? '选择卡片已展示，等待用户点击。' : '至少需要两个选项。',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('Short card title.'),
            'question' => $schema->string()->required()->description('One concise question in Chinese.'),
            'options' => $schema->array()->min(2)->max(4)->items($schema->object(fn (JsonSchema $schema) => [
                'label' => $schema->string()->required()->description('Short button label.'),
                'value' => $schema->string()->required()->description('Exact answer sent back to the assistant.'),
                'description' => $schema->string()->nullable()->description('Optional one-line explanation.'),
            ]))->required(),
        ];
    }
}
