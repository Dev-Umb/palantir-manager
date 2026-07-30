<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PresentUserFormTool implements Tool
{
    public function name(): string
    {
        return 'present_user_form';
    }

    public function description(): Stringable|string
    {
        return 'Present one compact form card for the user to fill missing create-proposal data. Supports text, textarea, number, date, and select fields. The submitted values return to the conversation and never write business data directly.';
    }

    public function handle(Request $request): Stringable|string
    {
        $fields = collect($request['fields'] ?? [])
            ->filter(fn (mixed $field) => is_array($field)
                && filled($field['key'] ?? null)
                && filled($field['label'] ?? null)
                && in_array($field['type'] ?? null, ['text', 'textarea', 'number', 'date', 'select'], true))
            ->unique('key')
            ->take(6)
            ->map(function (array $field): array {
                $options = collect($field['options'] ?? [])
                    ->filter(fn (mixed $option) => is_array($option)
                        && filled($option['label'] ?? null)
                        && array_key_exists('value', $option))
                    ->take(20)
                    ->values()
                    ->all();

                return [
                    'key' => (string) $field['key'],
                    'label' => (string) $field['label'],
                    'type' => (string) $field['type'],
                    'required' => (bool) ($field['required'] ?? true),
                    'placeholder' => (string) ($field['placeholder'] ?? ''),
                    'options' => $options,
                ];
            })
            ->filter(fn (array $field) => $field['type'] !== 'select' || $field['options'] !== [])
            ->values()
            ->all();
        $submitLabel = (string) $request->string('submit_label');

        return json_encode([
            'ok' => $fields !== [],
            'artifact' => [
                'id' => (string) Str::uuid7(),
                'type' => 'form',
                'title' => (string) $request->string('title'),
                'revision' => 1,
                'data' => [
                    'question' => (string) $request->string('question'),
                    'submit_label' => $submitLabel !== '' ? $submitLabel : '提交并继续',
                    'fields' => $fields,
                ],
            ],
            'message' => $fields !== [] ? '补充资料卡片已展示，等待用户填写。' : '至少需要一个有效字段。',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('Short card title in Chinese.'),
            'question' => $schema->string()->required()->description('One concise explanation of why these values are needed.'),
            'submit_label' => $schema->string()->nullable()->description('Short submit button label.'),
            'fields' => $schema->array()->min(1)->max(6)->items($schema->object(fn (JsonSchema $schema) => [
                'key' => $schema->string()->required()->description('Exact create payload field key.'),
                'label' => $schema->string()->required()->description('User-facing Chinese field label.'),
                'type' => $schema->string()->enum(['text', 'textarea', 'number', 'date', 'select'])->required(),
                'required' => $schema->boolean()->default(true),
                'placeholder' => $schema->string()->nullable(),
                'options' => $schema->array()->max(20)->items($schema->object(fn (JsonSchema $schema) => [
                    'label' => $schema->string()->required(),
                    'value' => $schema->string()->required(),
                ]))->nullable()->description('Required for select. Use exact UUID as value for relation choices.'),
            ]))->required(),
        ];
    }
}
