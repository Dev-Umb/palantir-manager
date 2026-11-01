<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;

class ProductionWorkspaceData
{
    public function validate(array $dataset): array
    {
        app(MaterialArrivalData::class)->dataset($dataset);
        app(TemplateAccessoryData::class)->dataset($dataset);
        Validator::make($dataset, [
            'materialDemandPlans' => ['present', 'array', 'list', 'max:10000'],
            'materialDemandPlans.*' => ['array'],
            'materialDemandPlans.*.id' => ['required', 'string', 'max:150', 'distinct:strict'],
            'materialDemandPlans.*.version' => ['required', 'integer:strict', 'min:1'],
            'materialDemandPlans.*.name' => ['required', 'string', 'max:150'],
            'materialDemandPlans.*.type' => ['required', 'in:月度计划,项目计划,其他计划'],
            'materialDemandPlans.*.orderDate' => ['nullable', 'date_format:Y-m-d'],
            'materialDemandPlans.*.needDate' => ['nullable', 'date_format:Y-m-d'],
            'materialDemandPlans.*.records' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'materialDemandPlans.*.records.*' => ['array'],
            'materialDemandPlans.*.records.*.id' => ['required', 'string', 'max:150'],
            'materialDemandPlans.*.records.*.material' => ['required', 'string', 'max:500'],
            'materialDemandPlans.*.records.*.unit' => ['required', 'in:吨,根,张,米,件'],
            'materialDemandPlans.*.records.*.quantity' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'materialDemandPlans.*.records.*.needDate' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();
        foreach ($dataset['materialDemandPlans'] as $plan) {
            $ids = [];
            foreach ($plan['records'] as $row) {
                abort_unless(! isset($ids[$row['id']]), 422, '需求明细编号重复。');
                $ids[$row['id']] = true;
                abort_unless(is_int($row['quantity']) || is_float($row['quantity']), 422, '数量必须为数字。');
                abort_unless(trim($row['spec'] ?? '') !== '' || trim($row['size'] ?? '') !== '', 422, '请填写规格或尺寸。');
                foreach (['spec', 'grade', 'size', 'standard', 'note'] as $key) {
                    abort_unless(! isset($row[$key]) || (is_string($row[$key]) && mb_strlen($row[$key]) <= 500), 422, '需求文本无效。');
                }
                if (isset($row['conflict'])) {
                    Validator::make($row, ['conflict' => ['array'], 'conflict.field' => ['required', 'in:spec'], 'conflict.excelValue' => ['required', 'string', 'max:500'], 'conflict.pdfValue' => ['required', 'string', 'max:500'], 'conflict.resolution' => ['nullable', 'in:excel,pdf,manual']])->validate();
                }
            }
        }

        return $dataset;
    }

    public function checkVersions(array $before, array $after): void
    {
        $old = array_column($before, null, 'id');
        $new = array_column($after, null, 'id');
        abort_unless(count($new) === count($after), 422, '记录编号重复。');
        foreach ($old as $id => $row) {
            abort_unless(isset($new[$id]), 422, '请使用回收站，不可直接移除记录。');
            if ($new[$id] != $row) {
                abort_unless($new[$id]['version'] === $row['version'] + 1, 409, '记录版本已变化，请重新打开。');
            }
        }
        foreach ($new as $id => $row) {
            if (! isset($old[$id])) {
                abort_unless($row['version'] === 1, 422, '新记录版本无效。');
            }
        }
    }
}
