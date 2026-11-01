<?php

namespace App\Actions;

use App\Models\User;
use App\Support\ProductionWorkspaceData;
use Illuminate\Support\Facades\DB;

class SaveProductionWorkspace
{
    private const TABLES = ['material_arrival_workspaces', 'template_accessory_workspaces', 'production_workspaces'];

    private function rows(bool $lock = false): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $q = DB::table($table)->where('id', 1);
            $rows[] = $lock ? $q->lockForUpdate()->sole() : $q->sole();
        }

        return $rows;
    }

    private function combine(array $rows): array
    {
        $d = [];
        foreach ($rows as $row) {
            $d = array_replace($d, json_decode($row->dataset, true, flags: JSON_THROW_ON_ERROR));
        }
        $d['revision'] = array_sum(array_column($rows, 'revision'));

        return $d;
    }

    public function current(): array
    {
        return DB::transaction(fn (): array => $this->combine($this->rows(true)));
    }

    public function snapshot(): array
    {
        return DB::transaction(function (): array {
            $rows = $this->rows(true);
            $snapshot = $rows[2]->upgrade_snapshot;
            if ($snapshot) {
                return json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
            }$d = $this->combine($rows);
            DB::table('production_workspaces')->where('id', 1)->update(['upgrade_snapshot' => json_encode($d, JSON_THROW_ON_ERROR)]);

            return $d;
        });
    }

    public function save(array $input, User $user): array
    {
        return DB::transaction(function () use ($input, $user): array {
            $rows = $this->rows(true);
            $before = $this->combine($rows);
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $receipt = DB::table('production_workspace_requests')->where('user_id', $user->id)->where('request_id', $input['request_id'])->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->input_hash, $hash), 409, '提交标识重复。');

                return $before;
            }
            abort_unless($before['revision'] === $input['expected_revision'], 409, '工程已变化，当前输入已保留，请重新打开后提交。');
            $operation = $input['operation'];
            $validator = app(ProductionWorkspaceData::class);
            if ($operation !== 'save') {
                foreach (['material-arrivals', 'template-accessories'] as $module) {
                    abort_unless($user->canDo($module.'.recover'), 403);
                }
            }
            if ($operation === 'undo') {
                abort_unless($rows[2]->before_restore && $rows[2]->restore_revision === $before['revision'], 409, '没有可撤销恢复，或恢复后已有修改；请先备份并核对。');
                $after = json_decode($rows[2]->before_restore, true, flags: JSON_THROW_ON_ERROR);
            } elseif ($operation === 'restore') {
                abort_unless(hash_equals(hash('sha256', $input['dataset_json']), $input['checksum']), 422, '备份摘要校验失败。');
                $after = json_decode($input['dataset_json'], true, flags: JSON_THROW_ON_ERROR);
                if (! array_key_exists('materialDemandPlans', $after)) {
                    abort_unless($rows[2]->reference_plans, 422, '旧完整备份缺少需求模块，请先初始化已核对参考计划。');
                    $after['materialDemandPlans'] = json_decode($rows[2]->reference_plans, true, flags: JSON_THROW_ON_ERROR);
                }
            } else {
                $after = json_decode($input['dataset_json'], true, flags: JSON_THROW_ON_ERROR);
            }
            abort_unless(is_array($after), 422, '工程格式无效。');
            if ($operation === 'save') {
                foreach (['records', 'progressRecords', 'materialDemandPlans'] as $key) {
                    $existing = array_column($before[$key], null, 'id');
                    foreach ($after[$key] ?? [] as $index => $record) {
                        if (is_array($record) && isset($existing[$record['id'] ?? ''])) {
                            $after[$key][$index] = array_replace($existing[$record['id']], $record);
                        }
                    }
                }
            }
            $validator->validate($after);
            $groups = [['records', 'imports'], ['progressRecords', 'progressImports'], ['materialDemandPlans']];
            foreach ($groups as $index => $keys) {
                $module = $index === 1 ? 'template-accessories' : 'material-arrivals';
                $changed = false;
                foreach ($keys as $key) {
                    if (($before[$key] ?? null) != ($after[$key] ?? null)) {
                        $changed = true;
                    }
                }
                if ($operation === 'save' && $changed) {
                    abort_unless($user->canDo($module.'.manage'), 403);
                    $validator->checkVersions($before[$keys[0]], $after[$keys[0]]);
                }
            }
            $extras = array_diff_key($after, array_flip(['records', 'imports', 'progressRecords', 'progressImports', 'materialDemandPlans', 'revision', 'updatedAt']));
            $oldExtras = array_diff_key($before, array_flip(['records', 'imports', 'progressRecords', 'progressImports', 'materialDemandPlans', 'revision', 'updatedAt']));
            if ($operation === 'save') {
                abort_unless($extras == $oldExtras, 422, '普通编辑不得修改工程扩展字段。');
            }
            $after['revision'] = $before['revision'] + 1;
            $after['updatedAt'] = now()->toIso8601String();
            foreach ($groups as $index => $keys) {
                $part = json_decode($rows[$index]->dataset, true, flags: JSON_THROW_ON_ERROR);
                foreach ($keys as $key) {
                    $part[$key] = $after[$key];
                }
                if ($index === 2) {
                    $part = [...$extras, ...array_intersect_key($part, array_flip(['materialDemandPlans']))];
                }
                $part['revision'] = (int) $rows[$index]->revision + ($index === 2 ? 1 : 0);
                $part['updatedAt'] = $after['updatedAt'];
                // Increment changed legacy domains too, so their existing clients reject stale writes.
                if ($index < 2 && array_any($keys, fn (string $key): bool => $before[$key] != $after[$key])) {
                    $part['revision']++;
                    $after['revision']++;
                }
                $update = ['dataset' => json_encode($part, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 'revision' => $part['revision']];
                if ($index === 2) {
                    if ($operation === 'restore') {
                        $update['before_restore'] = json_encode($before, JSON_THROW_ON_ERROR);
                        $update['restore_revision'] = $after['revision'];
                    } elseif ($operation === 'undo') {
                        $update['before_restore'] = null;
                        $update['restore_revision'] = null;
                    }
                }
                DB::table(self::TABLES[$index])->where('id', 1)->update($update);
            }
            DB::table('production_workspace_requests')->insert(['user_id' => $user->id, 'request_id' => $input['request_id'], 'input_hash' => $hash]);

            return $this->combine($this->rows());
        });
    }
}
