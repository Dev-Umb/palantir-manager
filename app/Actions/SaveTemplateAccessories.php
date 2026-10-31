<?php

namespace App\Actions;

use App\Support\TemplateAccessoryData;
use Illuminate\Support\Facades\DB;

class SaveTemplateAccessories
{
    public function __construct(private TemplateAccessoryData $data) {}

    public function current(): array
    {
        return json_decode(DB::table('template_accessory_workspaces')->where('id', 1)->sole()->dataset, true, flags: JSON_THROW_ON_ERROR);
    }

    public function save(array $input, int $userId): array
    {
        $inputHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($input, $userId, $inputHash): array {
            $workspace = DB::table('template_accessory_workspaces')->where('id', 1)->lockForUpdate()->sole();
            $dataset = json_decode($workspace->dataset, true, flags: JSON_THROW_ON_ERROR);
            $receipt = DB::table('template_accessory_requests')->where('user_id', $userId)->where('request_id', $input['request_id'])->first();
            if ($receipt) {
                abort_unless($receipt->input_hash === $inputHash, 409, '提交标识已用于不同内容。');

                return ['dataset' => $dataset, 'result' => json_decode($receipt->result, true)];
            }
            abort_unless((int) $workspace->revision === $input['expected_revision'], 409, '台账已变化，请重新打开记录或重新预览后提交。当前输入已保留。');
            $snapshot = $workspace->before_restore;
            $result = null;
            switch ($input['operation']) {
                case 'save':
                    $ids = [];
                    foreach ($input['entries'] as $entry) {
                        $record = $this->data->record($entry['record']);
                        abort_if(isset($ids[$record['id']]), 422, '同一项目有两份修改。');
                        $ids[$record['id']] = true;
                        $this->saveRecord($dataset, $record, $entry['version'] ?? null);
                    }
                    break;
                case 'delete':
                    $index = array_find_key($dataset['progressRecords'], fn (array $row): bool => $row['id'] === $input['id']);
                    abort_unless($index !== null && $dataset['progressRecords'][$index]['version'] === ($input['version'] ?? null), 409, '记录已变化，请刷新后重试。');
                    $dataset['progressRecords'][$index]['deleted'] = $input['deleted'];
                    $dataset['progressRecords'][$index]['version']++;
                    break;
                case 'import':
                    $result = $this->import($dataset, $input);
                    break;
                case 'restore':
                    abort_unless(hash_equals(hash('sha256', $input['dataset_json']), $input['checksum']), 422, '备份完整性校验失败，数据未改变。');
                    $restored = json_decode($input['dataset_json'], true, flags: JSON_THROW_ON_ERROR);
                    abort_unless(is_array($restored), 422, '工程结构无效。');
                    $dataset = $this->data->dataset($restored);
                    $snapshot = $workspace->dataset;
                    break;
                case 'undo':
                    abort_unless($snapshot, 409, '没有可撤销的恢复。');
                    $dataset = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
                    $snapshot = null;
                    break;
            }
            $dataset['revision'] = $workspace->revision + 1;
            $dataset['updatedAt'] = now()->toIso8601String();
            $dataset = $this->data->dataset($dataset);
            $changed = DB::table('template_accessory_workspaces')->where('id', 1)->where('revision', $workspace->revision)->update([
                'dataset' => json_encode($dataset, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                'revision' => $dataset['revision'], 'before_restore' => $snapshot,
            ]);
            abort_unless($changed === 1, 409, '台账已变化，请重新读取。');
            DB::table('template_accessory_requests')->insert(['user_id' => $userId, 'request_id' => $input['request_id'], 'input_hash' => $inputHash, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return ['dataset' => $dataset, 'result' => $result];
        });
    }

    private function nextSequence(array $dataset): int
    {
        return $dataset['progressRecords'] ? max(array_column($dataset['progressRecords'], 'seq')) + 1 : 1;
    }

    private function saveRecord(array &$dataset, array $record, ?int $version): void
    {
        $index = array_find_key($dataset['progressRecords'], fn (array $row): bool => $row['id'] === $record['id']);
        if ($index === null) {
            abort_if($version !== null, 409, '原记录不存在。');
            $record['seq'] = $this->nextSequence($dataset);
            $record['version'] = 1;
            $record['deleted'] = false;
            $dataset['progressRecords'][] = $record;
        } else {
            $old = $dataset['progressRecords'][$index];
            abort_unless(! $old['deleted'] && $old['version'] === $version && $old['kind'] === $record['kind'], 409, '记录已变化，请重新打开。');
            $dataset['progressRecords'][$index] = [...$record, 'seq' => $old['seq'], 'sourceRow' => $old['sourceRow'], 'deleted' => false, 'version' => $old['version'] + 1];
        }
    }

    private function import(array &$dataset, array $input): array
    {
        $rows = array_map($this->data->record(...), $input['rows']);
        abort_if(array_any($rows, fn (array $row): bool => $row['kind'] !== $input['kind']), 422, '导入分类不符。');
        $plan = ['sameFile' => false, 'add' => [], 'update' => [], 'same' => 0, 'ambiguous' => []];
        if (array_any($dataset['progressImports'], fn (array $item): bool => $item['hash'] === $input['hash'] && $item['kind'] === $input['kind'])) {
            return [...$plan, 'sameFile' => true, 'same' => count($rows)];
        }
        $used = [];
        foreach ($rows as $row) {
            $matches = array_values(array_filter($dataset['progressRecords'], fn (array $old): bool => ! $old['deleted'] && ! isset($used[$old['id']]) && $this->data->key($old) === $this->data->key($row)));
            $old = array_find($matches, fn (array $old): bool => $this->data->content($old) === $this->data->content($row));
            if ($old) {
                $used[$old['id']] = true;
                $plan['same']++;

                continue;
            }
            if (count($matches) === 1) {
                $old = $matches[0];
            } elseif (count($matches) > 1) {
                $exact = array_values(array_filter($matches, fn (array $old): bool => $this->data->signature($old) === $this->data->signature($row)));
                abort_unless(count($exact) === 1, 422, '同名项目无法唯一对应，本次未导入。');
                $old = $exact[0];
            }
            if ($old) {
                $used[$old['id']] = true;
                $plan['update'][] = ['old' => $old, 'record' => $row];
            } else {
                $plan['add'][] = $row;
            }
        }
        foreach ($plan['add'] as $index => $row) {
            $this->saveRecord($dataset, [...$row, 'id' => 'import-'.hash('sha256', $input['kind'].$input['hash']).'-'.$index, 'sourceRow' => null], null);
        }
        if ($input['replace']) {
            foreach ($plan['update'] as $change) {
                $this->saveRecord($dataset, [...$change['record'], 'id' => $change['old']['id']], $change['old']['version']);
            }
        }
        if ($input['replace'] || ! count($plan['update'])) {
            $dataset['progressImports'][] = ['hash' => $input['hash'], 'name' => $input['name'], 'kind' => $input['kind'], 'at' => now()->toIso8601String()];
        }

        return $plan;
    }
}
