<?php

namespace App\Ai;

class AiQueryProvenance
{
    public const RECORD_ID_LIMIT = 5000;

    public function __construct(private AiRunRequestFingerprint $fingerprint) {}

    public function make(string $operation, string $objectKey, array $input, array $recordIds, array $result): array
    {
        $recordIds = array_values(array_map('strval', $recordIds));
        $truncated = count($recordIds) > self::RECORD_ID_LIMIT;
        $capturedIds = array_slice($recordIds, 0, self::RECORD_ID_LIMIT);
        $query = [
            'object' => $objectKey,
            'select' => array_values($input['select'] ?? []),
            'filters' => array_values($input['filters'] ?? []),
            'group_by' => $input['group_by'] ?? null,
            'metrics' => array_values($input['metrics'] ?? []),
            'sort' => $input['sort'] ?? null,
            'limit' => isset($input['limit']) ? (int) $input['limit'] : null,
        ];

        return [
            'schema_version' => 1,
            'operation' => $operation,
            'object_key' => $objectKey,
            'query' => $query,
            'query_hash' => $this->fingerprint->hash($query),
            'metric_version' => $this->fingerprint->hash(config('ai_metrics', [])),
            'record_ids' => $capturedIds,
            'record_ids_truncated' => $truncated,
            'record_ids_hash' => $this->fingerprint->hash($recordIds),
            'result_hash' => $this->fingerprint->hash($result),
        ];
    }
}
