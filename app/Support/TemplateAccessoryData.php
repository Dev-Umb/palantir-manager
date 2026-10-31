<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class TemplateAccessoryData
{
    public const KINDS = ['台车模板', '开挖台架', '液压配件'];

    public const STAGES = ['未出图', '审核中', '未下放', '已下放', '生产中', '已做完'];

    public const SHIPPING = ['未发', '部分已发', '已发', '待确认'];

    public function fields(string $kind): array
    {
        $tail = ['progress', 'shipping', 'orderDate', 'shipDate', 'note'];

        return match ($kind) {
            '台车模板' => ['project', 'subproject', 'type', 'estimated', 'shipped', 'intelligent', 'quantity', ...$tail],
            '开挖台架' => ['project', 'subproject', 'type', 'estimated', 'shipped', 'quantity', ...$tail],
            '液压配件' => ['project', 'type', 'hydraulic', 'screws', 'pins', 'jacks', 'shipped', ...$tail, 'parent'],
            default => $this->fail('项目分类无效。'),
        };
    }

    public function fail(string $message): never
    {
        throw ValidationException::withMessages(['record' => $message]);
    }

    public function record(array $row): array
    {
        $kind = $row['kind'] ?? '';
        if (! in_array($kind, self::KINDS, true) || ! is_string($row['id'] ?? null) || $row['id'] === '' || mb_strlen($row['id']) > 150) {
            $this->fail('项目分类或编号无效。');
        }
        foreach (['version', 'seq'] as $key) {
            if (! is_int($row[$key] ?? null) || $row[$key] < 1) {
                $this->fail('项目版本或序号无效。');
            }
        }
        $clean = array_intersect_key($row, array_flip(['id', 'kind', 'version', 'seq']));
        foreach ($this->fields($kind) as $key) {
            $value = $row[$key] ?? null;
            if (in_array($key, ['estimated', 'shipped', 'intelligent', 'quantity', 'hydraulic', 'screws', 'pins', 'jacks'], true)) {
                if ($value !== null && ((! is_int($value) && ! is_float($value)) || ! is_finite($value) || $value < 0 || $value > 1e9)) {
                    $this->fail($key.' 必须为非负数或空值。');
                }
            } elseif ($value === null) {
                $value = '';
            } elseif (! is_string($value) || mb_strlen($value) > 5000) {
                $this->fail($key.' 格式无效。');
            }
            if (in_array($key, ['shipDate', 'orderDate'], true) && $value !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (! $date || $date->format('Y-m-d') !== $value || $value < '1900-01-01' || $value > '2200-12-31') {
                    $this->fail('日期无效。');
                }
            }
            $clean[$key] = is_int($value) ? (float) $value : $value;
        }
        if (trim($clean['project']) === '' || trim($clean['type']) === '' || ! in_array($clean['progress'], self::STAGES, true) || ! in_array($clean['shipping'], self::SHIPPING, true)) {
            $this->fail('请填写项目、类型及有效状态。');
        }
        if ($clean['shipping'] === '已发' && $clean['progress'] !== '已做完') {
            $this->fail('已发项目的模板进度应为已做完。');
        }
        if (isset($row['deleted']) && ! is_bool($row['deleted'])) {
            $this->fail('删除状态无效。');
        }
        $clean['deleted'] = $row['deleted'] ?? false;
        $clean['sourceRow'] = is_int($row['sourceRow'] ?? null) && $row['sourceRow'] > 0 ? $row['sourceRow'] : null;

        return $clean;
    }

    public function dataset(array $data): array
    {
        foreach (['progressRecords' => 50000, 'progressImports' => 5000] as $key => $max) {
            if (! is_array($data[$key] ?? null) || ! array_is_list($data[$key]) || count($data[$key]) > $max) {
                $this->fail('工程结构无效。');
            }
        }
        $records = [];
        foreach ($data['progressRecords'] as $row) {
            if (! is_array($row)) {
                $this->fail('项目无效。');
            }
            $record = $this->record($row);
            if (isset($records[$record['id']])) {
                $this->fail('项目编号重复。');
            }
            $records[$record['id']] = $record;
        }
        $imports = [];
        foreach ($data['progressImports'] as $item) {
            if (! is_array($item) || ! is_string($item['hash'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $item['hash']) || ! is_string($item['name'] ?? null) || mb_strlen($item['name']) > 500 || ! in_array($item['kind'] ?? null, self::KINDS, true)) {
                $this->fail('导入历史无效。');
            }
            $imports[] = array_intersect_key($item, array_flip(['hash', 'name', 'kind', 'at']));
        }

        return ['progressRecords' => array_values($records), 'progressImports' => $imports, 'revision' => $data['revision'] ?? 0, 'updatedAt' => $data['updatedAt'] ?? null];
    }

    public function key(array $row): array
    {
        return array_map(fn (string $key): string => trim($row[$key] ?? ''), ['kind', 'project', 'subproject', 'type']);
    }

    public function content(array $row): array
    {
        return array_map(fn (string $key): mixed => is_numeric($row[$key] ?? null) && ! is_string($row[$key]) ? (float) $row[$key] : ($row[$key] ?? null), $this->fields($row['kind']));
    }

    public function signature(array $row): array
    {
        return array_map(fn (string $key): mixed => isset($row[$key]) ? (float) $row[$key] : null, ['quantity', 'hydraulic', 'screws', 'pins', 'jacks']);
    }
}
