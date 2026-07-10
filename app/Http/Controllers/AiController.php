<?php

namespace App\Http\Controllers;

use App\Ai\XycDataAgent;
use App\Models\AiRun;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Ai;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class AiController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(config('ai.harness_v2'), 404);

        return Inertia::render('Ai/Index', [
            'conversations' => $this->conversations($request),
            'messages' => [],
        ]);
    }

    public function messages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'string'],
        ]);

        $provider = config('ai.default', 'ark');
        if (! Ai::hasFakeGatewayFor(XycDataAgent::class) && blank(config("ai.providers.{$provider}.key"))) {
            return response()->json(['message' => 'AI 服务尚未配置 ARK_API_KEY。'], 422);
        }

        if (! empty($data['conversation_id'])) {
            abort_unless($this->conversationBelongsToUser($data['conversation_id'], $request), 403);
        }

        $agent = XycDataAgent::make(user: $request->user());
        empty($data['conversation_id'])
            ? $agent->forUser($request->user())
            : $agent->continue($data['conversation_id'], $request->user());

        $timeout = (int) config('ai.request_timeout', 90);
        if (! Ai::hasFakeGatewayFor(XycDataAgent::class)) {
            set_time_limit(max($timeout + 30, 60));
        }

        try {
            $response = $agent->prompt($data['message'], provider: $provider, timeout: $timeout);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'AI 服务调用失败，请稍后重试。',
            ], 502);
        }

        $result = $this->normalizeResponse($response);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'ai.message',
            'subject_type' => 'agent_conversation',
            'subject_id' => $response->conversationId,
            'payload' => [
                'message' => $data['message'],
                'sources' => $result['sources'],
                'tool_calls' => $response->toolCalls->pluck('name')->values()->all(),
            ],
        ]);

        return response()->json([
            ...$result,
            'conversation_id' => $response->conversationId,
            'conversations' => $this->conversations($request),
        ]);
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        abort_unless(config('ai.harness_v2'), 404);
        abort_unless($this->conversationBelongsToUser($conversation, $request), 403);

        return response()->json([
            'messages' => ConversationMessage::where('conversation_id', $conversation)
                ->orderBy('created_at')
                ->get()
                ->map(fn (ConversationMessage $message) => [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $message->content,
                    'created_at' => $message->created_at?->toISOString(),
                ])
                ->all(),
            'runs' => AiRun::query()
                ->where('conversation_id', $conversation)
                ->where('user_id', $request->user()->id)
                ->with('events')
                ->oldest()
                ->limit(50)
                ->get()
                ->map(fn (AiRun $run) => [
                    ...$run->snapshot(),
                    'events' => $run->events->map->envelope()->values()->all(),
                ])
                ->all(),
        ]);
    }

    private function conversations(Request $request): array
    {
        return Conversation::where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (Conversation $conversation) => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'updated_at' => $conversation->updated_at?->toISOString(),
            ])
            ->all();
    }

    private function conversationBelongsToUser(string $id, Request $request): bool
    {
        return Conversation::whereKey($id)->where('user_id', $request->user()->id)->exists();
    }

    private function normalizeResponse($response): array
    {
        $structured = $response instanceof StructuredAgentResponse ? $response->toArray() : [];
        $result = filled($structured['answer'] ?? null)
            ? $structured
            : (json_decode($response->text, true) ?: ['answer' => $response->text]);

        $result = $this->backfillFromTools($result, $response);

        return [
            'answer' => (string) ($result['answer'] ?? ''),
            'table' => $result['table'] ?? null,
            'chart' => $result['chart'] ?? null,
            'sources' => is_array($result['sources'] ?? null) ? $result['sources'] : [],
        ];
    }

    private function backfillFromTools(array $result, $response): array
    {
        $toolResults = $response->toolResults ?? collect();

        foreach ($toolResults->reverse() as $toolResult) {
            $payload = is_string($toolResult->result)
                ? json_decode($toolResult->result, true)
                : $toolResult->result;

            if (! is_array($payload) || ! ($payload['ok'] ?? false)) {
                continue;
            }

            if ($toolResult->name === 'query_object_records') {
                return $this->backfillFromRecordQuery($result, $payload, $toolResult->arguments);
            }

            if ($toolResult->name === 'list_visible_objects') {
                return $this->backfillFromObjectList($result, $payload);
            }
        }

        return $result;
    }

    private function backfillFromRecordQuery(array $result, array $payload, array $arguments): array
    {
        $rows = collect($payload['rows'] ?? [])->filter(fn ($row) => is_array($row))->values()->all();
        if ($rows === []) {
            $result['sources'] ??= $payload['sources'] ?? [];

            return $result;
        }

        $fields = collect($payload['fields'] ?? [])->keyBy('key');
        $columns = collect(array_keys($rows[0]))
            ->reject(fn (string $key) => $key === 'group_by')
            ->map(fn (string $key) => [
                'key' => $key,
                'label' => $key === 'group'
                    ? ($fields[$payload['group_by'] ?? '']['label'] ?? '分组')
                    : ($fields[$key]['label'] ?? $key),
            ])
            ->values()
            ->all();

        $result['table'] ??= ['columns' => $columns, 'rows' => $rows];
        $result['sources'] ??= $payload['sources'] ?? [];
        $result['chart'] ??= $this->chartFromRows($rows, $arguments, $payload['object']['label'] ?? '数据');

        return $result;
    }

    private function backfillFromObjectList(array $result, array $payload): array
    {
        $rows = collect($payload['objects'] ?? [])
            ->filter(fn ($object) => is_array($object))
            ->map(fn (array $object) => [
                'group' => $object['group'] ?? '未分组',
                'label' => $object['label'] ?? $object['key'] ?? '',
                'key' => $object['key'] ?? '',
                'field_count' => count($object['fields'] ?? []),
            ])
            ->values()
            ->all();

        if ($rows === []) {
            return $result;
        }

        $result['table'] ??= [
            'columns' => [
                ['key' => 'group', 'label' => '分组'],
                ['key' => 'label', 'label' => '对象'],
                ['key' => 'key', 'label' => '标识'],
                ['key' => 'field_count', 'label' => '字段数'],
            ],
            'rows' => $rows,
        ];
        $result['sources'] ??= [[
            'object_key' => 'business_objects',
            'object_label' => '业务对象',
            'record_count' => count($rows),
        ]];

        return $result;
    }

    private function chartFromRows(array $rows, array $arguments, string $label): ?array
    {
        $first = $rows[0] ?? [];
        $keys = array_keys($first);
        $sortField = $arguments['sort']['field'] ?? null;
        $y = is_string($sortField) && $this->isNumericColumn($rows, $sortField)
            ? $sortField
            : collect($keys)->first(fn (string $key) => $this->isNumericColumn($rows, $key));
        $x = in_array('group', $keys, true)
            ? 'group'
            : collect(['title', 'name', 'code', 'id'])->first(fn (string $key) => in_array($key, $keys, true));

        if (! $x || ! $y || $x === $y) {
            return null;
        }

        return [
            'type' => 'bar',
            'title' => "{$label}汇总",
            'x' => $x,
            'y' => $y,
            'rows' => $rows,
        ];
    }

    private function isNumericColumn(array $rows, string $key): bool
    {
        return collect($rows)->contains(fn (array $row) => is_numeric($row[$key] ?? null));
    }
}
