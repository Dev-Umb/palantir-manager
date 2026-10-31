<?php

namespace App\Actions;

use App\Support\MaterialArrivalData;
use Illuminate\Support\Facades\DB;

class SaveMaterialArrivals
{
    public function __construct(private MaterialArrivalData $data) {}

    public function current(): array
    {
        return json_decode(DB::table('material_arrival_workspaces')->where('id', 1)->sole()->dataset, true, flags: JSON_THROW_ON_ERROR);
    }

    public function save(array $input, int $userId): array
    {
        $inputHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($input, $userId, $inputHash): array {
            $workspace = DB::table('material_arrival_workspaces')->where('id', 1)->lockForUpdate()->sole();
            $dataset = json_decode($workspace->dataset, true, flags: JSON_THROW_ON_ERROR);
            $receipt = DB::table('material_arrival_requests')->where('user_id', $userId)->where('request_id', $input['request_id'])->first();
            if ($receipt) {
                abort_unless($receipt->input_hash === $inputHash, 409, '提交标识已用于不同内容。');

                return ['dataset' => $dataset, 'result' => json_decode($receipt->result, true)];
            }
            abort_unless((int) $workspace->revision === $input['expected_revision'], 409, '台账已变化，请重新打开记录或重新预览后提交。当前输入已保留。');
            $snapshot = $workspace->before_restore;
            $result = null;
            switch ($input['operation']) {
                case 'save':
                    $record = $this->data->record($input['record']);
                    $index = array_find_key($dataset['records'], fn (array $row): bool => $row['id'] === $record['id']);
                    if ($index !== null) {
                        $old = $dataset['records'][$index];
                        abort_unless(! ($old['deleted'] ?? false) && $old['version'] === ($input['version'] ?? null), 409, '记录已修改或删除，请重新打开后再编辑。');
                        $record['seq'] = $old['seq'];
                        $record['version'] = $old['version'] + 1;
                        $record['source'] = $old['source'] ?? '';
                        $record['deleted'] = false;
                        $dataset['records'][$index] = $record;
                    } else {
                        abort_if(! empty($input['version']), 409, '原记录不存在。');
                        $record['seq'] = $this->nextSequence($dataset);
                        $record['version'] = 1;
                        $record['deleted'] = false;
                        $dataset['records'][] = $record;
                    }
                    foreach ($record['items'] as $item) {
                        if (! $this->data->knownClass($item['material']) && empty($item['majorClass'])) {
                            $this->data->fail('请为每一项材料选择型材或板材大类。');
                        }
                    }
                    break;
                case 'delete':
                    $index = array_find_key($dataset['records'], fn (array $row): bool => $row['id'] === $input['id']);
                    abort_unless($index !== null && $dataset['records'][$index]['version'] === ($input['version'] ?? null), 409, '记录已变化，请刷新后重试。');
                    $dataset['records'][$index]['deleted'] = $input['deleted'];
                    $dataset['records'][$index]['version']++;
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
            $changed = DB::table('material_arrival_workspaces')->where('id', 1)->where('revision', $workspace->revision)->update([
                'dataset' => json_encode($dataset, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                'revision' => $dataset['revision'], 'before_restore' => $snapshot,
            ]);
            abort_unless($changed === 1, 409, '台账已变化，请重新读取。');
            DB::table('material_arrival_requests')->insert(['user_id' => $userId, 'request_id' => $input['request_id'], 'input_hash' => $inputHash, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return ['dataset' => $dataset, 'result' => $result];
        });
    }

    private function nextSequence(array $dataset): int
    {
        return $dataset['records'] ? max(array_column($dataset['records'], 'seq')) + 1 : 1;
    }

    private function import(array &$dataset, array $input): array
    {
        $rows = array_map($this->data->record(...), $input['rows']);
        if (array_any($dataset['imports'], fn (array $row): bool => $row['hash'] === $input['hash'])) {
            return ['sameFile' => true, 'add' => [], 'duplicates' => count($rows), 'conflicts' => []];
        }
        $result = ['sameFile' => false, 'add' => [], 'duplicates' => 0, 'conflicts' => []];
        $existing = [];
        foreach ($dataset['records'] as $record) {
            if (! ($record['deleted'] ?? false)) {
                $existing[$this->data->key($record)] = $record;
            }
        }
        $sequence = $this->nextSequence($dataset);
        foreach ($rows as $row) {
            $key = $this->data->key($row);
            if (isset($existing[$key])) {
                if ($this->data->content($existing[$key]) == $this->data->content($row)) {
                    $result['duplicates']++;

                    continue;
                }
                $this->data->fail('存在同日同车的不同记录，请核对后再导入。');
            }
            $row['seq'] = $sequence++;
            $row['id'] = 'import-'.substr($input['hash'], 0, 24).'-'.count($result['add']);
            $row['version'] = 1;
            $row['deleted'] = false;
            $existing[$key] = $row;
            $dataset['records'][] = $row;
            $result['add'][] = $row;
        }
        $dataset['imports'][] = ['hash' => $input['hash'], 'name' => $input['name'], 'at' => now()->toIso8601String()];

        return $result;
    }
}
