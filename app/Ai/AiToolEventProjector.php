<?php

namespace App\Ai;

use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\BusinessObject;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

class AiToolEventProjector
{
    private array $startedAt = [];

    public function __construct(
        private AiRunEventPublisher $events,
        private AiArtifactFactory $artifacts,
    ) {}

    public function started(AiRun $run, ToolCall $call): void
    {
        $this->startedAt[$call->id] = microtime(true);
        $this->events->publish($run, 'tool.started', [
            'tool' => $call->name,
            'label' => $this->startedLabel($call),
            'status' => 'running',
        ]);
    }

    public function completed(AiRun $run, ToolResult $result): void
    {
        $payload = is_string($result->result) ? json_decode($result->result, true) : $result->result;
        $payload = is_array($payload) ? $payload : [];
        $projection = $this->artifacts->fromToolResult($result->name, $payload, $result->arguments);

        $run->update([
            'artifacts' => [...($run->artifacts ?? []), ...$projection['artifacts']],
            'sources' => $this->mergeSources($run->sources ?? [], $projection['sources']),
            'data_quality' => [...($run->data_quality ?? []), ...$projection['data_quality']],
        ]);

        $eventPayload = [
            'tool' => $result->name,
            'label' => $this->completedLabel($result->name, $payload),
            'status' => ($payload['ok'] ?? false) ? 'completed' : 'failed',
            'duration_ms' => isset($this->startedAt[$result->id])
                ? (int) round((microtime(true) - $this->startedAt[$result->id]) * 1000)
                : null,
        ];
        unset($this->startedAt[$result->id]);
        if ($result->name !== 'publish_html_artifact') {
            $eventPayload['record_count'] = (int) ($payload['record_count'] ?? count($payload['rows'] ?? []));
        }
        $this->events->publish($run, 'tool.completed', $eventPayload);

        foreach ($projection['artifacts'] as $artifact) {
            $this->events->publish($run, 'artifact.upsert', ['artifact' => $artifact]);

            if (($artifact['type'] ?? null) === 'html') {
                $data = $artifact['data'] ?? [];
                AuditLog::create([
                    'user_id' => $run->user_id,
                    'action' => 'ai.artifact.html.published',
                    'subject_type' => 'ai_run',
                    'subject_id' => $run->id,
                    'payload' => [
                        'artifact_id' => $artifact['id'] ?? null,
                        'bytes' => $data['bytes'] ?? 0,
                        'original_hash' => $data['original_hash'] ?? null,
                        'sanitized_hash' => $data['sanitized_hash'] ?? null,
                        'changed' => $data['changed'] ?? false,
                        'removed_rules' => $data['removed_rules'] ?? [],
                    ],
                ]);
            }
        }
    }

    private function startedLabel(ToolCall $call): string
    {
        return match ($call->name) {
            'list_visible_objects' => '正在读取可用数据范围',
            'get_object_record' => '正在读取记录详情',
            'query_object_records' => '正在查询'.$this->objectLabel($call->arguments['object'] ?? null),
            'publish_html_artifact' => '正在生成静态 HTML 结果',
            default => '正在执行数据工具',
        };
    }

    private function completedLabel(string $tool, array $payload): string
    {
        if (! ($payload['ok'] ?? false)) {
            return (string) ($payload['message'] ?? '数据工具执行失败');
        }

        if ($tool === 'publish_html_artifact') {
            return '静态 HTML 结果已生成';
        }

        $label = $payload['object']['label'] ?? ($tool === 'list_visible_objects' ? '数据范围' : '记录');
        $count = (int) ($payload['record_count'] ?? count($payload['rows'] ?? $payload['objects'] ?? []));

        return "已读取{$label}，共 {$count} 条记录";
    }

    private function objectLabel(mixed $key): string
    {
        if (! is_string($key) || $key === '') {
            return '业务数据';
        }

        return BusinessObject::where('key', $key)->value('label') ?: $key;
    }

    private function mergeSources(array $existing, array $incoming): array
    {
        return collect([...$existing, ...$incoming])
            ->filter(fn ($source) => is_array($source) && filled($source['object_key'] ?? null))
            ->keyBy('object_key')
            ->values()
            ->all();
    }
}
