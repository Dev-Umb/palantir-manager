<?php

namespace App\Actions;

use App\Models\SupportLibrary;
use App\Models\SupportLibraryVersion;
use App\Models\User;
use App\Support\SupportLibraryException;
use App\Support\SupportLibraryValidator;
use Illuminate\Support\Facades\DB;

class SaveSupportLibrary
{
    public function __construct(private SupportLibraryValidator $validator) {}

    public function current(?int $expectedRevision = null): SupportLibraryVersion
    {
        $library = SupportLibrary::where('key', 'default')->first();
        if (! $library?->current_version_id) {
            throw new SupportLibraryException('共享库尚未初始化，请管理员执行支架库初始化。', 'LIBRARY_EMPTY', 409);
        }
        if ($expectedRevision !== null && $expectedRevision !== $library->current_revision) {
            throw $this->conflict($expectedRevision, $library->current_revision);
        }

        return $library->currentVersion;
    }

    public function save(array $payload, int $expectedRevision, string $requestId, ?User $actor, string $action = 'edit'): SupportLibraryVersion
    {
        $inputHash = hash('sha256', json_encode($action === 'edit' ? array_intersect_key($payload, array_flip(['parts', 'frames', 'composition'])) : $payload, JSON_THROW_ON_ERROR));
        $requestKey = ($actor?->id ?? 'system').':'.$requestId;

        return DB::transaction(function () use ($payload, $expectedRevision, $requestKey, $actor, $action, $inputHash): SupportLibraryVersion {
            $library = SupportLibrary::where('key', 'default')->lockForUpdate()->firstOrFail();
            $existing = SupportLibraryVersion::where('library_id', $library->id)->where('request_id', $requestKey)->first();
            if ($existing) {
                if ($existing->input_sha256 !== $inputHash || $existing->action !== $action) {
                    throw new SupportLibraryException('提交标识已用于不同内容，请重新提交。', 'REQUEST_CONFLICT', 409);
                }

                return $existing;
            }
            if ($library->current_revision !== $expectedRevision) {
                throw $this->conflict($expectedRevision, $library->current_revision);
            }
            $old = $library->currentVersion?->payload_json;
            $next = $payload;
            if ($action === 'edit' && $old) {
                $next = $this->withProvenance($next, $old);
            }
            $next = $this->validator->validate($next);
            $revision = $expectedRevision + 1;
            $next['updated_at'] = now()->toIso8601String();
            $next['edit_revision'] = $revision;
            $version = SupportLibraryVersion::create(['library_id' => $library->id, 'revision' => $revision,
                'schema_version' => $next['schema_version'], 'rule_version' => SupportLibraryVersion::RULE_VERSION, 'payload_json' => $next,
                'payload_sha256' => hash('sha256', json_encode($next, JSON_THROW_ON_ERROR)),
                'source_filename' => basename(str_replace('\\', '/', $next['last_excel_import']['filename'] ?? $next['source']['file'] ?? '页面维护')),
                'source_sha256' => $next['last_excel_import']['sha256'] ?? $next['source']['sha256'] ?? null,
                'actor_id' => $actor?->id, 'action' => $action, 'request_id' => $requestKey, 'input_sha256' => $inputHash, 'created_at' => now()]);
            $changed = SupportLibrary::whereKey($library->id)->where('current_revision', $expectedRevision)
                ->update(['current_revision' => $revision, 'current_version_id' => $version->id, 'updated_at' => now()]);
            if ($changed !== 1) {
                throw $this->conflict($expectedRevision, SupportLibrary::findOrFail($library->id)->current_revision);
            }

            return $version;
        });
    }

    public function initialize(): SupportLibraryVersion
    {
        return DB::transaction(function (): SupportLibraryVersion {
            $library = SupportLibrary::firstOrCreate(['key' => 'default']);
            if ($library->current_revision > 0) {
                throw new SupportLibraryException('共享库已经存在，初始化不会覆盖已有维护。', 'LIBRARY_ALREADY_INITIALIZED', 409);
            }
            $bytes = file_get_contents(resource_path('support-library/baseline.json'));
            if (hash('sha256', $bytes) !== '8fbb651dd2cef4dfeeb62f514dd88bdd4ecc06e96361e2534049fb67c9f6ba61') {
                throw new SupportLibraryException('初始库校验和不一致，初始化已停止。');
            }

            return $this->save(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR), 0, 'baseline-v1', null, 'initialize');
        });
    }

    private function withProvenance(array $next, array $old): array
    {
        foreach (['parts', 'frames', 'composition'] as $section) {
            foreach ($next[$section] as &$record) {
                if (! is_array($record)) {
                    throw new SupportLibraryException('编辑记录必须为对象。', context: ['fieldPath' => $section]);
                }
                $previous = array_find($old[$section], fn (array $row) => $section === 'composition'
                    ? $row['frame_id'] === ($record['frame_id'] ?? null) && $row['part_id'] === ($record['part_id'] ?? null)
                    : $row['id'] === ($record['id'] ?? null));
                $fields = match ($section) {
                    'parts' => ['id', 'kind', 'spec', 'length_mm', 'unit_weight_kg', 'bolts_per_piece_raw', 'hole', 'note'],
                    'frames' => ['id', 'support_use', 'diameter_mm', 'height_mm', 'spacing_mm'],
                    'composition' => ['frame_id', 'part_id', 'per_frame'],
                };
                $record = [...($previous ?? ['note' => '']), ...array_intersect_key($record, array_flip($fields))];
                if ($section !== 'composition') {
                    $keyMethod = $section === 'parts' ? 'partKey' : 'frameKey';
                    if (count(array_intersect($fields, array_keys($record))) === count($fields)) {
                        $sameKey = array_find($old[$section], fn ($row) => $this->validator->$keyMethod($row) === $this->validator->$keyMethod($record));
                        if ($sameKey && $sameKey['id'] !== $record['id']) {
                            throw new SupportLibraryException('已有构件或搭配的追踪编号不可修改。');
                        }
                    }
                }
                if ($previous !== $record) {
                    $record['original_source'] = $previous['original_source'] ?? $previous['source'] ?? null;
                    $record['source'] = '页面编辑 '.now()->toIso8601String();
                }
            }
            unset($record);
        }

        return $next;
    }

    private function conflict(int $expected, int $actual): SupportLibraryException
    {
        return new SupportLibraryException('共享库已被他人更新，请加载最新版本后重新编辑或检索。当前草稿已保留。', 'REVISION_CONFLICT', 409,
            ['expectedRevision' => $expected, 'actualRevision' => $actual]);
    }
}
