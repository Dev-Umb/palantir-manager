<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MaterialArrivalData
{
    public function record(array $record): array
    {
        $record['note'] ??= '';
        $record['source'] ??= '';
        Validator::make($record, [
            'id' => ['required', 'string', 'max:150'],
            'seq' => ['required', 'integer:strict', 'min:1'],
            'version' => ['required', 'integer:strict', 'min:1'],
            'shipDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2200-12-31'],
            'arrivalDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:shipDate', 'before_or_equal:2200-12-31'],
            'supplier' => ['required', 'string', 'max:200'], 'plate' => ['required', 'string', 'max:50'],
            'note' => ['present', 'string', 'max:500'], 'source' => ['present', 'string', 'max:1000'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'items.*' => ['required', 'array'],
            'items.*.material' => ['required', 'string', 'max:100'],
            'items.*.spec' => ['required', 'string', 'max:200'],
            'items.*.majorClass' => ['nullable', 'in:型材,板材'],
        ])->validate();
        foreach (['dispatch', 'gross', 'tare'] as $key) {
            $value = $record[$key] ?? null;
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0 || $value > 1000000) {
                $this->fail('请填写有效的非负重量。');
            }
        }
        if ($record['gross'] < $record['tare']) {
            $this->fail('收货毛重不能小于收货皮重。');
        }
        if (isset($record['deleted']) && ! is_bool($record['deleted'])) {
            $this->fail('删除状态无效。');
        }
        foreach ($record['items'] as &$item) {
            $known = $this->knownClass($item['material']);
            if ($known && ! empty($item['majorClass']) && $item['majorClass'] !== $known) {
                $this->fail('材料大类与已确认的分类规则不一致。');
            }
            $item = array_intersect_key($item, array_flip(['material', 'spec', 'majorClass']));
        }
        unset($item);

        return array_intersect_key($record, array_flip(['id', 'seq', 'version', 'shipDate', 'arrivalDate', 'supplier', 'plate', 'note', 'source', 'items', 'dispatch', 'gross', 'tare', 'deleted']));
    }

    public function dataset(array $dataset): array
    {
        Validator::make($dataset, [
            'records' => ['present', 'array', 'list', 'max:50000'], 'records.*' => ['array'],
            'imports' => ['present', 'array', 'list', 'max:5000'], 'imports.*' => ['array'],
            'imports.*.hash' => ['required', 'string', 'max:128'],
            'imports.*.name' => ['required', 'string', 'max:500'],
            'imports.*.at' => ['required', 'string', 'max:100'],
            'revision' => ['required', 'integer:strict', 'min:0'],
        ])->validate();
        $ids = [];
        $keys = [];
        foreach ($dataset['records'] as &$record) {
            $record = $this->record($record);
            if (isset($ids[$record['id']])) {
                $this->fail('工程中有重复记录编号。');
            }
            $ids[$record['id']] = true;
            if (! ($record['deleted'] ?? false)) {
                $key = $this->key($record);
                if (isset($keys[$key])) {
                    $this->fail('同日同车已有记录，请先核对台账。');
                }
                $keys[$key] = true;
            }
        }
        unset($record);

        return ['records' => array_values($dataset['records']), 'imports' => array_values($dataset['imports']), 'revision' => $dataset['revision'], 'updatedAt' => $dataset['updatedAt'] ?? null];
    }

    public function key(array $record): string
    {
        return $record['arrivalDate'].'|'.mb_strtoupper(preg_replace('/\s/u', '', $record['plate']));
    }

    public function content(array $record): array
    {
        return [$record['shipDate'], $this->key($record), trim($record['supplier']), $record['dispatch'], $record['gross'], $record['tare'], array_map(fn (array $item): array => [$item['material'], $item['spec'], $this->knownClass($item['material']) ?: (($item['majorClass'] ?? '') ?: '待分类')], $record['items']), $record['note'] ?? ''];
    }

    public function knownClass(string $material): string
    {
        return match (trim($material)) {
            '槽钢', '工字钢' => '型材',
            '中板', '开平板' => '板材',
            default => '',
        };
    }

    public function fail(string $message): never
    {
        throw ValidationException::withMessages(['data' => $message]);
    }
}
